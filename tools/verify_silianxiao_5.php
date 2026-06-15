<?php
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $e = 'UTF-8') { return preg_match_all('/./us', $s, $m) ? count($m[0]) : 0; }
    function mb_substr($s, $st, $l = null, $e = 'UTF-8') {
        preg_match_all('/./us', $s, $m); $c = $m[0];
        if ($l === null) $l = count($c) - $st;
        return implode('', array_slice($c, $st, $l));
    }
}
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

echo "=== 复式四连肖 5肖 组数验算 ===\n\n";

$text = '复式四连肖羊猴鸡狗猪各组50';
$r = pingma_parse_submit_text($text);
$b = $r['bets'][0];
$n = count($b['selection']);
$k = pingma_zodiac_group_k('四连肖', $n);
$expect = pingma_nCr($n, $k);

echo "原文: {$text}\n";
echo "选肖 n={$n}: " . implode('', $b['selection']) . "\n";
echo "玩法: {$b['play_label']}\n";
echo "每组肖数 k: {$k}（四连肖 → k=4）\n";
echo "公式: C({$n},{$k}) = {$expect}\n";
echo "解析 group_count: {$b['group_count']}\n";
echo "groups 条数: " . count($b['groups']) . "\n";
echo "金额: {$b['group_count']}组 × {$b['amount_per_group']} = {$b['total_amount']}元\n\n";

echo "展开 5 组:\n";
foreach ($b['groups'] as $i => $g) {
    echo '  ' . ($i + 1) . '. ' . implode('', $g) . "\n";
}

$ok = ($b['group_count'] === 5 && count($b['groups']) === 5 && $k === 4);
echo "\n结论: " . ($ok ? '正确（5组，不是10组）' : '错误') . "\n\n";

// 模拟历史错误入库后 refresh
echo "=== 历史错误 groups_json（C(5,2)=10组）刷新后 ===\n";
$wrongGroups = pingma_combinations($b['selection'], 2);
$stale = array(
    'play_type' => 'sixiao',
    'play_label' => '四连肖',
    'selection_type' => 'zodiac',
    'selection' => $b['selection'],
    'groups' => $wrongGroups,
    'group_count' => 10,
    'amount_per_group' => 50,
    'total_amount' => 500,
    'amount_mode' => 'per_group',
);
$refreshed = pingma_refresh_zodiac_bet_groups($stale);
echo "旧 group_count=10 → 刷新后={$refreshed['group_count']}, total={$refreshed['total_amount']}元\n";
echo "刷新结论: " . ($refreshed['group_count'] === 5 ? '正确' : '错误') . "\n";
