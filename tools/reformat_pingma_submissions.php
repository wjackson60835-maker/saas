<?php
/**
 * 批量格式化已有平码提交：更新 raw_text、parsed_json.formatted_text、bets 表 raw_segment
 *
 * CLI: php tools/reformat_pingma_submissions.php
 * Web: /tools/reformat_pingma_submissions.php?run=1
 */
declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    if (!isset($_GET['run']) || $_GET['run'] !== '1') {
        echo '<p>平码提交格式化工具</p><p><a href="?run=1">点击执行</a></p>';
        exit;
    }
}

require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

function reformat_bootstrap_pdo(): PDO
{
    $cfgFile = dirname(__DIR__) . '/config/database.php';
    if (!is_readable($cfgFile)) {
        throw new RuntimeException('config/database.php not found');
    }
    $cfg = require $cfgFile;
    $db = $cfg['database'] ?? array();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $db['host'] ?? '127.0.0.1',
        (int) ($db['port'] ?? 3306),
        $db['dbname'] ?? ''
    );
    return new PDO($dsn, (string) ($db['user'] ?? 'root'), (string) ($db['passwd'] ?? ''), array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
}

function reformat_out(string $msg): void
{
    global $isCli;
    if ($isCli) {
        echo $msg . PHP_EOL;
        return;
    }
    echo nl2br(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'));
}

function reformat_build_display(?array $decoded, string $rawFallback): array
{
    if (is_array($decoded)) {
        $src = trim((string) ($decoded['original_raw_text'] ?? ''));
        if ($src !== '' && strpos($src, ' · ') === false) {
            try {
                $parsed = pingma_parse_submit_text($src);
                return array(
                    'formatted_text' => $parsed['formatted_text'],
                    'bets' => $parsed['bets'],
                    'total_amount' => $parsed['total_amount'],
                );
            } catch (Throwable $e) {
            }
        }
    }
    $bets = array();
    if (is_array($decoded) && !empty($decoded['bets']) && is_array($decoded['bets'])) {
        $bets = $decoded['bets'];
    }
    if (!$bets && trim($rawFallback) !== '') {
        $parsed = pingma_parse_submit_text($rawFallback);
        $bets = $parsed['bets'] ?? array();
    }
    if (!$bets) {
        $cached = is_array($decoded) ? trim((string) ($decoded['formatted_text'] ?? '')) : '';
        return array(
            'formatted_text' => $cached,
            'bets' => array(),
            'total_amount' => 0.0,
        );
    }
    $bets = pingma_attach_display_fields($bets);
    return array(
        'formatted_text' => pingma_format_bets_text($bets),
        'bets' => $bets,
        'total_amount' => pingma_sum_bets_total($bets),
    );
}

function reformat_bets_table_exists(PDO $pdo): bool
{
    $stmt = $pdo->query("SHOW TABLES LIKE 'collect_submission_bets'");
    return (bool) $stmt->fetch();
}

try {
    $pdo = reformat_bootstrap_pdo();
} catch (Throwable $e) {
    reformat_out('数据库连接失败：' . $e->getMessage());
    exit(1);
}

$hasBets = reformat_bets_table_exists($pdo);
$all = $pdo->query('SELECT id, raw_text, parsed_json FROM collect_submissions ORDER BY id ASC')->fetchAll();
$rows = array_values(array_filter($all, static function ($row) {
    if (empty($row['parsed_json'])) {
        return false;
    }
    $decoded = json_decode((string) $row['parsed_json'], true);
    return is_array($decoded) && ($decoded['ball_scope'] ?? '') === 'pingma';
}));

$updated = 0;
$skipped = 0;
$failed = 0;

reformat_out('待处理平码提交：' . count($rows) . ' 条');

foreach ($rows as $row) {
    $id = (int) ($row['id'] ?? 0);
    $raw = (string) ($row['raw_text'] ?? '');
    $decoded = json_decode((string) ($row['parsed_json'] ?? ''), true);
    if (!is_array($decoded)) {
        $decoded = array('ball_scope' => 'pingma');
    }
    try {
        $display = reformat_build_display($decoded, $raw);
        if ($display['formatted_text'] === '') {
            $skipped++;
            reformat_out("#{$id} 跳过（无法解析）");
            continue;
        }
        $decoded['bets'] = $display['bets'];
        $decoded['formatted_text'] = $display['formatted_text'];
        $decoded['ball_scope'] = 'pingma';
        if (!isset($decoded['original_raw_text']) || trim((string) $decoded['original_raw_text']) === '') {
            $decoded['original_raw_text'] = $raw;
        }
        $calcTotal = isset($display['total_amount']) ? (float) $display['total_amount'] : pingma_sum_bets_total($display['bets']);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'UPDATE collect_submissions SET raw_text = :raw_text, parsed_json = :parsed_json, total_amount = :total_amount WHERE id = :id'
        );
        $stmt->execute(array(
            ':id' => $id,
            ':raw_text' => $display['formatted_text'],
            ':parsed_json' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
            ':total_amount' => $calcTotal,
        ));

        if ($hasBets && $display['bets']) {
            $betStmt = $pdo->prepare(
                'UPDATE collect_submission_bets SET raw_segment = :raw_segment
                 WHERE submission_id = :sid AND sort_order = :ord'
            );
            foreach ($display['bets'] as $i => $bet) {
                if (!is_array($bet)) {
                    continue;
                }
                $betStmt->execute(array(
                    ':sid' => $id,
                    ':ord' => (int) $i,
                    ':raw_segment' => (string) ($bet['display_text'] ?? pingma_format_bet_line($bet)),
                ));
            }
        }
        $pdo->commit();
        $updated++;
        reformat_out("#{$id} OK\n" . $display['formatted_text']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failed++;
        reformat_out("#{$id} 失败：" . $e->getMessage());
    }
}

reformat_out("完成：更新 {$updated} 条，跳过 {$skipped} 条，失败 {$failed} 条。");
