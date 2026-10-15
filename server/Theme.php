<?php
/**
 * 主题引擎：把 Markdown 渲染成微信兼容的内联样式 HTML
 *
 * 微信会剥离 <style> 标签和 class 属性，只认 style="..." 行内样式。
 * 所以每条规则必须内联到元素上。
 */

class Theme
{
    /** 内联样式表：元素 => style 字符串 */
    public $styles = [];
    /** 容器内边距 */
    public $containerStyle = '';
    /** 当前主题 id */
    public $themeId;

    public function __construct(string $themeId)
    {
        $this->themeId = $themeId;
        $this->styles = self::load($themeId);
    }

    /** 各主题强调色（END 标记、封面海报共用） */
    public static function palettes(): array
    {
        return [
            'terminal'  => ['accent' => '#00e676', 'name' => '终端矩阵'],
            'neon'      => ['accent' => '#ff6b81', 'name' => '霓虹脉冲'],
            'blackgold' => ['accent' => '#e8ca7c', 'name' => '曜金质感'],
            'magma'     => ['accent' => '#ff8a50', 'name' => '赤焰警示'],
            'cobalt'    => ['accent' => '#5aa2f0', 'name' => '电光靛蓝'],
            'violet'    => ['accent' => '#b98cff', 'name' => '紫电深空'],
        ];
    }

    /**
     * 文末结束块：菱形锚点 + 上下长线夹 THE END（章牌式，暗色模式亦清晰）
     */
    public function endBlock(): string
    {
        $p      = self::palettes()[$this->themeId] ?? self::palettes()['terminal'];
        $accent = $p['accent'];
        return '<p style="text-align:center;margin:56px 0 0 0;line-height:1;">'
             . '<span style="color:' . $accent . ';font-size:9px;">◆</span>'
             . '</p>'
             . '<p style="text-align:center;margin:18px 0 14px 0;line-height:1;">'
             . '<span style="display:inline-block;min-width:230px;padding:15px 20px;border-top:1px solid ' . $accent . ';border-bottom:1px solid ' . $accent . ';font-size:14px;font-weight:600;letter-spacing:8px;text-indent:8px;color:' . $accent . ';">THE&nbsp;END</span>'
             . '</p>';
    }

    /**
     * 取某元素的行内样式；未定义时返回空串
     */
    public function s(string $element): string
    {
        return $this->styles[$element] ?? '';
    }

    public static function list(): array
    {
        return [
            'terminal'  => '终端矩阵 — 技术拆解、代码、开发者向',
            'neon'      => '霓虹脉冲 — AI、大模型、前沿科技',
            'blackgold' => '曜金质感 — 高端深度稿、品牌与行业观察',
            'magma'     => '赤焰警示 — 热点评论、争议快评',
            'cobalt'    => '电光靛蓝 — 财报解读、行业数据、深度分析',
            'violet'    => '紫电深空 — 概念产品、未来科技、发布会',
        ];
    }

    private static function load(string $id): array
    {
        switch ($id) {
            case 'neon':      return self::neon();
            case 'blackgold': return self::blackgold();
            case 'magma':     return self::magma();
            case 'cobalt':    return self::cobalt();
            case 'violet':    return self::violet();
            case 'terminal':
            default:          return self::terminal();
        }
    }

