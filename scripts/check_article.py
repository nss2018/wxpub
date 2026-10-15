#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
爆文流水线 · 第 3 步：发布前质检

用法：
  python3 check_article.py <文章.md>

退出码 0 = 可发布；1 = 有硬伤必须改。自检规则出自 references/viral-writing-guide.md 第 5 节。
"""
import re
import sys


def fail(msg):
    print("  [FAIL] " + msg)
    return 1


def warn(msg):
    print("  [WARN] " + msg)
    return 0


def main():
    if len(sys.argv) != 2:
        print(__doc__)
        sys.exit(2)
    path = sys.argv[1]
    try:
        with open(path, encoding="utf-8") as f:
            md = f.read()
    except OSError as e:
        print("无法读取：{}".format(e))
        sys.exit(2)

    print("质检：{}".format(path))
    err = 0

    # 1. 标题
    m = re.search(r"^#\s+(.+)$", md, re.M)
    title = m.group(1).strip() if m else ""
    if not title:
        err += fail("缺一级标题（# ）")
    elif "【" in title:
        err += fail("标题还是占位符：{}".format(title))
    elif len(title) > 32:
        err += fail("标题 {} 字，超微信 32 字上限".format(len(title)))
    else:
        print("  [OK] 标题（{}字）：{}".format(len(title), title))

    # 2. 占位符残留
    leftovers = re.findall(r"[【】]|TODO|待补|xxx", md)
    if leftovers:
        err += fail("残留占位符 {} 处（【】/TODO/待补/xxx）".format(len(leftovers)))
    else:
        print("  [OK] 无占位符残留")

    # 3. 中文字数
    cn = len(re.findall(r"[\u4e00-\u9fff]", md))
    if cn < 800:
        err += warn("中文字数 {}，偏短（建议 1200-2200）".format(cn))
    elif cn > 2500:
        err += warn("中文字数 {}，偏长（>2500 完读率崩）".format(cn))
    else:
        print("  [OK] 中文字数 {}".format(cn))

    # 4. 小节数
    secs = len(re.findall(r"^##\s+", md, re.M))
    if not 3 <= secs <= 6:
        err += warn("小节 {} 个（建议 3-6）".format(secs))
    else:
        print("  [OK] 小节 {} 个".format(secs))

    # 5. 代码块行数
    bad_block = False
    for i, block in enumerate(re.findall(r"```(.*?)```", md, re.S), 1):
        lines = block.count("\n")
        if lines > 25:
            err += fail("代码块 {} 有 {} 行，超 25 行上限".format(i, lines))
            bad_block = True
    if not bad_block:
        print("  [OK] 代码块行数合规")

    # 6. 导语（标题后第一段）
    after = md[m.end():].lstrip() if m else md
    quote = re.match(r"^>\s*(.+)$", after, re.M)
    if quote:
        lead = quote.group(1)
        if "【" in lead:
            err += warn("导语还是占位符")
        elif len(lead) > 60:
            err += warn("导语 {} 字，超 60 字（钩子要短）".format(len(lead)))
        else:
            print("  [OK] 导语（{}字）".format(len(lead)))
    else:
        err += warn("建议标题下加 > 导语钩子（≤60字）")

    # 7. END 不应手写
    if re.search(r"^\s*END\s*$", md, re.M):
        err += fail("END 标记由服务端自动生成，请删掉手写的")

    print("-" * 40)
    if err:
        print("结论：{} 处硬伤，改完再发布".format(err))
        sys.exit(1)
    print("结论：可发布 ✓  →  publish.sh {}".format(path))
    sys.exit(0)


if __name__ == "__main__":
    main()
