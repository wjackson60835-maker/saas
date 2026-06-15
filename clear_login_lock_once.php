<?php
/**
 * PbootCMS 后台：清除「登录失败次数过多」IP 锁定（不经框架）。
 *
 * 锁定数据在：runtime/data/9e77657ecbc72afa6aafe227957ebfd4.php
 * （文件名 = md5('login_black')）
 *
 * 用法：
 *   1. 修改 $SECRET 为随机串；上传到网站根目录（与 runtime 同级）。
 *   2. 访问：https://你的域名/clear_login_lock_once.php?k=你的SECRET
 *   3. 成功后立刻删除本文件。
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

$SECRET = 'CHANGE_ME_TO_RANDOM_STRING_BEFORE_UPLOAD';
$k = isset($_GET['k']) ? (string) $_GET['k'] : '';
if ($SECRET === 'CHANGE_ME_TO_RANDOM_STRING_BEFORE_UPLOAD' || $SECRET === '' || !hash_equals($SECRET, $k)) {
    http_response_code(403);
    exit("403\n请编辑本文件设置 \$SECRET，访问 ?k= 相同值；用毕删除本文件。\n");
}

$lockFile = __DIR__ . '/runtime/data/' . md5('login_black') . '.php';
if (!is_file($lockFile)) {
    exit("no_lock_file: {$lockFile}\n（没有锁定文件则无需处理，可直接再试登录）\n");
}
if (!@unlink($lockFile)) {
    http_response_code(500);
    exit("unlink_failed: {$lockFile}\n请检查该路径的写权限，或用 FTP/面板手工删除此文件。\n");
}
exit("ok: 已清除登录锁定，请立即删除 clear_login_lock_once.php，然后重试后台登录。\n");