    /** 终端矩阵：终端绿 + 深色代码块 + 编号标题 */
    private static function terminal(): array
    {
        return [
            'container' => 'font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;font-size:15px;line-height:1.75;color:#2c3e50;letter-spacing:0.4px;',
            'h1'        => 'font-size:22px;font-weight:600;color:#0a1a10;margin:0 0 24px 0;padding:0 0 12px 0;border-bottom:2px solid #00c853;line-height:1.4;',
            'h2'        => 'font-size:18px;font-weight:600;color:#00a844;margin:34px 0 16px 0;padding-left:11px;border-left:4px solid #00c853;line-height:1.4;',
            'h3'        => 'font-size:16px;font-weight:600;color:#007a33;margin:26px 0 12px 0;line-height:1.5;',
            'p'         => 'margin:16px 0;color:#2c3e50;line-height:1.8;',
            'strong'    => 'color:#00873c;font-weight:600;',
            'em'        => 'color:#4a6572;font-style:italic;',
            'a'         => 'color:#00a844;text-decoration:none;border-bottom:1px solid rgba(0,168,68,0.3);',
            'blockquote'=> 'margin:20px 0;padding:14px 16px;background:#f0fdf4;border-left:4px solid #00c853;color:#2c5f3a;font-size:14px;line-height:1.7;',
            'code'      => 'background:#f0fdf4;color:#00873c;padding:2px 6px;font-family:Menlo,Monaco,Consolas,monospace;font-size:13px;border-radius:3px;',
            'pre'       => 'background:#0d1117;color:#c9f1d0;padding:16px 18px;margin:20px 0;font-family:Menlo,Monaco,Consolas,monospace;font-size:13px;line-height:1.65;overflow-x:auto;border-radius:8px;border-left:3px solid #00c853;',
            'precode'   => 'background:transparent;color:inherit;padding:0;font-size:13px;',
            'ul'        => 'margin:16px 0;padding-left:22px;color:#2c3e50;',
            'ol'        => 'margin:16px 0;padding-left:22px;color:#2c3e50;',
            'li'        => 'margin:9px 0;line-height:1.75;',
            'hr'        => 'border:none;border-top:1px dashed #b7e4c7;margin:32px 0;',
            'img'       => 'max-width:100%;height:auto;display:block;margin:20px auto;border-radius:6px;',
        ];
    }

    /** 霓虹脉冲：深色导语卡 + 三色点标题 */
    private static function neon(): array
    {
        return [
            'container' => 'font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;font-size:15px;line-height:1.8;color:#1a1a2e;',
            'h1'        => 'font-size:22px;font-weight:600;color:#0f0f1e;margin:0 0 24px 0;text-align:center;line-height:1.5;',
            'h2'        => 'font-size:18px;font-weight:600;color:#e94560;margin:32px 0 16px 0;padding-left:12px;line-height:1.4;',
            'h3'        => 'font-size:16px;font-weight:600;color:#0a2472;margin:24px 0 12px 0;',
            'p'         => 'margin:16px 0;color:#1a1a2e;line-height:1.85;',
            'strong'    => 'color:#e94560;font-weight:600;',
            'em'        => 'color:#0a2472;font-style:italic;',
            'a'         => 'color:#0a2472;text-decoration:none;border-bottom:1px solid rgba(10,36,114,0.25);',
            'blockquote'=> 'margin:22px 0;padding:18px 20px;background:linear-gradient(135deg,#1a1a2e,#16213e);color:#a8e6cf;border-radius:10px;font-size:14px;line-height:1.75;',
            'code'      => 'background:#f5f0ff;color:#7b2ff7;padding:2px 6px;font-family:Menlo,Monaco,monospace;font-size:13px;border-radius:3px;',
            'pre'       => 'background:#16213e;color:#a8e6cf;padding:16px 18px;margin:20px 0;font-family:Menlo,Monaco,monospace;font-size:13px;line-height:1.65;overflow-x:auto;border-radius:8px;',
            'precode'   => 'background:transparent;color:inherit;padding:0;font-size:13px;',
            'ul'        => 'margin:16px 0;padding-left:22px;color:#1a1a2e;',
            'ol'        => 'margin:16px 0;padding-left:22px;color:#1a1a2e;',
            'li'        => 'margin:9px 0;line-height:1.8;',
            'hr'        => 'border:none;border-top:1px solid #dcd6f7;margin:32px 0;',
            'img'       => 'max-width:100%;height:auto;display:block;margin:20px auto;border-radius:8px;',
        ];
    }

