<?php
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $e = 'UTF-8') { return preg_match_all('/./us', $s, $m) ? count($m[0]) : 0; }
    function mb_substr($s, $st, $l = null, $e = 'UTF-8') {
        preg_match_all('/./us', $s, $m); $c = $m[0];
        if ($l === null) $l = count($c) - $st;
        return implode('', array_slice($c, $st, $l));
    }
}
define('COLLECT_API_SKIP_DISPATCH', true);
require_once dirname(__DIR__) . '/ajax/pingma_bet_parser.php';
require_once dirname(__DIR__) . '/ajax/lhc_lookup.php';
require_once dirname(__DIR__) . '/ajax/collect/api.php';

$parsed = pingma_parse_submit_text(file_get_contents(dirname(__DIR__) . '/doc/pingma_full_coverage_test.txt'));
$prepared = array_map('pingma_recalc_bet_amounts', $parsed['bets'] ?? array());
$drawTs = strtotime('2026-06-09 21:30:00');
$exposure = collect_pingma_compute_ball_exposure_weights($prepared, $drawTs);
$sorted = collect_pingma_exposure_sorted_balls($exposure);
$stake = collect_pingma_stake_sum($prepared);
$target = round($stake * 0.7, 2);

$t0 = microtime(true);
$search = collect_pingma_algorithm_search_best_zheng_ma($prepared, $sorted, $drawTs);
$sec = round(microtime(true) - $t0, 3);
$ev = $search['eval'] ?? array();
$net = (float) ($ev['net_profit'] ?? 0);
$rate = $stake > 0 ? round(100 * $net / $stake, 2) : 0;
echo "耗时 {$sec}s  验算 {$search['searched']} 次\n";
echo '平码: ' . implode(',', $search['zheng'] ?? array()) . "\n";
echo "下注 {$stake} 派彩 " . ($ev['payout'] ?? 0) . " 盈利 {$net} 盈利率 {$rate}% 目标70%=" . ($net >= $target ? 'YES' : 'no') . "\n";
