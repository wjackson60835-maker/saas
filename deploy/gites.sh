#!/bin/bash
# 服务器首次/强制同步 GitHub 代码
# 用法：
#   公开仓库：bash deploy/gites.sh
#   私有仓库：GITHUB_TOKEN=ghp_你的token bash deploy/gites.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

REPO="https://github.com/wjackson60835-maker/saas.git"
GITHUB_USER="${GITHUB_USER:-wjackson60835-maker}"

echo "=== saas 同步开始 ==="
echo "目录: $ROOT"

cp -a config/database.php /tmp/saas_database.php.bak 2>/dev/null || true
cp -a config/collect.php /tmp/saas_collect.php.bak 2>/dev/null || true

if [ ! -d .git ]; then
  git init
fi

git remote remove origin 2>/dev/null || true

if [ -n "${GITHUB_TOKEN:-}" ]; then
  echo "使用 Token 拉取（私有仓库）"
  git remote add origin "https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/wjackson60835-maker/saas.git"
else
  echo "使用公开地址拉取（仓库须为 Public，或已配置 SSH）"
  git remote add origin "$REPO"
fi

if ! git fetch origin; then
  echo ""
  echo "[失败] git fetch 403/认证失败，请任选一种："
  echo "  1) GitHub 仓库 Settings -> 改为 Public，再执行: bash deploy/gites.sh"
  echo "  2) 生成 classic token (ghp_开头，勾选 repo)，执行:"
  echo "     GITHUB_TOKEN=ghp_xxx bash deploy/gites.sh"
  echo "  3) 不要用已撤销的 github_pat_ 或发到聊天里的 token"
  exit 1
fi

git checkout -B main
git reset --hard origin/main

git remote set-url origin "$REPO"

cp -a /tmp/saas_database.php.bak config/database.php 2>/dev/null \
  || cp -n config/database.php.example config/database.php 2>/dev/null || true
cp -a /tmp/saas_collect.php.bak config/collect.php 2>/dev/null \
  || cp -n config/collect.php.example config/collect.php 2>/dev/null || true

mkdir -p runtime/complile runtime/session runtime/config runtime/data
chmod -R 775 runtime 2>/dev/null || true
rm -f runtime/complile/*.php 2>/dev/null || true

echo "=== 同步完成 $(date '+%F %T') ==="
echo "以后更新: cd $ROOT && git pull && bash deploy/update.sh"
