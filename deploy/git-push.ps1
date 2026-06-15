# 一键提交并推送到 GitHub
# 用法：
#   powershell -ExecutionPolicy Bypass -File deploy\git-push.ps1
#   powershell -ExecutionPolicy Bypass -File deploy\git-push.ps1 "修复开奖对照"
param(
    [string]$Message = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root

if (-not $Message) {
    $Message = "更新 $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
}

if (-not (Test-Path ".git")) {
    Write-Host "错误：当前目录不是 git 仓库"
    exit 1
}

Write-Host "=== [1/4] 检查改动 ==="
git status -s

$dirty = git status --porcelain
if (-not $dirty) {
    Write-Host "没有改动，直接推送..."
} else {
    Write-Host "=== [2/4] git add ==="
    git add -A
    Write-Host "=== [3/4] git commit ==="
    $userName = if ($env:GIT_USER_NAME) { $env:GIT_USER_NAME } else { "saas" }
    $userEmail = if ($env:GIT_USER_EMAIL) { $env:GIT_USER_EMAIL } else { "saas@local" }
    git -c "user.name=$userName" -c "user.email=$userEmail" commit -m $Message
}

Write-Host "=== [4/4] git push ==="
git push origin main
git log -1 --oneline
Write-Host "=== 完成 ==="
