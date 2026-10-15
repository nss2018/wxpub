#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
爆文流水线 · 第 1 步：选题生成文章骨架

用法：
  python3 new_article.py "选题关键词或一句话" [--type A|B|C] [--outdir 目录]

生成 <日期>-<话题>.md 骨架，占位符用【】包裹。写完后必须删光所有【】占位符
（check_article.py 会卡住含占位符的文章）。

骨架类型（详见 references/viral-writing-guide.md）：
  A 事故切入（默认）   B 反共识   C 悬念倒叙
"""
import argparse
import datetime
import os
import re
import sys

SKELETONS = {
    "A": """# 【标题：数字+损失，≤32字】

> 【导语钩子：事故现场一句话，≤60字】

## 【第一节：事故现场】
【时间/指标/损失数字，画面感】

## 【第二节：两次误判】
【自查过程，第一次转折：以为的原因是错的】

## 【第三节：真因反转】
【真凶藏在哪里 + 关键证据】

## 【第四节：解法】
【技术点作为解法出场，代码 ≤25 行，讲完回到故事】

## 【复盘：三个行动清单】
1. 【】
2. 【】
3. 【】
""",
    "B": """# 【标题：反常识断言，≤32字】

> 【导语钩子：否定共识一句话，≤60字】

## 【第一节：大家都这么做】
【共识做法是什么，为什么看起来合理】

## 【第二节：证据否定】
【数据/事故，第二次转折：证据链】

## 【第三节：为什么一直没发现】
【掩盖机制：日志/监控/默认值里的盲区】

## 【第四节：正确做法】
【技术点出场，代码 ≤25 行，迁移成本评估】

## 【复盘：三个行动清单】
1. 【】
2. 【】
3. 【】
""",
    "C": """# 【标题：悬念缺口，≤32字】

> 【导语钩子：最糟时刻的画面，≤60字】

## 【第一节：结果先曝光】
【最糟的时刻：时间/指标/损失，倒叙起点】

## 【第二节：以为的原因，全错】
【两次误判，埋第一次转折】

## 【第三节：真正的凶手】
【反转：真凶藏在哪里 + 关键证据】

## 【第四节：解法】
【技术点作为解法出场，代码 ≤25 行】

## 【复盘：三个决策错误】
1. 【】
2. 【】
3. 【】
""",
}


def slugify(topic: str) -> str:
    s = re.sub(r"\s+", "-", topic.strip())
    s = re.sub(r"[^\w\u4e00-\u9fff-]", "", s)
    return s[:24] or "untitled"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("topic", help="选题关键词或一句话")
    ap.add_argument("--type", default="A", choices=list(SKELETONS), help="骨架类型，默认 A 事故切入")
    ap.add_argument("--outdir", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "drafts"))
    args = ap.parse_args()

    os.makedirs(args.outdir, exist_ok=True)
    path = os.path.join(args.outdir, "{}-{}.md".format(
        datetime.date.today().strftime("%Y%m%d"), slugify(args.topic)))
    if os.path.exists(path):
        print("[ABORT] 已存在：{}".format(path))
        sys.exit(1)

    skeleton = SKELETONS[args.type]
    # 把选题注入所有占位符头部，写作时围绕选题展开
    skeleton = skeleton.replace("【标题：", "【标题｜选题：{}。".format(args.topic), 1)
    with open(path, "w", encoding="utf-8") as f:
        f.write(skeleton)

    print("骨架已生成：{}".format(path))
    print("类型：{}（{}）".format(args.type, {"A": "事故切入", "B": "反共识", "C": "悬念倒叙"}[args.type]))
    print("下一步：AI 按references/viral-writing-guide.md 写初稿 → 润色 → python3 check_article.py {} → publish.sh".format(path))


if __name__ == "__main__":
    main()