    /** 曜金质感：墨黑导语卡 + 金色底线标题 */
    private static function blackgold(): array
    {
        return [
            'container' => 'font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;font-size:15px;line-height:1.8;color:#2b2b2b;',
            'h1'        => 'font-size:22px;font-weight:600;color:#1a1a1a;margin:0 0 24px 0;padding-bottom:14px;border-bottom:1px solid #b8963e;line-height:1.4;',
            'h2'        => 'font-size:18px;font-weight:600;color:#8c6d1f;margin:32px 0 16px 0;padding-bottom:8px;border-bottom:1px solid rgba(184,150,62,0.3);line-height:1.4;',
            'h3'        => 'font-size:16px;font-weight:600;color:#5c4a1a;margin:24px 0 12px 0;',
            'p'         => 'margin:16px 0;color:#2b2b2b;line-height:1.85;',
            'strong'    => 'color:#8c6d1f;font-weight:600;',
            'em'        => 'color:#5a5a5a;font-style:italic;',
            'a'         => 'color:#8c6d1f;text-decoration:none;border-bottom:1px solid rgba(184,150,62,0.35);',
            'blockquote'=> 'margin:22px 0;padding:18px 20px;background:#1a1a1a;color:#d4b876;font-size:14px;line-height:1.8;border-radius:4px;',
            'code'      => 'background:#f7f3ea;color:#8c6d1f;padding:2px 6px;font-family:Menlo,Monaco,monospace;font-size:13px;border-radius:3px;',
            'pre'       => 'background:#1a1a1a;color:#e8dcc0;padding:16px 18px;margin:20px 0;font-family:Menlo,Monaco,monospace;font-size:13px;line-height:1.65;overflow-x:auto;border-radius:4px;',
            'precode'   => 'background:transparent;color:inherit;padding:0;font-size:13px;',
            'ul'        => 'margin:16px 0;padding-left:22px;color:#2b2b2b;',
            'ol'        => 'margin:16px 0;padding-left:22px;color:#2b2b2b;',
            'li'        => 'margin:9px 0;line-height:1.8;',
            'hr'        => 'border:none;border-top:1px solid #d4c9a8;margin:32px 0;',
            'img'       => 'max-width:100%;height:auto;display:block;margin:20px auto;',
        ];
    }

    /** 赤焰警示：暖色标签导语 + 橙色结尾 */
    private static function magma(): array
    {
        return [
            'container' => 'font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;font-size:15px;line-height:1.8;color:#2d2320;',
            'h1'        => 'font-size:22px;font-weight:600;color:#1f1512;margin:0 0 24px 0;padding-left:13px;border-left:5px solid #e8622c;line-height:1.4;',
            'h2'        => 'font-size:18px;font-weight:600;color:#c1461a;margin:32px 0 16px 0;line-height:1.4;',
            'h3'        => 'font-size:16px;font-weight:600;color:#8c3210;margin:24px 0 12px 0;',
            'p'         => 'margin:16px 0;color:#2d2320;line-height:1.85;',
            'strong'    => 'color:#c1461a;font-weight:600;',
            'em'        => 'color:#6b5548;font-style:italic;',
            'a'         => 'color:#c1461a;text-decoration:none;border-bottom:1px solid rgba(193,70,26,0.3);',
            'blockquote'=> 'margin:22px 0;padding:16px 18px;background:#fff4ef;border-left:4px solid #e8622c;color:#6b3a22;font-size:14px;line-height:1.75;border-radius:0 6px 6px 0;',
            'code'      => 'background:#fff0e8;color:#c1461a;padding:2px 6px;font-family:Menlo,Monaco,monospace;font-size:13px;border-radius:3px;',
            'pre'       => 'background:#2d2320;color:#ffd9c4;padding:16px 18px;margin:20px 0;font-family:Menlo,Monaco,monospace;font-size:13px;line-height:1.65;overflow-x:auto;border-radius:6px;',
            'precode'   => 'background:transparent;color:inherit;padding:0;font-size:13px;',
            'ul'        => 'margin:16px 0;padding-left:22px;color:#2d2320;',
            'ol'        => 'margin:16px 0;padding-left:22px;color:#2d2320;',
            'li'        => 'margin:9px 0;line-height:1.8;',
            'hr'        => 'border:none;border-top:1px solid #f0c9b4;margin:32px 0;',
            'img'       => 'max-width:100%;height:auto;display:block;margin:20px auto;border-radius:6px;',
        ];
    }

