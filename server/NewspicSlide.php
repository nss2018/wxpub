<?php
/**
 * 公众号「贴图」单页渲染器（GD）— 1080×1440（3:4），主题化。
 *
 * 9 页分镜每页渲染一张图：大字标题 + 小字支撑 + 脚注 + 页码 + 品牌。
 * 复用 Cover.php 的色板与中文字体（wqy-microhei）。
 *
 * 依赖：php-gd（imagettftext）+ 中文字体 wqy-microhei
 */

class NewspicSlide
{
    const W = 1080;
    const H = 1440;
    const FONT = '/usr/share/fonts/wqy-microhei/wqy-microhei.ttc';

    /** 主题色板（与 Cover.php 保持一致） */
    private static $palettes = [
        'terminal'  => ['bg0' => '#050807', 'bg1' => '#0c1811', 'glow1' => '#00c853', 'glow2' => '#0e5c2f', 'accent' => '#00e676', 'text' => '#e9f6ef', 'sub' => '#6fa98a'],
        'neon'      => ['bg0' => '#0a0a14', 'bg1' => '#161632', 'glow1' => '#e94560', 'glow2' => '#7b2ff7', 'accent' => '#ff6b81', 'text' => '#f3eefc', 'sub' => '#8f8ab5'],
        'blackgold' => ['bg0' => '#0c0b08', 'bg1' => '#1c1810', 'glow1' => '#d4b876', 'glow2' => '#8c6d1f', 'accent' => '#e8ca7c', 'text' => '#f6f0e3', 'sub' => '#a89a78'],
        'magma'     => ['bg0' => '#160b07', 'bg1' => '#28130a', 'glow1' => '#ff6b35', 'glow2' => '#c1461a', 'accent' => '#ff8a50', 'text' => '#fdf0e8', 'sub' => '#c09a86'],
        'cobalt'    => ['bg0' => '#060b16', 'bg1' => '#0e1a34', 'glow1' => '#2e7cd6', 'glow2' => '#7b2ff7', 'accent' => '#5aa2f0', 'text' => '#ebf3fd', 'sub' => '#7e97b8'],
        'violet'    => ['bg0' => '#0c0a18', 'bg1' => '#191328', 'glow1' => '#9d6bff', 'glow2' => '#2dd4bf', 'accent' => '#b98cff', 'text' => '#f2edfd', 'sub' => '#9a8ec0'],
    ];

    /** 角色中文标签 */
    private static $roleLabels = [
        'cover' => '封面', 'hook' => '钩子', 'point' => '论点',
        'data'  => '数据', 'list' => '清单', 'cta'   => '收尾',
    ];

    /**
     * 渲染单页贴图并返回写入的文件路径
     * @return string 生成的 PNG 绝对路径
     */
    public static function render(
        string $big, string $sub, string $foot, string $role,
        string $themeId, int $slideNo, int $total, string $outPath, string $brand = '文序云启'
    ): string {
        if (!function_exists('imagettftext')) {
            throw new RuntimeException('php-gd 未安装或缺少 freetype，无法渲染贴图页');
        }
        if (!is_file(self::FONT)) {
            throw new RuntimeException('中文字体不存在：' . self::FONT);
        }
        $p = self::$palettes[$themeId] ?? self::$palettes['terminal'];

        $img = imagecreatetruecolor(self::W, self::H);
        imagealphablending($img, true);

        self::gradientBg($img, $p['bg0'], $p['bg1']);
        self::glow($img, 920, 150, 440, $p['glow1'], 40);   // 右上主辉光
        self::glow($img, 120, 1320, 360, $p['glow2'], 26);  // 左下次辉光
        self::grid($img, $p['sub']);
        self::frame($img, $p['accent']);

        // 顶部：品牌 + 角色标签
        imagefilledrectangle($img, 80, 92, 86, 120, self::c($img, $p['accent']));
        imagettftext($img, 28, 0, 102, 116, self::c($img, $p['sub']), self::FONT, $brand);
        $roleLabel = self::$roleLabels[$role] ?? $role;
        imagettftext($img, 26, 0, self::W - 80 - self::twidth(26, $roleLabel), 116,
            self::c($img, $p['accent']), self::FONT, $roleLabel);

        // 页码（右下）
        $pg = sprintf('%02d / %02d', $slideNo, $total);
        imagettftext($img, 26, 0, self::W - 80 - self::twidth(26, $pg), self::H - 96,
            self::c($img, $p['sub']), self::FONT, $pg);

        // 主区域：大字（按角色调字号）
        $isData  = ($role === 'data');
        $isCover = ($role === 'cover');
        $baseSize = $isData ? 150 : ($isCover ? 92 : 78);
        $maxW   = 900;
        $maxLines = $isData ? 2 : 3;
        $lines = self::wrap($big, $baseSize, $maxW);
        while (count($lines) > $maxLines && $baseSize > 36) {
            $baseSize -= 6;
            $lines = self::wrap($big, $baseSize, $maxW);
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[count($lines) - 1] = mb_substr($lines[count($lines) - 1], 0, -1, 'UTF-8') . '…';
        }
        $lineH  = (int) ($baseSize * 1.4);
        $blockH = $lineH * count($lines);
        $ty     = (int) ((self::H - $blockH) / 2 + $baseSize * 0.3) - ($sub !== '' ? 60 : 0);

        // 左侧强调竖条（封面/数据更粗）
        $barW = $isData ? 16 : 10;
        imagefilledrectangle($img, 80, $ty - (int) ($baseSize * 0.9), 80 + $barW,
            $ty + (int) ($baseSize * 0.3) + ($lineH * (count($lines) - 1)), self::c($img, $p['accent']));
        foreach ($lines as $i => $ln) {
            $y = $ty + $i * $lineH;
            // 伪粗体：偏移 1px 描两遍
            imagettftext($img, $baseSize, 0, 112 + 1, $y, self::c($img, $p['accent'], 48), self::FONT, $ln);
            imagettftext($img, $baseSize, 0, 112, $y, self::c($img, $p['text']), self::FONT, $ln);
        }

        // 小字支撑（大字下方）
        if ($sub !== '') {
            $sSize  = 36;
            $sLines = self::wrap($sub, $sSize, 880);
            while (count($sLines) > 3 && $sSize > 24) {
                $sSize -= 2;
                $sLines = self::wrap($sub, $sSize, 880);
            }
            if (count($sLines) > 3) {
                $sLines = array_slice($sLines, 0, 3);
                $sLines[2] = mb_substr($sLines[2], 0, -1, 'UTF-8') . '…';
            }
            $sLineH = (int) ($sSize * 1.5);
            $sy = $ty + $blockH + 50;
            foreach ($sLines as $i => $ln) {
                imagettftext($img, $sSize, 0, 112, $sy + $i * $sLineH, self::c($img, $p['sub']), self::FONT, $ln);
            }
        }

        // 脚注（底部横线上方）
        if ($foot !== '') {
            imagettftext($img, 24, 0, 112, self::H - 150, self::c($img, $p['sub']), self::FONT, '· ' . $foot);
        }

        if (!imagepng($img, $outPath)) {
            throw new RuntimeException('贴图页写入失败：' . $outPath);
        }
        imagedestroy($img);
        return $outPath;
    }

