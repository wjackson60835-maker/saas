<?php
/**
 * 平码派彩计算单元验算（对照 doc/collect_pingma_payout_odds.md §6 开奖）。
 * 用法：php tools/test_pingma_payout_calc.php
 */
require_once dirname(__DIR__) . '/ajax/lhc_lookup.php';
require_once dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

$zhengMa = array('02', '10', '25', '26', '36', '03');
$teMa = '43';
$ts = strtotime('2026-06-09 21:30:00');

$bet = pingma_parse_one_segment('复式三中三02.26.43.36.25各50');
$calc = pingma_calc_bet_payout($bet, $zhengMa, $teMa, $ts);
assert($calc['hit_count'] === 4, 'hit_count should be 4');
assert(abs($calc['payout'] - 141000) < 0.01, 'payout should be 141000');

$bet2 = pingma_parse_one_segment('二中二26-36 50元');
$calc2 = pingma_calc_bet_payout($bet2, $zhengMa, $teMa, $ts);
assert($calc2['hit_count'] === 1, 'erzhonger hit 1');
assert(abs($calc2['payout'] - 3150) < 0.01, 'payout 3150');

$bet3 = array(
    'play_type' => 'erxiao',
    'play_label' => '二肖',
    'selection_type' => 'zodiac',
    'selection' => array('鼠', '牛', '虎', '兔', '龙'),
    'groups' => array(array('鼠', '龙')),
    'group_count' => 10,
    'amount_per_group' => 50,
    'total_amount' => 500,
    'amount_mode' => 'per_group',
);
$calc3 = pingma_calc_bet_payout($bet3, $zhengMa, $teMa, $ts);
assert($calc3['hit_count'] === 1, 'erxiao hit 1');
assert(abs($calc3['payout'] - 155) < 0.01, 'payout 155');

echo "OK pingma payout calc\n";
