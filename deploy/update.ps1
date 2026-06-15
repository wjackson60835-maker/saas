# Windows 服务器上一键更新：powershell -ExecutionPolicy Bypass -File deploy\update.ps1
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root

Write-Host "[1/3] git pull ..."
git pull --ff-only

Write-Host "[2/3] 检查本地配置 ..."
@("config\database.php", "config\collect.php") | ForEach-Object {
    if (-not (Test-Path $_)) {
        Write-Host "  缺少 $_ ，请从 *.example 复制并填写"
    }
}

Write-Host "[3/3] 清理模板编译缓存 ..."
$compile = Join-Path $Root "runtime\complile"
if (Test-Path $compile) {
    Get-ChildItem $compile -Filter "*.php" -ErrorAction SilentlyContinue | Remove-Item -Force
}

Write-Host ("更新完成：" + (Get-Date -Format "yyyy-MM-dd HH:mm:ss"))
