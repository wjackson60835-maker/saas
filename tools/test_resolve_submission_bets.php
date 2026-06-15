<?php
/**
 * 模拟：submission.total_amount=40，bets 表错误 1280，应回退 parsed_json/原文。
 */
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

$sub = array(
    'id' => 1,
    'period_no' => '2026001',
    'created_at' => '2026-01-01 00:00:00',
    'raw_text' => '平特一肖马各10\n平特一肖鸡各10\n平特一肖羊各10\n平特一肖狗各10',
    'total_amount' => 40.0,
    'total_items' => 4,
    'key_name' => 'test',
    'parsed_json' => json_encode(array(
        'ball_scope' => 'pingma',
        'original_raw_text' => "平特一肖马各10\n平特一肖鸡各10\n平特一肖羊各10\n平特一肖狗各10",
        'bets' => array(
            array('play_type' => 'yixiao', 'play_label' => '平特一肖', 'selection_type' => 'zodiac',
                'selection' => array('马'), 'group_count' => 1, 'amount_per_group' => 10, 'total_amount' => 10, 'amount_mode' => 'per_group', 'groups' => array(array('马'))),
            array('play_type' => 'yixiao', 'play_label' => '平特一肖', 'selection_type' => 'zodiac',
                'selection' => array('鸡'), 'group_count' => 1, 'amount_per_group' => 10, 'total_amount' => 10, 'amount_mode' => 'per_group', 'groups' => array(array('鸡'))),
            array('play_type' => 'yixiao', 'play_label' => '平特一肖', 'selection_type' => 'zodiac',
                'selection' => array('羊'), 'group_count' => 1, 'amount_per_group' => 10, 'total_amount' => 10, 'amount_mode' => 'per_group', 'groups' => array(array('羊'))),
            array('play_type' => 'yixiao', 'play_label' => '平特一肖', 'selection_type' => 'zodiac',
                'selection' => array('狗'), 'group_count' => 1, 'amount_per_group' => 10, 'total_amount' => 10, 'amount_mode' => 'per_group', 'groups' => array(array('狗'))),
        ),
    ), JSON_UNESCAPED_UNICODE),
);

$wrongTable = array();
for ($i = 0; $i < 9; $i++) {
    $wrongTable[] = array(
        'id' => $i + 1,
        'submission_id' => 1,
        'play_type' => 'yixiao',
        'play_label' => '平特一肖',
        'selection_type' => 'zodiac',
        'selection_json' => '["马"]',
        'groups_json' => '[["马"]]',
        'group_count' => 32,
        'amount_per_group' => 10,
        'total_amount' => 320,
        'amount_mode' => 'per_group',
        'raw_segment' => '',
        'period_no' => '2026001',
        'created_at' => '2026-01-01',
        'key_name' => 'test',
    );
}

require dirname(__DIR__) . '/ajax/collect/api.php';

$resolved = collect_pingma_resolve_submission_bet_rows(
    new PDO('sqlite::memory:'),
    $sub,
    $wrongTable
);

$stake = collect_pingma_sum_rows_stake($resolved);
$ok = abs($stake - 40.0) < 0.01 && count($resolved) === 4;
echo ($ok ? 'OK' : 'FAIL') . " stake={$stake} rows=" . count($resolved) . " (expect 40 / 4)\n";
exit($ok ? 0 : 1);
