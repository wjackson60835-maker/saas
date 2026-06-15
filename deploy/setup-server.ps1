# 服务器首次检查：powershell -ExecutionPolicy Bypass -File deploy\setup-server.ps1
$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root

Write-Host "=== saas 服务器初始化检查 ==="

@("runtime\complile", "runtime\session", "runtime\config", "runtime\data") | ForEach-Object {
    if (-not (Test-Path $_)) { New-Item -ItemType Directory -Path $_ -Force | Out-Null }
}

@(
    @{ cfg = "config\database.php"; ex = "config\database.php.example" },
    @{ cfg = "config\collect.php"; ex = "config\collect.php.example" }
) | ForEach-Object {
    if (-not (Test-Path $_.cfg)) {
        if (Test-Path $_.ex) {
            Copy-Item $_.ex $_.cfg
            Write-Host "[!] 已从 $($_.ex) 生成 $($_.cfg) ，请编辑后重启站点"
        } else {
            Write-Host "[!] 缺少 $($_.cfg)"
        }
    } else {
        Write-Host "[ok] $($_.cfg)"
    }
}

if (Get-Command php -ErrorAction SilentlyContinue) {
    php -r "$ext=['mysqli','gd','json','mbstring','pdo_mysql']; foreach($ext as $e){ echo '['.(extension_loaded($e)?'ok':'!!').'] php ext: '.$e.PHP_EOL; }"
} else {
    Write-Host "[!!] 未找到 php 命令"
}

Write-Host "完成。日常更新请执行: deploy\update.ps1"
