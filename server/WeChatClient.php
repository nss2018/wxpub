<?php
/**
 * 微信公众号 API 客户端
 *
 * 覆盖：access_token 缓存 / 封面上传 / 正文图片上传 / 草稿箱新增
 *
 * 三个关键约束（踩过的坑）：
 *  1. access_token 有效期 2 小时，必须服务端缓存，不能每次重新获取（有频率限制）
 *  2. 正文图片 URL 不能用外部域名，微信会过滤。必须走 media/uploadimg 换成 mmbiz 地址
 *  3. content 必须少于 2 万字符，且封面 thumb_media_id 是必填（草稿接口不接受空）
 */

class WeChatClient
{
    private string $appId;
    private string $appSecret;
    private string $cacheDir;
    private string $cacheKey;
    private ?string $token = null;

    private const API_BASE = 'https://api.weixin.qq.com/cgi-bin';

    public function __construct(string $appId, string $appSecret, string $cacheDir, string $cacheKey = 'default')
    {
        $this->appId     = $appId;
        $this->appSecret = $appSecret;
        $this->cacheDir  = $cacheDir;
        $this->cacheKey  = $cacheKey;
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0700, true);
        }
    }

    /**
     * 获取 access_token，带文件缓存（提前 5 分钟过期，避免边界失效）
     */
    public function getAccessToken(bool $forceRefresh = false): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $file = $this->cacheDir . '/access_token.' . $this->cacheKey . '.json';
        if (!$forceRefresh && is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)
                && !empty($cached['token'])
                && (int) ($cached['expires_at'] ?? 0) > time() + 300) {
                $this->token = (string) $cached['token'];
                return $this->token;
            }
        }

        $url = self::API_BASE . '/token?grant_type=client_credential'
             . '&appid=' . urlencode($this->appId)
             . '&secret=' . urlencode($this->appSecret);

        $raw  = $this->httpGet($url);
        $json = json_decode($raw, true);

        if (!is_array($json) || empty($json['access_token'])) {
            $err = $json['errmsg'] ?? substr($raw, 0, 200);
            $code = $json['errcode'] ?? '?';
            throw new RuntimeException("获取 access_token 失败 [{$code}]：{$err}");
        }

        $this->token = (string) $json['access_token'];
        @file_put_contents($file, json_encode([
            'token'      => $this->token,
            'expires_at' => time() + (int) $json['expires_in'],
        ], JSON_UNESCAPED_UNICODE));

        return $this->token;
    }

    /**
     * 上传永久素材（封面图用）
     *
     * @return string media_id
     */
    public function uploadPermanentImage(string $localPath): string
    {
        $json = $this->postFile(self::API_BASE . '/material/add_material?type=image', $localPath);
        if (!is_array($json) || empty($json['media_id'])) {
            $err  = $json['errmsg'] ?? '未知错误';
            $code = $json['errcode'] ?? '?';
            throw new RuntimeException("上传封面失败 [{$code}]：{$err}");
        }
        return (string) $json['media_id'];
    }

    /**
     * 上传图文消息内的图片，返回微信自己的 URL
     *
     * 这是最容易踩的坑：正文里的 <img src> 如果是外部域名，微信会直接过滤掉图片。
     * 必须先调这个接口换成 mmbiz.qpic.cn 的地址。
     */
    public function uploadContentImage(string $localPath): string
    {
        $json = $this->postFile(self::API_BASE . '/media/uploadimg', $localPath);
        if (!is_array($json) || empty($json['url'])) {
            $err  = $json['errmsg'] ?? '未知错误';
            $code = $json['errcode'] ?? '?';
            throw new RuntimeException("上传正文图片失败 [{$code}]：{$err}");
        }
        return (string) $json['url'];
    }

    /**
     * multipart 文件上传 —— 用 CURLFile 让 curl 自动构造 boundary
     *
     * 手写 multipart body 会踩坑：boundary 格式、CURLOPT_POSTFIELDS 传字符串时
     * curl 不会补换行，微信收不到文件数据直接报 41005 media data missing。
     *
     * @return array 解码后的 JSON
     */
    private function postFile(string $endpoint, string $localPath): array
    {
        if (!is_file($localPath)) {
            throw new RuntimeException("文件不存在：{$localPath}");
        }
        // endpoint 可能已带 query（如 ?type=image），这里要按情况选分隔符，
        // 否则拼出 `?type=image?access_token=` 微信解析不到 token，报 41001
        $url = $endpoint
            . (strpos($endpoint, '?') === false ? '?' : '&')
            . 'access_token=' . $this->getAccessToken();

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                // 字段名必须是 media，微信按这个名字取文件
                'media' => new CURLFile($localPath, $this->guessMime($localPath), basename($localPath)),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("上传请求失败：{$err}");
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('微信返回了非 JSON 内容：' . substr((string) $raw, 0, 200));
        }
        return $json;
    }

    /**
     * 新增草稿
     *
     * @param string $contentHtml 已渲染好的正文 HTML（须< 20000 字符）
     * @return array 成功时含 media_id
     */
    public function addDraft(
        string $title,
        string $contentHtml,
        string $thumbMediaId,
        string $author = '',
        string $digest = '',
        string $sourceUrl = ''
    ): array {
        $len = mb_strlen($contentHtml, 'UTF-8');
        if ($len >= 20000) {
            throw new RuntimeException(
                "正文超长：{$len} 字符，微信上限 20000。请精简内容或拆分多篇。"
            );
        }
        if ($title === '') {
            throw new RuntimeException("标题不能为空");
        }
        if (mb_strlen($title, 'UTF-8') > 32) {
            throw new RuntimeException(
                '标题超长：' . mb_strlen($title, 'UTF-8') . ' 字符，微信上限 32'
            );
        }

        $article = [
            'article_type'        => 'news',
            'title'               => $title,
            'content'             => $contentHtml,
            'thumb_media_id'      => $thumbMediaId,
            'need_open_comment'   => 0,
            'only_fans_can_comment' => 0,
        ];
        if ($author !== '') {
            $article['author'] = mb_substr($author, 0, 16, 'UTF-8');
        }
        if ($digest !== '') {
            $article['digest'] = mb_substr($digest, 0, 120, 'UTF-8');
        }
        if ($sourceUrl !== '') {
            $article['content_source_url'] = $sourceUrl;
        }

        $url = self::API_BASE . '/draft/add?access_token=' . $this->getAccessToken();
        // ensure_ascii=false —— 否则中文会被转成 \uXXXX
        $payload = json_encode(['articles' => [$article]], JSON_UNESCAPED_UNICODE);
        $raw = $this->httpPost($url, $payload, [
            'Content-Type: application/json; charset=utf-8',
        ]);

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['media_id'])) {
            $err  = $json['errmsg'] ?? substr($raw, 0, 300);
            $code = $json['errcode'] ?? '?';
            throw new RuntimeException("新增草稿失败 [{$code}]：{$err}");
        }
        return $json;
    }

    /** 查询草稿列表（用于验证） */
    public function listDrafts(int $offset = 0, int $count = 5): array
    {
        $url = self::API_BASE . '/draft/batchget?access_token=' . $this->getAccessToken();
        $raw = $this->httpPost($url, json_encode([
            'offset' => $offset,
            'count'  => $count,
            'no_content' => 1,
        ]), ['Content-Type: application/json; charset=utf-8']);
        return json_decode($raw, true) ?: [];
    }

    private function guessMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = [
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
        ];
        return isset($map[$ext]) ? $map[$ext] : 'image/jpeg';
    }

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException("网络请求失败：{$err}");
        }
        return (string) $raw;
    }

    private function httpPost(string $url, string $body, array $headers): string
    {
        $ch = curl_init($url);
        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException("网络请求失败：{$err}");
        }
        return (string) $raw;
    }
}
