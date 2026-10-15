#!/usr/bin/env bash
# 爆文流水线 · 第 4 步：一键发布（自动封面海报 + END 标记由服务端生成）
#
# 用法：
#   ./publish.sh <文章.md> [账号] [主题]
#   账号：company(默认) | gaopo
#   主题：terminal(默认) | neon | blackgold | magma | cobalt | violet
#
# 前置：先跑 check_article.py 通过，否则浪费微信草稿额度
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if [ -f "${SCRIPT_DIR}/wxpub.env" ]; then
  source "${SCRIPT_DIR}/wxpub.env"
fi
API="${WXPUB_API:?请设置 WXPUB_API，例如 https://你的服务器IP或域名/wxpub/wxpub.php}"
K="${WXPUB_TOKEN:?请设置 WXPUB_TOKEN（与服务器 config.php 中的 WXPUB_TOKEN 一致）}"

MD="$1"
ACC="${2:-company}"
THEME="${3:-terminal}"
REMOTE="/www/wxpub/_draft.md"

# push.py 与本脚本同目录（技能目录布局）或上一级（wxpub 项目布局）
if [ -f "${SCRIPT_DIR}/push.py" ]; then
  PUSH_PY="${SCRIPT_DIR}/push.py"
else
  PUSH_PY="${SCRIPT_DIR}/../push.py"
fi

[ -f "$MD" ] || { echo "文件不存在：$MD"; exit 2; }

echo "[1/3] 上传 $(basename "$MD") ..."
python3 "${PUSH_PY}" put "$MD" "$REMOTE"

echo "[2/3] 发布到 ${ACC}（主题 ${THEME}，自动海报+END）..."
curl -sk -X POST "${API}?k=${K}" \
  -d 'action=publish' \
  -d "md=${REMOTE}" \
  -d "account=${ACC}" \
  -d "theme=${THEME}"

echo
echo "[3/3] 完成。请到公众号后台草稿箱确认封面与排版。"
