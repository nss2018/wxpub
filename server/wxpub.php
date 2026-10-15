<?php
/**
 * 公众号排版推送工具 — HTTP 入口
 *
 * 设计成无状态 HTTP 接口，用 token 鉴权。命令行调用方式：
 *   php wxpub.php <markdown文件> [主题] [封面图] [作者] [摘要]
 * 或HTTP：
 *   POST /wxpub.php
 *   action=publish & md=<文件路径> & theme=terminal & cover=<封面路径> & k=<token>
 *
 * action 说明：
 *   publish  发布（默认，图文 news）
 *   newspic 发布图片消息（贴图），md 指向 newspic JSON
 *   preview  只渲染，不调微信，输出 HTML 供检查
 *   token    强制刷新 access_token
 *   drafts   查草稿箱
 *   themes   列主题
 *   check    环境自检
 */

require __DIR__ . '/Theme.php';
require __DIR__ . '/Markdown.php';
require __DIR__ . '/Cover.php';
require __DIR__ . '/NewspicSlide.php';
require __DIR__ . '/WeChatClient.php';
require __DIR__ . '/config.php';

// ---------- 鉴权 ----------
$key = (string) ($_GET['k'] ?? $_POST['k'] ?? WXPUB_TOKEN);
if (!hash_equals(WXPUB_TOKEN, $key)) {
    out(['ok' => false, 'error' => '鉴权失败'], 403);
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'publish');

try {
    switch ($action) {
        case 'check':    actionCheck();      break;
        case 'themes':   actionThemes();     break;
        case 'accounts': actionAccounts();   break;
        case 'token':    actionToken();      break;
        case 'drafts':   actionDrafts();     break;
        case 'preview':  actionPublish(false); break;
        case 'publish':  actionPublish(true);  break;
        case 'newspic':  actionNewspic();     break;
        default:         out(['ok' => false, 'error' => "未知 action: {$action}"], 400);
    }
} catch (Throwable $e) {
    out(['ok' => false, 'error' => $e->getMessage()], 500);
}

function actionCheck(): void
{
    $accounts = [];
    foreach (WXPUB_ACCOUNTS as $k => $a) {
        $accounts[$k] = ['name' => $a['name'], 'appid' => $a['appid']];
    }
    $info = [
        'php_version'    => PHP_VERSION,
        'extensions'     => [
            'curl'    => extension_loaded('curl'),
            'json'    => extension_loaded('json'),
            'mbstring'=> extension_loaded('mbstring'),
            'gd'      => extension_loaded('gd'),
        ],
        'accounts'       => $accounts,
        'default_account'=> WXPUB_DEFAULT,
        'cache_dir'      => WXPUB_CACHE,
        'cache_writable' => is_writable(WXPUB_CACHE),
        'themes'         => array_keys(Theme::list()),
    ];
    $info['ok'] = $info['extensions']['curl']
        && $info['extensions']['json']
        && $info['extensions']['mbstring']
        && count($accounts) > 0;
    out($info);
}

function actionAccounts(): void
{
    $list = [];
    foreach (WXPUB_ACCOUNTS as $k => $a) {
        $list[] = ['key' => $k, 'name' => $a['name'], 'appid' => $a['appid']];
    }
    out(['ok' => true, 'default' => WXPUB_DEFAULT, 'accounts' => $list]);
}

function actionThemes(): void
{
    out(['ok' => true, 'themes' => Theme::list()]);
}

function actionToken(): void
{
    $c = client();
    $t = $c->getAccessToken(true);
    out(['ok' => true, 'token_preview' => substr($t, 0, 12) . '...', 'length' => strlen($t)]);
}

function actionDrafts(): void
{
    $c = client();
    $r = $c->listDrafts(0, 5);
    $items = [];
    foreach (($r['item'] ?? []) as $it) {
        foreach (($it['content']['news_item'] ?? []) as $a) {
            $items[] = [
                'title'   => $a['title'] ?? '',
                'digest'  => mb_substr($a['digest'] ?? '', 0, 60, 'UTF-8'),
                'author'  => $a['author'] ?? '',
                'media_id'=> $a['thumb_media_id'] ?? '',
            ];
        }
    }
    out(['ok' => true, 'total_count' => $r['total_count'] ?? 0, 'items' => $items]);
}

