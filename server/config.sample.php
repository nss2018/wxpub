<?php
/**
 * 凭据配置样例 —— 复制为 config.php 后填入真实值；config.php 不要提交到公开仓库
 *
 * 多个公众号用 WXPUB_ACCOUNTS 配，key 是短名（调用时用 account=xxx 指定）。
 * WXPUB_DEFAULT 指向默认账号。
 */

define('WXPUB_ACCOUNTS', [
    'company' => [
        'appid'  => 'YOUR_APPID',
        'secret' => 'YOUR_APPSECRET',
        'name'   => '公众号A（默认）',
    ],
    'gaopo'  => [
        'appid'  => 'YOUR_APPID_2',
        'secret' => 'YOUR_APPSECRET_2',
        'name'   => '公众号B',
    ],
]);

/** 默认账号 key */
define('WXPUB_DEFAULT', 'company');

/** 接口鉴权 token —— 自己改成随机长串，调用时带上 */
define('WXPUB_TOKEN', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');

/** access_token 缓存目录（按账号分文件） */
define('WXPUB_CACHE', __DIR__ . '/.cache');

/** 默认封面图绝对路径（必须 < 2MB，建议 900x383） */
define('WXPUB_DEFAULT_COVER', __DIR__ . '/cover.png');
