<?php
if (!function_exists('mb_strlen')) {
    function mb_strlen($str, $encoding = 'UTF-8') { return preg_match_all('/./us', (string) $str, $m) ? count($m[0]) : 0; }
    function mb_substr($str, $start, $length = null, $encoding = 'UTF-8') {
        preg_match_all('/./us', (string) $str, $m);
        $chars = $m[0];
        if ($length === null) $length = count($chars) - $start;
        return implode('', array_slice($chars, $start, $length));
    }
}
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

$lines = [
    '复式三中三02.26.43.36.25各50',
    '复式三中三02.26.43.36.25各组50',
    '复复式三中三02.26.43.36.25.29各组50',
    '复式三中三02.26.43.36.25.29.03各组50',
    '三中三 40、35、22/50',
    '复式五连肖鼠牛虎兔龙蛇各50',
    '复式五连肖鼠牛虎兔龙蛇马各组50',
];

$grand = 0.0;
$grandGroups = 0;
$grandBets = 0;

foreach ($lines as $i => $line) {
    $n = $i + 1;
    echo "=== 第{$n}行 ===\n";
    echo "原文: {$line}\n";
    try {
        $p = pingma_parse_submit_text($line);
        foreach ($p['bets'] as $bet) {
            echo '  ' . ($bet['display_text'] ?? '') . "\n";
        }
        echo "  小计: {$p['total_amount']} 元 / {$p['total_bets']} 笔 / {$p['total_groups']} 组\n\n";
        $grand += $p['total_amount'];
        $grandGroups += $p['total_groups'];
        $grandBets += $p['total_bets'];
    } catch (Throwable $e) {
        echo "  解析失败: " . $e->getMessage() . "\n\n";
    }
}

echo "=== 合计 ===\n";
echo "总金额: {$grand} 元\n";
echo "总笔数: {$grandBets}\n";
echo "总组数: {$grandGroups}\n";

// 整单一次提交
echo "\n=== 整单一次提交 ===\n";
$batch = implode("\n", $lines);
try {
    $p = pingma_parse_submit_text($batch);
    echo "合计: {$p['total_amount']} 元 / {$p['total_bets']} 笔 / {$p['total_groups']} 组\n";
    foreach ($p['bets'] as $bet) {
        echo ($bet['display_text'] ?? '') . "\n";
    }
} catch (Throwable $e) {
    echo "整单失败: " . $e->getMessage() . "\n";
}