function actionPublish(bool $doPublish): void
{
    $mdPath = (string) ($_GET['md'] ?? $_POST['md'] ?? '');
    $theme  = (string) ($_GET['theme'] ?? $_POST['theme'] ?? 'terminal');
    $cover  = (string) ($_GET['cover'] ?? $_POST['cover'] ?? WXPUB_DEFAULT_COVER);
    $poster = ($_GET['poster'] ?? $_POST['poster'] ?? '1') !== '0';  // 默认自动生成封面海报
    $author = (string) ($_GET['author'] ?? $_POST['author'] ?? '');
    $digest = (string) ($_GET['digest'] ?? $_POST['digest'] ?? '');
    $srcUrl = (string) ($_GET['source'] ?? $_POST['source'] ?? '');

    if ($mdPath === '') {
        out(['ok' => false, 'error' => '缺少 md 参数（Markdown 文件路径）'], 400);
    }
    if (!is_file($mdPath)) {
        out(['ok' => false, 'error' => "Markdown 文件不存在：{$mdPath}"], 400);
    }
    $md = file_get_contents($mdPath);
    if ($md === false) {
        out(['ok' => false, 'error' => "无法读取：{$mdPath}"], 500);
    }

    $themeObj = new Theme($theme);
    $mdObj    = new Markdown($themeObj);
    [$html, $plain] = $mdObj->render($md);
    $html .= $themeObj->endBlock();  // 文末双短线 + END

    // 首行 # 标题作为文章标题
    $title = '';
    if (preg_match('/^#\s+(.+)$/m', $md, $m)) {
        $title = trim($m[1]);
    }
    if ($title === '') {
        $title = mb_substr(pathinfo($mdPath, PATHINFO_FILENAME), 0, 32, 'UTF-8');
    }

    $result = [
        'ok'          => true,
        'title'       => $title,
        'theme'       => $theme,
        'html_length' => mb_strlen($html, 'UTF-8'),
        'plain_length'=> mb_strlen($plain, 'UTF-8'),
    ];

    if (mb_strlen($html, 'UTF-8') >= 20000) {
        out(['ok' => false, 'error' => '正文超 20000 字符，请精简',
             'html_length' => mb_strlen($html, 'UTF-8')] + $result, 400);
    }

    if (!$doPublish) {
        $result['html'] = $html;
        $result['plain'] = mb_substr($plain, 0, 300, 'UTF-8');
        out($result);
        return;
    }

    $c = client();
    if ($poster) {
        // 自动生成主题化封面海报（含文章标题），poster=0 时用 cover 参数
        $coverFile = Cover::generate($title, $theme, WXPUB_ACCOUNTS[accountKey()]['name'], WXPUB_CACHE);
    } else {
        $coverFile = $cover;
    }
    $result['thumb_media_id'] = $c->uploadPermanentImage($coverFile);
    $result['cover_used']     = $poster ? '(自动海报)' : $coverFile;
    $draft = $c->addDraft($title, $html, $result['thumb_media_id'], $author, $digest, $srcUrl);
    $result['media_id'] = $draft['media_id'];
    out($result);
}

/**
 * 发布「贴图」（图片消息 newspic）
 *
 * md 参数指向 newspic JSON（由 newspic_md2json.py 从 .newspic.md 生成），结构：
 *   { "title", "content"(描述≤1000字), "digest"(可选), "storyboard":[{slide,role,big,sub,foot}] }
 *
 * 流程：逐页渲染 1080×1440 PNG → uploadPermanentImage 拿 media_id → draft/add(newspic)。
 * 只进草稿箱，不群发。
 */
