<?php
/**
 * 封面海报生成器（GD）— 参考言序封面风格：深色底 + 极光辉光 + 品牌元素 + 文章标题
 *
 * 尺寸 900x383（微信封面推荐比例 2.35:1）。
 * 发布时若无指定封面，自动按主题色生成带标题的海报。
 *
 * 依赖：php-gd（imagettftext）+ 中文字体 wqy-microhei
 */

class Cover
{
    const W = 900;
    const H = 383;
    const FONT = '/usr/share/fonts/wqy-microhei/wqy-microhei.ttc';

    /** 主题海报色板：bg0 顶部底色 / bg1 底部底色 / glow1+glow2 辉光 / accent 强调 / text 主文字 / sub 次文字 */
    private static $palettes = [
        'terminal'  => ['bg0' => '#050807', 'bg1' => '#0c1811', 'glow1' => '#00c853', 'glow2' => '#0e5c2f', 'accent' => '#00e676', 'text' => '#e9f6ef', 'sub' => '#6fa98a'],
        'neon'      => ['bg0' => '#0a0a14', 'bg1' => '#161632', 'glow1' => '#e94560', 'glow2' => '#7b2ff7', 'accent' => '#ff6b81', 'text' => '#f3eefc', 'sub' => '#8f8ab5'],
        'blackgold' => ['bg0' => '#0c0b08', 'bg1' => '#1c1810', 'glow1' => '#d4b876', 'glow2' => '#8c6d1f', 'accent' => '#e8ca7c', 'text' => '#f6f0e3', 'sub' => '#a89a78'],
        'magma'     => ['bg0' => '#160b07', 'bg1' => '#28130a', 'glow1' => '#ff6b35', 'glow2' => '#c1461a', 'accent' => '#ff8a50', 'text' => '#fdf0e8', 'sub' => '#c09a86'],
        'cobalt'    => ['bg0' => '#060b16', 'bg1' => '#0e1a34', 'glow1' => '#2e7cd6', 'glow2' => '#7b2ff7', 'accent' => '#5aa2f0', 'text' => '#ebf3fd', 'sub' => '#7e97b8'],
        'violet'    => ['bg0' => '#0c0a18', 'bg1' => '#191328', 'glow1' => '#9d6bff', 'glow2' => '#2dd4bf', 'accent' => '#b98cff', 'text' => '#f2edfd', 'sub' => '#9a8ec0'],
    ];

    /**
     * 生成海报并写入 PNG
     * @return string 生成的文件绝对路径
     */
    public static function generate(string $title, string $themeId, string $brand, string $outDir): string
    {
        if (!function_exists('imagettftext')) {
            throw new RuntimeException('php-gd 未安装或缺少 freetype，无法生成封面海报');
        }
        if (!is_file(self::FONT)) {
            throw new RuntimeException('中文字体不存在：' . self::FONT);
        }
        $p = self::$palettes[$themeId] ?? self::$palettes['terminal'];

        $img = imagecreatetruecolor(self::W, self::H);
        imagealphablending($img, true);

        self::gradientBg($img, $p['bg0'], $p['bg1']);
        self::glow($img, 760, 60, 300, $p['glow1'], 42);   // 右上主辉光
        self::glow($img, 80, 360, 260, $p['glow2'], 30);   // 左下次辉光
        self::glow($img, 480, 210, 200, $p['glow1'], 14);  // 标题后微光
        self::grid($img, $p['sub']);
        self::rings($img, 760, 60, $p['glow1']);
        self::frame($img, $p['accent']);

        // 品牌行（左上）：竖条 + 账号名
        imagefilledrectangle($img, 64, 40, 67, 58, self::c($img, $p['accent']));
        imagettftext($img, 14, 0, 78, 55, self::c($img, $p['sub']), self::FONT, $brand);

        // 标题区：左侧强调竖条 + 自动换行大标题（最多 2 行，字号自适应）
        $size  = 52;
        $maxW  = 720;
        $lines = self::wrap($title, $size, $maxW);
        while (count($lines) > 2 && $size >= 32) {
            $size -= 6;
            $lines = self::wrap($title, $size, $maxW);
        }
        if (count($lines) > 2) {
            $lines = array_slice($lines, 0, 2);
            $lines[1] = mb_substr($lines[1], 0, mb_strlen($lines[1], 'UTF-8') - 1, 'UTF-8') . '…';
        }
        $lineH  = (int) ($size * 1.42);
        $blockH = $lineH * count($lines);
        $ty     = (int) ((self::H - $blockH) / 2 + $size * 0.28) + 14;
        imagefilledrectangle($img, 64, $ty - (int) ($size * 0.92), 70, $ty + (int) ($size * 0.42) + ($lineH * (count($lines) - 1)), self::c($img, $p['accent']));
        foreach ($lines as $i => $ln) {
            $y = $ty + $i * $lineH;
            // 伪粗体：偏移 1px 描两遍
            imagettftext($img, $size, 0, 89, $y, self::c($img, $p['accent'], 46), self::FONT, $ln);
            imagettftext($img, $size, 0, 88, $y, self::c($img, $p['text']), self::FONT, $ln);
        }

        // 底部 meta 行：日期 · 主题名（左）  WX·PUB（右）
        $meta = date('Y.m.d') . '  ·  ' . self::themeName($themeId);
        imagettftext($img, 14, 0, 88, self::H - 40, self::c($img, $p['sub']), self::FONT, $meta);
        imagettftext($img, 13, 0, self::W - 132, self::H - 40, self::c($img, $p['sub']), self::FONT, 'WX·PUB');

        if (!is_dir($outDir) || !is_writable($outDir)) {
            throw new RuntimeException("封面输出目录不可写：{$outDir}");
        }
        $out = rtrim($outDir, '/') . '/poster.' . md5($title . $themeId . $brand) . '.png';
        if (!imagepng($img, $out)) {
            throw new RuntimeException('封面写入失败：' . $out);
        }
        imagedestroy($img);
        return $out;
    }

