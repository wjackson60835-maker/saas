#!/bin/bash
# 服务器上一键更新：bash deploy/update.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "[1/3] git pull ..."
git pull --ff-only

echo "[2/3] 检查本地配置 ..."
for f in config/database.php config/collect.php; do
  if [ ! -f "$f" ]; then
    echo "  缺少 $f ，请从 *.example 复制并填写后再访问站点"
  fi
done

echo "[3/3] 清理模板编译缓存 ..."
if [ -d runtime/complile ]; then
  rm -f runtime/complile/*.php 2>/dev/null || true
fi

echo "更新完成：$(date '+%Y-%m-%d %H:%M:%S')"
