<?php
/**
 * Markdown → 微信内联样式 HTML
 *
 * 支持：标题、段落、粗体、斜体、行内代码、代码块、引用、无序/有序列表、
 *       分割线、图片、链接。
 * 不支持：表格（微信渲染效果差，建议改写为列表或段落）
 */

class Markdown
{
    private Theme $theme;

    public function __construct(Theme $theme)
    {
        $this->theme = $theme;
    }

    /**
     * @return array{0:string,1:string} [正文 HTML, 去掉标记后的纯文本]
     */
    public function render(string $md): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $md);
        $html  = '';
        $text  = '';

        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            $trim = trim($line);

            // 空行
            if ($trim === '') {
                $i++;
                continue;
            }

            // 代码块 ```
            if (strpos($trim, '```') === 0) {
                $lang = substr($trim, 3);
                $buf = [];
                $i++;
                while ($i < $n && strpos(trim($lines[$i]), '```') !== 0) {
                    $buf[] = $lines[$i];
                    $i++;
                }
                $i++; // 跳过结束标记
                $code = htmlspecialchars(implode("\n", $buf), ENT_QUOTES, 'UTF-8');
                $html .= '<pre style="' . $this->theme->s('pre') . '"><code style="'
                      . $this->theme->s('precode') . '">' . $code . '</code></pre>';
                $text .= "\n" . implode("\n", $buf) . "\n";
                continue;
            }

            // 分割线
            if (preg_match('/^([-*_])\1{2,}$/', $trim)) {
                $html .= '<hr style="' . $this->theme->s('hr') . '" />';
                $i++;
                continue;
            }

            // 标题
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
                $level = min(strlen($m[1]), 3);
                $inner = $this->inline($m[2]);
                $html .= '<h' . $level . ' style="' . $this->theme->s('h' . $level) . '">'
                      . $inner . '</h' . $level . '>';
                $text .= "\n" . $m[2] . "\n";
                $i++;
                continue;
            }

            // 引用
            if (strpos($trim, '>') === 0) {
                $buf = [];
                while ($i < $n && strpos(trim($lines[$i]), '>') === 0) {
                    $buf[] = preg_replace('/^\s*>\s?/', '', $lines[$i]);
                    $i++;
                }
                $inner = $this->inline(trim(implode(' ', $buf)));
                $html .= '<blockquote style="' . $this->theme->s('blockquote') . '">'
                      . $inner . '</blockquote>';
                $text .= ' ' . trim(implode(' ', $buf)) . ' ';
                continue;
            }

            // 列表（连续的 - / 1. / * 行）
            if (preg_match('/^([-*+]|\d+\.)\s+(.*)$/', $trim)) {
                $ordered = (bool) preg_match('/^\d+\./', $trim);
                $items = [];
                while ($i < $n) {
                    $t = trim($lines[$i]);
                    if ($t === '') {
                        // 空行后若不是列表项则结束
                        $j = $i + 1;
                        if ($j < $n && preg_match('/^([-*+]|\d+\.)\s+/', trim($lines[$j]))) {
                            $i = $j;
                            continue;
                        }
                        break;
                    }
                    if (!preg_match('/^([-*+]|\d+\.)\s+(.*)$/', $t, $m)) {
                        break;
                    }
                    $items[] = $this->inline($m[2]);
                    $i++;
                }
                $tag = $ordered ? 'ol' : 'ul';
                $html .= '<' . $tag . ' style="' . $this->theme->s($tag) . '">';
                foreach ($items as $it) {
                    $html .= '<li style="' . $this->theme->s('li') . '">' . $it . '</li>';
                }
                $html .= '</' . $tag . '>';
                $text .= "\n" . implode("\n", $items) . "\n";
                continue;
            }

            // 段落：连续非空行合并
            $buf = [];
            while ($i < $n && trim($lines[$i]) !== '') {
                $t = trim($lines[$i]);
                if (preg_match('/^(#{1,6})\s/', $t)
                    || strpos($t, '```') === 0
                    || strpos($t, '>') === 0
                    || preg_match('/^([-*_])\1{2,}$/', $t)
                    || preg_match('/^([-*+]|\d+\.)\s+/', $t)) {
                    break;
                }
                $buf[] = $t;
                $i++;
            }
            if ($buf) {
                $inner = $this->inline(implode("\n", $buf));
                $html .= '<p style="' . $this->theme->s('p') . '">' . $inner . '</p>';
                $text .= ' ' . implode(' ', $buf) . ' ';
            }
        }

        return [$html, trim($text)];
    }

    /**
     * 行内标记：图片 > 链接 > 行内代码 > 粗体 > 斜体
     * 顺序很重要：代码里的 * _ 不应被解析
     */
    private function inline(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        // 行内代码
        $s = preg_replace_callback('/`([^`]+)`/u', function ($m) {
            return '<code style="' . $this->theme->s('code') . '">' . $m[1] . '</code>';
        }, $s);

        // 图片 ![alt](url)
        $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/u', function ($m) {
            return '<img src="' . $m[2] . '" alt="' . $m[1] . '" style="'
                 . $this->theme->s('img') . '" />';
        }, $s);

        // 链接 [text](url)
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', function ($m) {
            return '<a href="' . $m[2] . '" style="' . $this->theme->s('a') . '">'
                 . $m[1] . '</a>';
        }, $s);

        // 粗体 **text** / __text__
        $s = preg_replace('/\*\*(.+?)\*\*/us', '<strong style="' . $this->theme->s('strong') . '">$1</strong>', $s);
        $s = preg_replace('/__(.+?)__/us', '<strong style="' . $this->theme->s('strong') . '">$1</strong>', $s);

        // 斜体 *text* / _text_
        $s = preg_replace('/\*(.+?)\*/us', '<em style="' . $this->theme->s('em') . '">$1</em>', $s);

        // 换行 → <br />
        $s = str_replace("\n", '<br />', $s);

        return $s;
    }
}
