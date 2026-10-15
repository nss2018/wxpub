#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""把「贴图」工作稿（.newspic.md，遵循 templates/newspic-format.md 填空模板）
转成服务器 newspic 发布接口消费的 JSON。

用法：
  python3 newspic_md2json.py <稿.newspic.md> [--out /tmp/x.json]

输出 JSON：
  {
    "title":   "≤20字痛点标题",
    "content": "≤1000字单段描述",
    "digest":  "首句摘要(≤120字，可选)",
    "storyboard": [
      {"slide":1,"role":"cover","big":"...","sub":"...","foot":"...","image_hint":"..."},
      ...
    ]
  }

wxpub.php 的 action=newspic 消费此 JSON：逐页渲染 1080×1440 PNG → 上传 → draft/add(newspic)。
"""
import json
import re
import sys
import argparse


def parse(src):
    text = open(src, encoding="utf-8").read()
    lines = text.splitlines()

    title = ""
    m = re.search(r"^#\s*贴图[:：]\s*(.+)$", text, re.M)
    if m:
        title = m.group(1).strip()
    else:
        m2 = re.search(r"^#\s+(.+)$", text, re.M)
        if m2:
            title = m2.group(1).strip()

    # ---- 描述（> 描述块，到 ## 分镜 为止）----
    content_lines = []
    in_desc = False
    for ln in lines:
        s = ln.strip()
        if s.startswith("##"):
            in_desc = False
            continue
        if s.startswith(">"):
            if not in_desc:
                # 进入描述块；首行可能是「> 描述（...）：」标签，跳过标签本身
                in_desc = True
                label = s.lstrip(">").strip()
                if label.startswith("描述"):
                    continue
                content_lines.append(label)
            else:
                content_lines.append(s.lstrip(">").strip())
    content = "\n".join(x for x in content_lines if x != "").strip()

    # ---- 分镜 ----
    storyboard = []
    pat = re.compile(
        r"^-\s*\[\s*(\d+)\s+(\w+)\s*\]\s*"
        r"大字[:：]\s*(.+?)\s*"
        r"[｜|]\s*小字[:：]\s*(.*?)\s*"
        r"[｜|]\s*脚注[:：]\s*(.*?)"
        r"(?:\s*[｜|]\s*图[:：]\s*(.*))?$"
    )
    for ln in lines:
        mm = pat.match(ln.strip())
        if not mm:
            continue
        slide = int(mm.group(1))
        role = mm.group(2)
        big = mm.group(3).strip()
        sub = mm.group(4).strip()
        foot = mm.group(5).strip()
        hint = (mm.group(6) or "").strip()
        storyboard.append({
            "slide": slide,
            "role": role,
            "big": big,
            "sub": sub,
            "foot": foot,
            "image_hint": hint,
        })
    storyboard.sort(key=lambda x: x["slide"])

    digest = content_lines[0][:120] if content_lines else ""
    return {
        "title": title,
        "content": content,
        "digest": digest,
        "storyboard": storyboard,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("source", help=".newspic.md 工作稿路径")
    ap.add_argument("--out", default="/tmp/newspic.json", help="输出 JSON 路径")
    args = ap.parse_args()

    data = parse(args.source)
    if not data["storyboard"]:
        print("⚠️ 未解析到分镜，请检查 .newspic.md 格式", file=sys.stderr)
        sys.exit(1)
    with open(args.out, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    print("✅ 已生成：%s" % args.out)
    print("   标题：%s" % data["title"])
    print("   描述字数：%d" % len(data["content"]))
    print("   分镜页数：%d" % len(data["storyboard"]))


if __name__ == "__main__":
    main()
