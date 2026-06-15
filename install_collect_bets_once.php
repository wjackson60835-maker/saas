<?php
/**
 * 一次性安装平码明细表 collect_submission_bets
 * 浏览器访问：/install_collect_bets_once.php
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

$cfgFile = __DIR__ . '/config/database.php';
if (!is_readable($cfgFile)) {
    echo "config/database.php not found\n";
    exit(1);
}
$cfg = require $cfgFile;
$db = $cfg['database'] ?? array();
$host = $db['host'] ?? '127.0.0.1';
$user = $db['user'] ?? 'root';
$pass = $db['passwd'] ?? '';
$port = (int) ($db['port'] ?? 3306);
$dbname = $db['dbname'] ?? '';

$sqlFile = __DIR__ . '/doc/collect_submission_bets.sql';
if (!is_readable($sqlFile)) {
    echo "doc/collect_submission_bets.sql not found\n";
    exit(1);
}

try {
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $user, $pass, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ));
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException('read sql failed');
    }
    $pdo->exec($sql);
    echo "OK: collect_submission_bets installed in database {$dbname}\n";
} catch (Throwable $e) {
    echo 'FAIL: ' . $e->getMessage() . "\n";
    exit(1);
}