    private static function gradientBg($img, string $top, string $bottom): void
    {
        [$r0, $g0, $b0] = self::rgb($top);
        [$r1, $g1, $b1] = self::rgb($bottom);
        for ($y = 0; $y < self::H; $y++) {
            $t = $y / (self::H - 1);
            $c = imagecolorallocate($img,
                (int) round($r0 + ($r1 - $r0) * $t),
                (int) round($g0 + ($g1 - $g0) * $t),
                (int) round($b0 + ($b1 - $b0) * $t));
            imageline($img, 0, $y, self::W, $y, $c);
        }
    }

    private static function glow($img, int $cx, int $cy, int $r, string $hex, int $alphaMax): void
    {
        [$cr, $cg, $cb] = self::rgb($hex);
        $steps = 46;
        for ($i = $steps; $i >= 1; $i--) {
            $t   = $i / $steps;
            $rad = $r * $t;
            $a   = (int) ($alphaMax * (1 - $t) * (1 - $t * 0.35)) + 1;
            $c   = imagecolorallocatealpha($img, $cr, $cg, $cb, 127 - min(127, $a));
            imagefilledellipse($img, (int) ($cx + $rad * 0.08), (int) ($cy - $rad * 0.05),
                (int) ($rad * 2), (int) ($rad * 1.15), $c);
        }
    }

    private static function grid($img, string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $c = imagecolorallocatealpha($img, $r, $g, $b, 116);
        for ($x = 40; $x < self::W; $x += 40) {
            imageline($img, $x, 0, $x, self::H, $c);
        }
        for ($y = 40; $y < self::H; $y += 40) {
            imageline($img, 0, $y, self::W, $y, $c);
        }
    }

    private static function frame($img, string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $border = imagecolorallocatealpha($img, $r, $g, $b, 78);
        $solid  = self::c($img, $hex);
        imagerectangle($img, 24, 24, self::W - 25, self::H - 25, $border);
        $m = 24; $L = 26;
        foreach ([[24, 24, 1, 1], [self::W - 25, 24, -1, 1], [24, self::H - 25, 1, -1], [self::W - 25, self::H - 25, -1, -1]] as [$x, $y, $dx, $dy]) {
            imageline($img, $x, $y, $x + $dx * $L, $y, $solid);
            imageline($img, $x, $y, $x, $y + $dy * $L, $solid);
        }
        unset($m);
    }

    /** 中文按像素宽度换行 */
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

    private static function twidth(int $size, string $text): int
    {
        $b = imagettfbbox($size, 0, self::FONT, $text);
        return (int) ($b[2] - $b[0]);
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
        return imagecolorallocatealpha($img, $r, $g, $b, $alpha);
    }
}
