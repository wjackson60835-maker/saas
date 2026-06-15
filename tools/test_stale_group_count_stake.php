<?php
/**
 * 模拟 DB 陈旧 group_count：派彩下注合计应与真实 40 元一致，而非 1280。
 */
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

// 4 注平特一肖，各 10 元；DB 误存 group_count=32（合计被算成 1280）
$bets = array();
foreach (array('马', '鸡', '羊', '狗') as $sx) {
    $bets[] = array(
        'play_type' => 'yixiao',
        'play_label' => '平特一肖',
        'selection_type' => 'zodiac',
        'selection' => array($sx),
        'groups' => array(array($sx)),
        'group_count' => 32,
        'amount_per_group' => 10.0,
        'total_amount' => 320.0,
        'amount_mode' => 'per_group',
    );
}

$stake = 0.0;
$groups = 0;
$zhengMa = array('13', '22', '19', '45', '12', '32');
$teMa = '33';
foreach ($bets as $bet) {
    $calc = pingma_calc_bet_payout($bet, $zhengMa, $teMa, time());
    $stake += (float) $calc['stake'];
    $groups += (int) $calc['group_count'];
}

$ok = abs($stake - 40.0) < 0.01 && $groups === 4;
echo ($ok ? 'OK' : 'FAIL') . " stake={$stake} groups={$groups} (expect 40 / 4)\n";
exit($ok ? 0 : 1);
