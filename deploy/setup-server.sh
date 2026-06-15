#!/bin/bash
# 服务器首次检查：bash deploy/setup-server.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "=== saas 服务器初始化检查 ==="

mkdir -p runtime/complile runtime/session runtime/config runtime/data
chmod -R 775 runtime 2>/dev/null || true

for f in config/database.php config/collect.php; do
  if [ ! -f "$f" ]; then
    ex="${f}.example"
    if [ -f "$ex" ]; then
      cp "$ex" "$f"
      echo "[!] 已从 $ex 生成 $f ，请编辑后重启站点"
    else
      echo "[!] 缺少 $f"
    fi
  else
    echo "[ok] $f"
  fi
done

if command -v php >/dev/null 2>&1; then
  php -r "
    \$ext = ['mysqli','gd','json','mbstring','pdo_mysql'];
    foreach (\$ext as \$e) {
      echo '[' . (extension_loaded(\$e) ? 'ok' : '!!') . '] php ext: ' . \$e . PHP_EOL;
    }
  "
else
  echo "[!!] 未找到 php 命令"
fi

echo "完成。日常更新请执行: bash deploy/update.sh"
