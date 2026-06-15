<?php
/**
 * 验算推荐开奖中奖明细（与用户截图玩法一致）。
 * php tools/verify_recommend_hits_sample.php
 */
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $e = 'UTF-8')
    {
        return preg_match_all('/./us', $s, $m) ? count($m[0]) : 0;
    }
    function mb_substr($s, $st, $l = null, $e = 'UTF-8')
    {
        preg_match_all('/./us', $s, $m);
        $c = $m[0];
        if ($l === null) {
            $l = count($c) - $st;
        }
        return implode('', array_slice($c, $st, $l));
    }
}
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';
require dirname(__DIR__) . '/ajax/lhc_lookup.php';

$lines = array(
    '一肖鼠各组50',
    '一肖牛各组50',
    '一肖虎各组50',
    '一肖兔各组50',
    '一肖龙各组50',
    '一肖蛇各组50',
    '一肖马各组50',
    '一肖羊各组50',
    '一肖猴各组50',
    '一肖鸡各组50',
    '一肖狗各组50',
    '一肖猪各组50',
    '复式二肖鼠牛虎兔龙蛇马各组50',
    '复式二肖羊猴鸡狗猪各组50',
    '复式三肖鼠牛虎兔龙蛇马各组50',
    '复式三肖羊猴鸡狗猪各组50',
    '复式四肖鼠牛虎兔龙蛇马各组50',
    '复式四肖羊猴鸡狗猪各组50',
    '复式五肖鼠牛虎兔龙蛇马各组50',
    '复式五肖羊猴鸡狗猪各组50',
);

$parsed = pingma_parse_submit_text(implode("\n", $lines));
$bets = $parsed['bets'];
$drawTs = time();

// 推荐平码需覆盖截图中的中奖肖：鼠牛 + 羊鸡狗（6 平码、不含特码）
// 示例：选各肖代表号（按当年生肖表，仅用于验算逻辑）
$candidateDraws = array(
    array('07', '18', '30', '34', '45', '48'), // 鼠牛羊鸡狗 + 猪
    array('19', '22', '30', '34', '45', '48'),
);

foreach ($candidateDraws as $zheng) {
    echo "=== 试算平码: " . implode(',', $zheng) . " ===\n";
    $zods = array_keys(pingma_draw_zodiac_hit_set($zheng, '', $drawTs));
    sort($zods);
    echo '六平码肖: ' . implode('、', $zods) . "\n";
    $totalPayout = 0.0;
    $hitGroups = 0;
    $hitBets = 0;
    foreach ($bets as $bet) {
        $calc = pingma_calc_bet_payout($bet, $zheng, '', $drawTs);
        if ((int) $calc['hit_count'] > 0) {
            $hitBets++;
            $hitGroups += (int) $calc['hit_count'];
            $totalPayout += (float) $calc['payout'];
            echo sprintf(
                "  %s | n=%d 中%d组 [%s] 球号[%s] 派彩=%s (每组%s×赔率%s)\n",
                $bet['play_label'],
                count($bet['selection']),
                $calc['hit_count'],
                $calc['hit_groups_display'],
                $calc['hit_groups_numbers_display'],
                $calc['payout'],
                $calc['amount_per_group'],
                $calc['odds']
            );
        }
    }
    echo "命中笔数: {$hitBets}  命中组数: {$hitGroups}  派彩合计: {$totalPayout}\n";
    echo "下注合计: {$parsed['total_amount']}  庄家盈亏: " . round($parsed['total_amount'] - $totalPayout, 2) . "\n\n";
}

// 截图中单行验算
echo "=== 截图逐行公式验算 ===\n";
$checks = array(
    array('三肖', 1, 50, 11, 550, '羊鸡狗'),
    array('二肖', 3, 50, 3.1, 465, '鸡狗、羊狗、羊鸡'),
    array('一肖', 3, 50, 1.1, 165, '羊、鸡、狗'),
    array('二肖', 1, 50, 3.1, 155, '鼠牛'),
    array('一肖', 2, 50, 1.1, 110, '鼠、牛'),
);
foreach ($checks as $c) {
    $calc = round($c[1] * $c[2] * $c[3], 2);
    $ok = abs($calc - $c[4]) < 0.01 ? 'OK' : 'ERR';
    echo "{$ok} {$c[0]} 中{$c[1]}组×{$c[2]}×{$c[3]}={$calc} (期望{$c[4]}) [{$c[5]}]\n";
}
