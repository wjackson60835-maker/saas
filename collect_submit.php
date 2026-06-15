<?php
/**
 * 数据收集提交页（PHP 包装）
 *
 * 部署在子目录时，静态打开 collect_submit.html 可能仍把接口指错路径导致 nginx 404。
 * 请优先访问本文件：与 collect_submit.html 同目录，例如
 *   https://你的域名/collect_submit.php
 *   https://你的域名/子目录/collect_submit.php
 *
 * 会在 <head> 内注入 window.__COLLECT_API_BASE__，与 collect_submit.html 内脚本配合。
 */
declare(strict_types=1);

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/collect_submit.php'));
$dir = rtrim(dirname($scriptName), '/');
if ($dir === '' || $dir === '.' || $dir === '/') {
    $apiBase = '/ajax/collect/api.php';
} else {
    $apiBase = $dir . '/ajax/collect/api.php';
}

$htmlFile = __DIR__ . DIRECTORY_SEPARATOR . 'collect_submit.html';
if (!is_readable($htmlFile)) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'collect_submit.html not found next to collect_submit.php';
    exit;
}

$html = file_get_contents($htmlFile);
if ($html === false) {
    header('HTTP/1.1 500 Internal Server Error');
    exit;
}

$inject = '<script>window.__COLLECT_API_BASE__=' . json_encode($apiBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
$html = preg_replace('/<head>/i', '<head>' . "\n  " . $inject, $html, 1);

header('Content-Type: text/html; charset=UTF-8');
echo $html;
