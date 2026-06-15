#!/bin/bash
# 一键提交并推送到 GitHub
# 用法：
#   bash deploy/git-push.sh
#   bash deploy/git-push.sh "修复开奖对照"
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MSG="${1:-更新 $(date '+%Y-%m-%d %H:%M:%S')}"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "错误：当前目录不是 git 仓库"
  exit 1
fi

echo "=== [1/4] 检查改动 ==="
git status -s
if [ -z "$(git status --porcelain)" ]; then
  echo "没有改动，直接推送..."
else
  echo "=== [2/4] git add ==="
  git add -A
  echo "=== [3/4] git commit ==="
  git -c user.name="${GIT_USER_NAME:-saas}" -c user.email="${GIT_USER_EMAIL:-saas@local}" \
    commit -m "$MSG"
fi

echo "=== [4/4] git push ==="
git push origin main
git log -1 --oneline
echo "=== 完成 ==="
