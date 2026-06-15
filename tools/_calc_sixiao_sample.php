<?php
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $e = 'UTF-8') { return preg_match_all('/./us', $s, $m) ? count($m[0]) : 0; }
    function mb_substr($s, $st, $l = null, $e = 'UTF-8') {
        preg_match_all('/./us', $s, $m);
        $c = $m[0];
        if ($l === null) $l = count($c) - $st;
        return implode('', array_slice($c, $st, $l));
    }
}
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

$lines = [
    '平四肖鼠牛虎兔50元',
    '复式四肖鼠牛虎兔龙各50',
    '复式四肖鼠牛虎兔龙蛇各组50',
    '复式四肖鼠牛虎兔龙蛇马各组50',
];

$grand = 0.0;
echo "=== 四肖样例逐行 ===\n";
foreach ($lines as $i => $line) {
    $n = $i + 1;
    echo "第{$n}行: {$line}\n";
    $p = pingma_parse_submit_text($line);
    foreach ($p['bets'] as $bet) {
        echo '  ' . ($bet['display_text'] ?? '') . "\n";
        echo '  玩法=' . ($bet['play_label'] ?? '') . ' n=' . count($bet['selection'] ?? [])
            . ' C(n,k)=' . ($bet['group_count'] ?? 0) . '组×' . ($bet['amount_per_group'] ?? 0) . '=' . ($bet['total_amount'] ?? 0) . "元\n";
    }
    echo "  小计: {$p['total_amount']} 元\n\n";
    $grand += $p['total_amount'];
}

echo "=== 逐行合计 ===\n";
echo "总金额: {$grand} 元\n\n";

$p = pingma_parse_submit_text(implode("\n", $lines));
echo "=== 整单一次提交 ===\n";
echo "合计: {$p['total_amount']} 元 / {$p['total_bets']} 笔 / {$p['total_groups']} 组\n";
foreach ($p['bets'] as $bet) {
    echo ($bet['display_text'] ?? '') . "\n";
}
