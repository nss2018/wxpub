#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
瓦特影评 · 微信草稿转换器
把 wtxt 源稿 Markdown 转成微信编辑器吃的内联样式 HTML（熔岩红·编辑风），
并生成一张 PIL 封面。输出：
  - <out>.styled.html   正文 HTML（含 base64 注入串供 bsk 使用）
  - <out>.cover.png     封面（900x500，熔岩红底白字）
用法：
  python gen_wtxt.py <源稿.md> [--out /tmp/the-substance]
"""
import sys, os, re, base64, argparse

BODY_STYLE = ("font-size:15px;line-height:1.95;color:#3a3a3a;"
              "letter-spacing:0.5px;text-align:justify;margin:1.2em 0;")
LEDE_STYLE = "font-style:italic;color:#8b2500;"
H2_STYLE = ("font-size:17px;font-weight:700;color:#8b2500;"
            "border-left:4px solid #d4451e;padding-left:12px;"
            "margin:1.6em 0 0.8em 0;")


def esc(t: str) -> str:
    return (t.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
            .replace('"', "&quot;"))


def md_to_styled_html(md: str):
    lines = md.splitlines()
    title = ""
    blocks = []  # (kind, text)  kind: lede / h2 / p
    i = 0
    first_para_done = False
    # strip leading blanks
    while i < len(lines) and not lines[i].strip():
        i += 1
    if i < len(lines) and lines[i].startswith("# "):
        title = lines[i][2:].strip()
        i += 1
    buf = []
    for line in lines[i:]:
        s = line.strip()
        if not s:
            if buf:
                blocks.append(("p", " ".join(buf)))
                buf = []
            continue
        if s.startswith("## "):
            if buf:
                blocks.append(("p", " ".join(buf)))
                buf = []
            blocks.append(("h2", s[3:].strip()))
        else:
            buf.append(s)
    if buf:
        blocks.append(("p", " ".join(buf)))
    # 第一段 p 作为导语（italic）
    html_parts = []
    first_p = True
    for kind, text in blocks:
        if kind == "h2":
            html_parts.append(f'<h2 style="{H2_STYLE}">{esc(text)}</h2>')
        elif kind == "p":
            if first_p and not first_para_done:
                html_parts.append(
                    f'<p style="{BODY_STYLE}"><span style="{LEDE_STYLE}">{esc(text)}</span></p>')
                first_para_done = True
            else:
                html_parts.append(f'<p style="{BODY_STYLE}">{esc(text)}</p>')
            first_p = False
    return title, "\n".join(html_parts)


def make_cover(title: str, out_png: str):
    try:
        from PIL import Image, ImageDraw, ImageFont
    except Exception as e:
        print(f"[cover] skip, Pillow unavailable: {e}")
        return None
    W, H = 900, 500
    img = Image.new("RGB", (W, H), (139, 37, 0))  # #8b2500
    draw = ImageDraw.Draw(img)
    # 暗角渐变（简单双矩形）
    draw.rectangle([0, 0, W, H], fill=(139, 37, 0))
    draw.rectangle([0, H - 120, W, H], fill=(212, 69, 30))  # #d4451e 底条
    # 字体（优先苹方，回退默认）
    font_title = None
    font_sub = None
    for cand in ["/System/Library/Fonts/PingFang.ttc",
                 "/System/Library/Fonts/STHeiti Light.ttc",
                 "/Library/Fonts/Arial Unicode.ttf"]:
        if os.path.exists(cand):
            try:
                font_title = ImageFont.truetype(cand, 46)
                font_sub = ImageFont.truetype(cand, 30)
                break
            except Exception:
                continue
    if font_title is None:
        font_title = ImageFont.load_default()
        font_sub = ImageFont.load_default()
    # 标题：取《片名》部分，过长折行
    label = title
    # 简单按字数折行（每行<=13字）
    maxlen = 13
    lines = [label[j:j + maxlen] for j in range(0, len(label), maxlen)]
    y = 150
    for ln in lines[:3]:
        draw.text((70, y), ln, fill=(255, 255, 255), font=font_title)
        y += 64
    draw.text((70, H - 95), "瓦特影评 · 每周拆一部全球新片", fill=(255, 255, 255), font=font_sub)
    img.save(out_png)
    return out_png


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("src")
    ap.add_argument("--out", default=None)
    args = ap.parse_args()
    src = args.src
    base = args.out or (os.path.splitext(src)[0] + ".wtxt")
    md = open(src, encoding="utf-8").read()
    title, html = md_to_styled_html(md)
    html_path = base + ".styled.html"
    open(html_path, "w", encoding="utf-8").write(html)
    b64 = base64.b64encode(html.encode("utf-8")).decode()
    open(base + ".b64.txt", "w", encoding="utf-8").write(b64)
    cover = make_cover(title, base + ".cover.png")
    print(f"[ok] title = {title}")
    print(f"[ok] html  -> {html_path}  ({len(html)} chars)")
    print(f"[ok] b64   -> {base}.b64.txt ({len(b64)} chars)")
    if cover:
        print(f"[ok] cover -> {cover}")
    print(f"[inject] bsk evaluate 用以下 JS（解码后 paste 进最后一个 .ProseMirror）：")
    print(f'VAR_B64={b64[:40]}... (完整见 .b64.txt)')


if __name__ == "__main__":
    main()