function actionNewspic(): void
{
    $jsonPath = (string) ($_GET['md'] ?? $_POST['md'] ?? '');
    $theme    = (string) ($_GET['theme'] ?? $_POST['theme'] ?? 'terminal');
    if ($jsonPath === '' || !is_file($jsonPath)) {
        out(['ok' => false, 'error' => "贴图 JSON 文件不存在：{$jsonPath}"], 400);
    }
    $raw = file_get_contents($jsonPath);
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['storyboard']) || !is_array($data['storyboard'])) {
        out(['ok' => false, 'error' => '贴图 JSON 缺少 storyboard 数组'], 400);
    }

    $title   = (string) ($data['title'] ?? '');
    $content = (string) ($data['content'] ?? '');
    $digest  = (string) ($data['digest'] ?? '');
    $storyboard = array_values($data['storyboard']);
    if (count($storyboard) > 9) {
        $storyboard = array_slice($storyboard, 0, 9);
    }
    if (mb_strlen($title, 'UTF-8') > 32) {
        out(['ok' => false, 'error' => '贴图标题超 32 字：' . mb_strlen($title, 'UTF-8')], 400);
    }
    if ($content === '') {
        out(['ok' => false, 'error' => '贴图描述(content)为空'], 400);
    }

    $c = client();
    $brand = WXPUB_ACCOUNTS[accountKey()]['name'];
    $total = count($storyboard);
    $mediaIds = [];
    $tmpFiles = [];

    try {
        foreach ($storyboard as $i => $s) {
            $no   = (int) ($s['slide'] ?? ($i + 1));
            $role = (string) ($s['role'] ?? 'point');
            $big  = (string) ($s['big'] ?? '');
            $sub  = (string) ($s['sub'] ?? '');
            $foot = (string) ($s['foot'] ?? '');
            $png  = WXPUB_CACHE . '/np_' . md5($title . $no . $role . microtime(true)) . '.png';
            NewspicSlide::render($big, $sub, $foot, $role, $theme, $no, $total, $png, $brand);
            $tmpFiles[] = $png;
            $mediaIds[] = $c->uploadPermanentImage($png);
        }

        $article = [
            'article_type'        => 'newspic',
            'title'               => $title,
            'content'             => $content,
            'need_open_comment'   => 0,
            'only_fans_can_comment' => 0,
            'image_info'          => [
                'image_list' => array_map(function ($id) {
                    return ['image_media_id' => $id];
                }, $mediaIds),
            ],
        ];
        if ($digest !== '') {
            $article['digest'] = mb_substr($digest, 0, 128, 'UTF-8');
        }

        $payload = json_encode(['articles' => [$article]], JSON_UNESCAPED_UNICODE);
        $url = 'https://api.weixin.qq.com/cgi-bin/draft/add?access_token=' . $c->getAccessToken();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('图片消息草稿请求失败：' . $err);
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json) || empty($json['media_id'])) {
            $e   = $json['errmsg'] ?? substr((string) $raw, 0, 300);
            $code = $json['errcode'] ?? '?';
            throw new RuntimeException("新增图片消息草稿失败 [{$code}]：{$e}");
        }

        out([
            'ok'             => true,
            'type'           => 'newspic',
            'account'        => accountKey(),
            'title'          => $title,
            'slides'         => $total,
            'image_media_ids'=> $mediaIds,
            'media_id'       => $json['media_id'],
        ]);
    } finally {
        foreach ($tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }
}

function accountKey(): string
{
    $key = (string) ($_GET['account'] ?? $_POST['account'] ?? WXPUB_DEFAULT);
    if (!isset(WXPUB_ACCOUNTS[$key])) {
        throw new RuntimeException(
            "未知账号 '{$key}'，可用：" . implode(', ', array_keys(WXPUB_ACCOUNTS))
        );
    }
    return $key;
}

function client(): WeChatClient
{
    $a = WXPUB_ACCOUNTS[accountKey()];
    return new WeChatClient($a['appid'], $a['secret'], WXPUB_CACHE, accountKey());
}

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
