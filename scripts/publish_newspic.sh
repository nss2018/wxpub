#!/usr/bin/env bash
# 贴图(newspic)一键发布：.newspic.md -> JSON -> 服务器渲染9图 -> 草稿箱
#
# 用法：
#   ./publish_newspic.sh <稿.newspic.md> [账号] [主题]
#   账号：company(默认) | gaopo
#   主题：terminal(默认) | neon | blackgold | magma | cobalt | violet
#
# 流程：newspic_md2json.py 转 JSON -> push.py 上传 -> wxpub.php?action=newspic
#       服务器逐页渲染 1080×1440 PNG -> 上传拿 media_id -> draft/add(newspic)
# 仅进草稿箱，不群发；高坡个人号草稿箱文章需后台手动群发。
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
REMOTE="/www/wxpub/_np_$(date +%s).json"

[ -f "$MD" ] || { echo "文件不存在：$MD"; exit 2; }

echo "[1/3] .newspic.md -> JSON ..."
python3 "${SCRIPT_DIR}/newspic_md2json.py" "$MD" --out /tmp/_np.json

echo "[2/3] 上传 JSON 到服务器 ..."
python3 "${SCRIPT_DIR}/push.py" put /tmp/_np.json "$REMOTE"

echo "[3/3] 渲染 9 图并发布到 ${ACC} 草稿箱 (theme ${THEME}) ..."
curl -sk -X POST "${API}?k=${K}" \
  -d 'action=newspic' \
  -d "md=${REMOTE}" \
  -d "account=${ACC}" \
  -d "theme=${THEME}"

echo
echo "完成。请到公众号后台草稿箱确认 9 图贴图。"
