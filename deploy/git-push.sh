#!/bin/bash
# 一键提交并推送到 GitHub（提交说明根据实际改动自动生成）
#
# 用法：
#   bash deploy/git-push.sh              # 自动根据改动生成说明
#   bash deploy/git-push.sh -i           # 自动生成后，可编辑说明
#   bash deploy/git-push.sh "手动说明"   # 用手动说明（仍附带改动摘要）
#   bash deploy/git-push.sh -y           # 不确认，直接提交
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

INTERACTIVE=0
SKIP_CONFIRM=0
MANUAL_MSG=""

while [ $# -gt 0 ]; do
  case "$1" in
    -i|--interactive) INTERACTIVE=1; shift ;;
    -y|--yes) SKIP_CONFIRM=1; shift ;;
    -h|--help)
      sed -n '2,8p' "$0"
      exit 0
      ;;
    *)
      MANUAL_MSG="$1"
      shift
      ;;
  esac
done

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "错误：当前目录不是 git 仓库"
  exit 1
fi

# 根据 git status 自动生成提交说明
generate_commit_message() {
  local items=()
  local line code file verb base
  while IFS= read -r line; do
    [ -z "$line" ] && continue
    code="${line:0:2}"
    file="${line:3}"
    file="${file#\"}"
    file="${file%\"}"
    case "$code" in
      "??") verb="新增" ;;
      " A"|"A ") verb="新增" ;;
      " D"|"D ") verb="删除" ;;
      " R"|"R ") verb="重命名" ;;
      *) verb="修改" ;;
    esac
    base="${file##*/}"
    items+=("${base}(${verb})")
  done < <(git status --porcelain 2>/dev/null || true)

  local n=${#items[@]}
  if [ "$n" -eq 0 ]; then
    echo "同步 $(date '+%Y-%m-%d %H:%M:%S')"
    return
  fi

  local preview=""
  local i=0
  local max=8
  for item in "${items[@]}"; do
    if [ "$i" -lt "$max" ]; then
      if [ -n "$preview" ]; then
        preview+=", "
      fi
      preview+="$item"
    fi
    i=$((i + 1))
  done

  if [ "$n" -gt "$max" ]; then
    echo "更新 ${preview} 等 ${n} 个文件"
  else
    echo "更新 ${preview}"
  fi
}

build_final_message() {
  local auto="$1"
  local manual="$2"
  if [ -n "$manual" ]; then
    if [ -n "$auto" ] && [ "$auto" != "$manual" ]; then
      echo "${manual}（${auto}）"
    else
      echo "$manual"
    fi
  else
    echo "$auto"
  fi
}

BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)"

echo "=== [1/5] 检查改动 ==="
git status -s

if [ -z "$(git status --porcelain)" ]; then
  echo "没有本地改动，直接推送 origin/${BRANCH} ..."
  MSG=""
else
  AUTO_MSG="$(generate_commit_message)"
  MSG="$(build_final_message "$AUTO_MSG" "$MANUAL_MSG")"

  if [ "$INTERACTIVE" -eq 1 ]; then
    read -r -p "提交说明 [${MSG}]: " INPUT || true
    if [ -n "${INPUT:-}" ]; then
      MSG="$INPUT"
    fi
  fi

  echo ""
  echo "将使用提交说明："
  echo "  ${MSG}"
  echo ""

  if [ "$SKIP_CONFIRM" -eq 0 ]; then
    read -r -p "确认提交并推送? [Y/n]: " CONFIRM || true
    case "${CONFIRM:-Y}" in
      n|N|no|NO) echo "已取消"; exit 0 ;;
    esac
  fi

  echo "=== [2/5] git add ==="
  git add -A

  echo "=== [3/5] git commit ==="
  git -c user.name="${GIT_USER_NAME:-saas}" \
      -c user.email="${GIT_USER_EMAIL:-saas@local}" \
      commit -m "$MSG"
fi

echo "=== [4/5] git push origin ${BRANCH} ==="
git push origin "$BRANCH"

echo "=== [5/5] 最新提交 ==="
git log -1 --oneline
echo "=== 完成 ==="
