# 一键提交并推送到 GitHub（提交说明根据实际改动自动生成）
#
# 用法：
#   powershell -ExecutionPolicy Bypass -File deploy\git-push.ps1
#   powershell -ExecutionPolicy Bypass -File deploy\git-push.ps1 -Interactive
#   powershell -ExecutionPolicy Bypass -File deploy\git-push.ps1 -Message "手动说明"
#   powershell -ExecutionPolicy Bypass -File deploy\git-push.ps1 -Yes
param(
    [string]$Message = "",
    [switch]$Interactive,
    [switch]$Yes
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root

function Get-AutoCommitMessage {
    $lines = git status --porcelain 2>$null
    if (-not $lines) {
        return "同步 $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
    }

    $items = @()
    foreach ($line in $lines) {
        if (-not $line) { continue }
        $code = $line.Substring(0, [Math]::Min(2, $line.Length)).Trim()
        $file = $line.Substring(3).Trim().Trim('"')
        $base = Split-Path -Leaf $file
        $verb = switch -Regex ($code) {
            '^\?\?' { '新增'; break }
            '^A'    { '新增'; break }
            '^D'    { '删除'; break }
            '^R'    { '重命名'; break }
            default { '修改' }
        }
        $items += "${base}(${verb})"
    }

    $n = $items.Count
    $max = 8
    $preview = ($items | Select-Object -First $max) -join ', '
    if ($n -gt $max) {
        return "更新 ${preview} 等 ${n} 个文件"
    }
    return "更新 ${preview}"
}

function Build-FinalMessage([string]$Auto, [string]$Manual) {
    if ($Manual) {
        if ($Auto -and $Auto -ne $Manual) {
            return "${Manual}（${Auto}）"
        }
        return $Manual
    }
    return $Auto
}

if (-not (Test-Path ".git")) {
    Write-Host "错误：当前目录不是 git 仓库"
    exit 1
}

$branch = git rev-parse --abbrev-ref HEAD 2>$null
if (-not $branch) { $branch = "main" }

Write-Host "=== [1/5] 检查改动 ==="
git status -s

$dirty = git status --porcelain
if (-not $dirty) {
    Write-Host "没有本地改动，直接推送 origin/${branch} ..."
} else {
    $autoMsg = Get-AutoCommitMessage
    $msg = Build-FinalMessage $autoMsg $Message

    if ($Interactive) {
        $input = Read-Host "提交说明 [$msg]"
        if ($input) { $msg = $input }
    }

    Write-Host ""
    Write-Host "将使用提交说明："
    Write-Host "  $msg"
    Write-Host ""

    if (-not $Yes) {
        $confirm = Read-Host "确认提交并推送? [Y/n]"
        if ($confirm -match '^(n|N|no|NO)$') {
            Write-Host "已取消"
            exit 0
        }
    }

    Write-Host "=== [2/5] git add ==="
    git add -A

    Write-Host "=== [3/5] git commit ==="
    $userName = if ($env:GIT_USER_NAME) { $env:GIT_USER_NAME } else { "saas" }
    $userEmail = if ($env:GIT_USER_EMAIL) { $env:GIT_USER_EMAIL } else { "saas@local" }
    git -c "user.name=$userName" -c "user.email=$userEmail" commit -m $msg
}

Write-Host "=== [4/5] git push origin ${branch} ==="
git push origin $branch

Write-Host "=== [5/5] 最新提交 ==="
git log -1 --oneline
Write-Host "=== 完成 ==="
