<?php
/**
 * 自检：与本站 core/basic/Config.php 前几步一致地合并惯性配置 + config.php + database.php，
 * 用于确认「你改的 database.php」是否会被读到、生效后的 dbname/host 是什么。
 *
 * 使用方法：上传到网站根目录（与 core、config、index.php 同级），浏览器访问一次看输出，
 *           看完后立刻删除本文件（勿长期留在公网）。
 *
 * （可选）访问：check_database_config_merge.php?key=一串随机密钥
 */
declare(strict_types=1);

$SECRET = 'CHANGE_ME'; // 使用前改成随机串，访问时 ?k=相同内容
if (!isset($_GET['k']) || !hash_equals($SECRET, (string) $_GET['k'])) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=utf-8');
    exit("403\n请编辑本文件设置 \$SECRET，浏览器访问 check_database_config_merge.php?k=你的SECRET\n看完输出后删除本文件。\n");
}

// 与本文件同级即为站点根（与 index.php、config 同级）；若报错请把本脚本移到根目录后再访问
$root = __DIR__;
if (!is_readable($root . '/core/function/handle.php')) {
    exit("ROOT wrong (need core/function/handle.php under same dir): {$root}");
}
require $root . '/core/function/handle.php';

if (!function_exists('mult_array_merge')) {
    exit('mult_array_merge missing');
}

$coreConv = $root . '/core/convention.php';
if (!is_readable($coreConv)) {
    exit('missing core/convention.php');
}
$configs = require $coreConv;

$cfgMain = $root . '/config/config.php';
if (is_readable($cfgMain)) {
    $configs = mult_array_merge($configs, require $cfgMain);
}

$dbPhp = $root . '/config/database.php';
if (!is_readable($dbPhp)) {
    exit('missing config/database.php — 程序会使用惯性里的默认库名等');
}
$configs = mult_array_merge($configs, require $dbPhp);

header('Content-Type: text/plain; charset=utf-8');
echo "--- merged database (before runtime md5 caches) ---\n\n";
echo 'config/database.php realpath: ' . realpath($dbPhp) . "\n\n";
if (!isset($configs['database'])) {
    echo "ERROR: merged config has NO [database] key. Check database.php MUST return:\n";
    echo "return array('database'=>array(...));\n";
    exit;
}
print_r($configs['database']);
echo "\n--- If dbname/host here are NOT what you set, compare path above with your editor path. ---\n";
