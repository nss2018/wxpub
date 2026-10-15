# wxpub — 自建微信公众号排版推送服务

把 Markdown 文章一键变成微信兼容的排版 HTML，推送到公众号**草稿箱**。自带 6 套主题、GD 自动封面海报、文末 END 标记，以及「贴图」（图片消息）9 页分镜渲染。整套服务跑在自己的服务器上，不依赖任何第三方 SaaS。

## 功能

- **Markdown → 微信内联样式 HTML**：微信会剥离 `<style>` 标签和 class 属性，只认 `style="..."` 行内样式——服务端把每条规则内联到元素上（`Theme.php`）
- **6 套主题**：`terminal` / `neon` / `blackgold` / `magma` / `cobalt` / `violet`
- **封面海报自动生成**：按主题色渲染 900×383 海报（辉光 + 网格 + 标题 + 账号名，GD + wqy-microhei 中文字体），无需手动做封面；也可传自己的图（`poster=0&cover=/path/x.png`）
- **文末 END 标记**：菱形 + 上下长线夹 THE END 章牌式，随主题强调色，自动追加
- **贴图（newspic）**：公众号 2026-02 把图文改名「贴图」即图片消息。逐页渲染 1080×1440（3:4）PNG → 上传拿 `media_id` → `draft/add` 进草稿箱；App 端贴图 UX 限 9 张
- **多公众号账号**：一个服务推多个号，调用时 `account=xxx` 指定
- **免 SSH 部署**：`push.py` 走阿里云云助手 OpenAPI（RunCommand）同步代码，不需要 SSH 密码/密钥

## 目录结构

```
wxpub/
├── server/                  # 部署到服务器 /www/wxpub/
│   ├── wxpub.php            #   HTTP 入口（preview/publish/newspic/drafts/token）
│   ├── Theme.php            #   主题引擎：Markdown → 内联样式 HTML
│   ├── Markdown.php         #   Markdown 解析
│   ├── Cover.php            #   GD 封面海报渲染
│   ├── NewspicSlide.php     #   贴图单页渲染（1080×1440）
│   ├── WeChatClient.php     #   微信 API 客户端（token 缓存、素材上传、草稿箱）
│   ├── config.sample.php    #   配置样例（复制为 config.php 填真实值）
│   └── cover.png            #   默认封面底图
├── scripts/
│   ├── push.py              # 阿里云云助手推送/远程命令（免 SSH）
│   ├── publish.sh           # 一键发布文章到草稿箱
│   ├── publish_newspic.sh   # 一键发布贴图到草稿箱
│   ├── newspic_md2json.py   # .newspic.md 分镜稿 → JSON
│   ├── new_article.py       # 爆文骨架生成
│   ├── check_article.py     # 发布前质检（不过不许发）
│   └── gen_wtxt.py          # 长文排版生成
└── references/
    └── viral-writing-guide.md   # 爆文写作方法论
```

## 部署

环境要求：**PHP 7.4**（别用 8.x 语法）+ `php-gd` + 中文字体 `wqy-microhei` + nginx。

```bash
# 1. 上传代码到服务器
export ALIYUN_AK_ID=xxx ALIYUN_AK_SECRET=xxx ALIYUN_SWAS_INSTANCE_ID=xxx
python3 scripts/push.py sync          # 推送 server/ 全部文件并 php -l 校验

# 2. 配置（在服务器上）
cd /www/wxpub
cp config.sample.php config.php       # 填入 appid / secret / 自定鉴权 token
mkdir -p .cache && chown apache:apache .cache && chmod 700 .cache
```

```bash
# 3. 日常同步单文件
python3 scripts/push.py put 本地文件 /www/wxpub/远程文件名
python3 scripts/push.py run 'ls -la /www/wxpub'    # 远程执行任意命令
```

微信公众号后台 → 开发 → 基本配置 → IP白名单：**把服务器公网 IP 加进去**（见下方踩坑 #1）。

## 使用

```bash
export WXPUB_API="https://你的服务器/wxpub/wxpub.php"
export WXPUB_TOKEN="你config.php里定的token"

# 预览（只渲染不发布，返回 HTML）
curl -sk "$WXPUB_API?action=preview&k=$WXPUB_TOKEN&md=/www/wxpub/_draft.md&theme=magma"

# 发布文章到草稿箱（account 默认取 config 的 WXPUB_DEFAULT）
curl -sk -X POST "$WXPUB_API?k=$WXPUB_TOKEN" \
  -d 'action=publish' -d 'md=/www/wxpub/_draft.md' -d 'theme=magma' -d 'account=account-a'

# 发布贴图（newspic）
scripts/publish_newspic.sh 稿.newspic.md account-a terminal

# 查草稿箱 / 强制刷新 token
curl -sk "$WXPUB_API?action=drafts&k=$WXPUB_TOKEN"
curl -sk "$WXPUB_API?action=token&k=$WXPUB_TOKEN&account=account-a"
```

参数：`md` 必须是**服务器绝对路径**；主题 6 选 1；可选 `cover`（封面绝对路径）、`poster=0`（关自动海报）、`author`（≤16字）、`digest`（≤120字）。硬限制：`title` ≤32 字，`content` ≤2 万字符。

贴图输入 JSON 结构：`{title, content(≤1000字), digest?, storyboard:[{slide,role,big,sub,foot}]}`。

爆文流水线：`new_article.py` 生成骨架 → AI 按 `references/viral-writing-guide.md` 写初稿 → 人工润色 → `check_article.py` 质检（exit 0 才可发）→ `publish.sh` 发布。

## 踩坑记录（实测）

1. **IP 白名单按公众号独立配置**：报 `40164 invalid ip` = 白名单没加当前服务器 IP；`40001` = secret 错。多个号要分别加。
2. **php-fpm 用户是 `apache` 不是 `www`**：权限问题一律 `chown apache:apache`（如 `.cache` 目录 700）。
3. **服务器 PHP 是 7.4**：别用 8.x 语法（`str_starts_with` / `match` / 枚举）。
4. **微信 API 的 multipart 必须用 CURLFile 构造**：手写 boundary 必挂（`41005`）。
5. **curl 必须带 `-k`**：IP 直接访问时证书不匹配。
6. **改 config.php 后要删 `.cache/access_token.{key}.json`** 才会重新取 token。
7. **个人订阅号不能 API 群发**（`48001`）：只能推进草稿箱，群发需后台手动点。本服务定位即「草稿箱工厂」。
8. **newspic 的 `image_info.image_list` 必须是 `[{image_media_id:"..."}]` 对象数组**（不是字符串数组）；`cover_info` 可省略（首图即封面）；图片消息最多 20 张，App 端贴图 UX 限 9 张。

## 流量纪律（2026-07 微信官方公开课口径）

- 公众号已全面推荐流化，**推荐流量可占 76%**，小号也有机会；拿推荐流的硬条件：非分组发布、声明原创、**不勾**「不允许被平台推荐」
- 官方推荐四类内容：标题清晰完整的**原创**、**真人创作**真实分享、有价值有共鸣、专业领域认证作者——AI 批量水文权重天然偏低，AI 初稿必须真人化润色
- 无基础流量保障，**断更即断崖式下跌**；单篇 500 字以上、1000–2000 字为宜（完读率），标题+首图决定打开率

## License

MIT