    /** 电光靛蓝：靛蓝强调线标题 + 双横线结尾 */
    private static function cobalt(): array
    {
        return [
            'container' => 'font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;font-size:15px;line-height:1.8;color:#1f2933;',
            'h1'        => 'font-size:22px;font-weight:600;color:#12263f;margin:0 0 24px 0;line-height:1.4;',
            'h2'        => 'font-size:18px;font-weight:600;color:#1e4d8c;margin:32px 0 16px 0;padding-bottom:9px;border-bottom:1px solid #1e4d8c;line-height:1.4;',
            'h3'        => 'font-size:16px;font-weight:600;color:#2c5fa8;margin:24px 0 12px 0;',
            'p'         => 'margin:16px 0;color:#1f2933;line-height:1.85;',
            'strong'    => 'color:#1e4d8c;font-weight:600;',
            'em'        => 'color:#52606d;font-style:italic;',
            'a'         => 'color:#1e4d8c;text-decoration:none;border-bottom:1px solid rgba(30,77,140,0.3);',
            'blockquote'=> 'margin:22px 0;padding:16px 18px;background:#eef3f9;border-left:4px solid #1e4d8c;color:#2c4a6b;font-size:14px;line-height:1.8;',
            'code'      => 'background:#eef3f9;color:#1e4d8c;padding:2px 6px;font-family:Menlo,Monaco,monospace;font-size:13px;border-radius:3px;',
            'pre'       => 'background:#12263f;color:#d6e4f7;padding:16px 18px;margin:20px 0;font-family:Menlo,Monaco,monospace;font-size:13px;line-height:1.65;overflow-x:auto;border-radius:8px;',
            'precode'   => 'background:transparent;color:inherit;padding:0;font-size:13px;',
            'ul'        => 'margin:16px 0;padding-left:22px;color:#1f2933;',
            'ol'        => 'margin:16px 0;padding-left:22px;color:#1f2933;',
            'li'        => 'margin:9px 0;line-height:1.8;',
            'hr'        => 'border:none;border-top:3px double #1e4d8c;margin:32px 0;',
            'img'       => 'max-width:100%;height:auto;display:block;margin:20px auto;border-radius:8px;',
        ];
    }

    /** 紫电深空：紫青双色点标题 + 紫色强调导语 */
    private static function violet(): array
    {
        return [
            'container' => 'font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;font-size:15px;line-height:1.8;color:#1e1b2e;',
            'h1'        => 'font-size:22px;font-weight:600;color:#15122a;margin:0 0 24px 0;line-height:1.4;',
            'h2'        => 'font-size:18px;font-weight:600;color:#6b3fa0;margin:32px 0 16px 0;padding-left:12px;line-height:1.4;',
            'h3'        => 'font-size:16px;font-weight:600;color:#2d8b8b;margin:24px 0 12px 0;',
            'p'         => 'margin:16px 0;color:#1e1b2e;line-height:1.85;',
            'strong'    => 'color:#6b3fa0;font-weight:600;',
            'em'        => 'color:#4a4a6a;font-style:italic;',
            'a'         => 'color:#6b3fa0;text-decoration:none;border-bottom:1px solid rgba(107,63,160,0.3);',
            'blockquote'=> 'margin:22px 0;padding:18px 20px;background:#f5f0fa;border-left:4px solid #6b3fa0;color:#4a3a6b;font-size:14px;line-height:1.8;border-radius:0 6px 6px 0;',
            'code'      => 'background:#f0ebf7;color:#6b3fa0;padding:2px 6px;font-family:Menlo,Monaco,monospace;font-size:13px;border-radius:3px;',
            'pre'       => 'background:#1e1b2e;color:#d6c9f0;padding:16px 18px;margin:20px 0;font-family:Menlo,Monaco,monospace;font-size:13px;line-height:1.65;overflow-x:auto;border-radius:8px;',
            'precode'   => 'background:transparent;color:inherit;padding:0;font-size:13px;',
            'ul'        => 'margin:16px 0;padding-left:22px;color:#1e1b2e;',
            'ol'        => 'margin:16px 0;padding-left:22px;color:#1e1b2e;',
            'li'        => 'margin:9px 0;line-height:1.8;',
            'hr'        => 'border:none;border-top:1px solid #cfc0e8;margin:32px 0;',
            'img'       => 'max-width:100%;height:auto;display:block;margin:20px auto;border-radius:8px;',
        ];
    }
}
