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

$t0 = microtime(true);
$search = collect_pingma_search_top_zheng_ma($prepared, $sorted, $drawTs, 3);
$sec = round(microtime(true) - $t0, 3);
echo "耗时 {$sec}s 候选 {$search['searched']} 返回 " . count($search['plans']) . " 套\n";
foreach ($search['plans'] as $i => $p) {
    $ev = $p['eval'];
    echo ($i + 1) . ': ' . implode(',', $p['zheng'])
        . ' payout=' . ($ev['payout'] ?? 0)
        . ' net=' . ($ev['net_profit'] ?? 0) . "\n";
}