    /** 顶部→底部的竖向渐变底色 */
    private static function gradientBg($img, string $top, string $bottom): void
    {
        [$r0, $g0, $b0] = self::rgb($top);
        [$r1, $g1, $b1] = self::rgb($bottom);
        for ($y = 0; $y < self::H; $y++) {
            $t  = $y / (self::H - 1);
            $c  = imagecolorallocate($img,
                (int) round($r0 + ($r1 - $r0) * $t),
                (int) round($g0 + ($g1 - $g0) * $t),
                (int) round($b0 + ($b1 - $b0) * $t));
            imageline($img, 0, $y, self::W, $y, $c);
        }
    }

    /** 极光辉光：由外向内逐层低透明度实心椭圆 */
    private static function glow($img, int $cx, int $cy, int $r, string $hex, int $alphaMax): void
    {
        [$cr, $cg, $cb] = self::rgb($hex);
        $steps = 46;
        for ($i = $steps; $i >= 1; $i--) {
            $t    = $i / $steps;
            $rad  = $r * $t;
            $a    = (int) ($alphaMax * (1 - $t) * (1 - $t * 0.35)) + 1;
            $c    = imagecolorallocatealpha($img, $cr, $cg, $cb, 127 - min(127, $a));
            imagefilledellipse($img, (int) ($cx + $rad * 0.08), (int) ($cy - $rad * 0.05), (int) ($rad * 2), (int) ($rad * 1.15), $c);
        }
    }

    /** 光环弧线：辉光中心外围 2 圈细弧 */
    private static function rings($img, int $cx, int $cy, string $hex): void
    {
        [$cr, $cg, $cb] = self::rgb($hex);
        foreach ([[190, 40], [260, 26]] as [$r, $a]) {
            $c = imagecolorallocatealpha($img, $cr, $cg, $cb, $a);
            imagearc($img, $cx, $cy, $r * 2, (int) ($r * 1.4), 140, 330, $c);
        }
    }

    /** 细网格纹理 */
    private static function grid($img, string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $c = imagecolorallocatealpha($img, $r, $g, $b, 112);
        for ($x = 32; $x < self::W; $x += 32) {
            imageline($img, $x, 0, $x, self::H, $c);
        }
        for ($y = 32; $y < self::H; $y += 32) {
            imageline($img, 0, $y, self::W, $y, $c);
        }
    }

    /** 内边框 + 四角短刻线 */
    private static function frame($img, string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $border = imagecolorallocatealpha($img, $r, $g, $b, 72);
        $solid  = self::c($img, $hex);
        imagerectangle($img, 18, 18, self::W - 19, self::H - 19, $border);
        $m = 18; $L = 20;
        foreach ([[18, 18, 1, 1], [self::W - 19, 18, -1, 1], [18, self::H - 19, 1, -1], [self::W - 19, self::H - 19, -1, -1]] as [$x, $y, $dx, $dy]) {
            imageline($img, $x, $y, $x + $dx * $L, $y, $solid);
            imageline($img, $x, $y, $x, $y + $dy * $L, $solid);
        }
        unset($m);
    }

    /** 中文按像素宽度换行（逐字符累积） */
    private static function wrap(string $text, int $size, int $maxW): array
    {
        $text  = str_replace(["\n", "\r"], '', $text);
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        $cur   = '';
        foreach ($chars as $ch) {
            $try = $cur . $ch;
            $box = imagettfbbox($size, 0, self::FONT, $try);
            if (($box[2] - $box[0]) > $maxW && $cur !== '') {
                $lines[] = $cur;
                $cur = $ch;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines ?: [''];
    }

    private static function themeName(string $id): string
    {
        $names = [
            'terminal' => '终端矩阵', 'neon' => '霓虹脉冲', 'blackgold' => '曜金质感',
            'magma' => '赤焰警示', 'cobalt' => '电光靛蓝', 'violet' => '紫电深空',
        ];
        return $names[$id] ?? $id;
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function c($img, string $hex, int $alpha = 0): int
    {
        [$r, $g, $b] = self::rgb($hex);
        return $alpha > 0 ? imagecolorallocatealpha($img, $r, $g, $b, $alpha) : imagecolorallocate($img, $r, $g, $b);
    }
}
