<?php
/**
 * 样例批次 27 条：完整解析 + 指定开奖下的中奖组数与派彩（输出 Markdown 片段）。
 * 用法：php tools/calc_payout_sample_batch.php
 */
require_once dirname(__DIR__) . '/ajax/pingma_bet_parser.php';
require_once dirname(__DIR__) . '/ajax/lhc_lookup.php';

$lines = array(
    array(1, '复式三中三02.26.43.36.25各50'),
    array(2, '复式三中三02.26.43.36.25各组50'),
    array(3, '复复式三中三02.26.43.36.25.29各组50'),
    array(4, '复式三中三02.26.43.36.25.29.03各组50'),
    array(5, '二中二26-36 50元'),
    array(6, '二中二26-36 50圆'),
    array(7, '二中二26-36 50米'),
    array(8, '复式二中二26-36-10各50'),
    array(9, '复式二中二26-36-10-30各组50'),
    array(10, '复式二中二26-36-10-30-02各组50'),
    array(11, '复式二中二26-36-10-30-02-03各组50'),
    array(12, '复式二中二26-36-10-30-02-03-04各组50'),
    array(13, '复式二肖鼠牛虎各50'),
    array(14, '复式二肖鼠牛虎各组50'),
    array(15, '复式二肖鼠牛虎兔各组50'),
    array(16, '复式二肖鼠牛虎兔龙各组50'),
    array(17, '复式二肖鼠牛虎兔龙蛇各组50'),
    array(18, '复式二肖鼠牛虎兔龙蛇马各组50'),
    array(19, '复式三肖鼠牛虎兔各50'),
    array(20, '复式三肖鼠牛虎兔龙各组50'),
    array(21, '复式三肖鼠牛虎兔龙蛇各组50'),
    array(22, '复式三肖鼠牛虎兔龙蛇马各组50'),
    array(23, '复式四肖鼠牛虎兔龙各50'),
    array(24, '复式四肖鼠牛虎兔龙蛇各组50'),
    array(25, '复式四肖鼠牛虎兔龙蛇马各组50'),
    array(26, '复式五肖鼠牛虎兔龙蛇各50'),
    array(27, '复式五肖鼠牛虎兔龙蛇马各组50'),
);

$odds = array(
    'erzhonger' => 63,
    'sanzhongsan' => 705,
    'erxiao' => 3.1,
    'sanxiao' => 11,
    'sixiao' => 31,
    'wuxiao' => 108,
    'yixiao' => 1.1,
);

// 验算用开奖（2026 马年生肖表）
$zhengMa = array('02', '10', '25', '26', '36', '03');
$teMa = '43';
$drawTs = strtotime('2026-06-09 21:30:00');

$zhengSet = array_flip($zhengMa);
$zodiacHit = array();
foreach (array_merge($zhengMa, array($teMa)) as $ball) {
    $sx = lhc_shengxiao_for_number($ball, $drawTs);
    if ($sx !== '') {
        $zodiacHit[$sx] = true;
    }
}
$zodiacHitList = array_keys($zodiacHit);
sort($zodiacHitList);

function group_hits_number(array $group, array $zhengSet): bool
{
    foreach ($group as $num) {
        if (!isset($zhengSet[$num])) {
            return false;
        }
    }
    return true;
}

function group_hits_zodiac(array $group, array $zodiacHit): bool
{
    foreach ($group as $sx) {
        if (empty($zodiacHit[$sx])) {
            return false;
        }
    }
    return true;
}

function fmt_group(array $group, string $type): string
{
    if ($type === 'zodiac') {
        return implode('', $group);
    }
    return implode('-', $group);
}

$totalStake = 0.0;
$totalPayout = 0.0;
$rows = array();

foreach ($lines as $item) {
    list($no, $line) = $item;
    $parseLine = preg_replace('/^复复式/u', '复式', $line);
    $bet = pingma_parse_one_segment($parseLine);
    $bet = pingma_recalc_bet_amounts($bet);
    $pt = (string) $bet['play_type'];
    $mult = $odds[$pt] ?? 0;
    $per = (float) $bet['amount_per_group'];
    $groups = $bet['groups'] ?? array();
    $hitGroups = array();
    $stype = (string) ($bet['selection_type'] ?? 'number');
    foreach ($groups as $g) {
        $ok = $stype === 'zodiac'
            ? group_hits_zodiac($g, $zodiacHit)
            : group_hits_number($g, $zhengSet);
        if ($ok) {
            $hitGroups[] = $g;
        }
    }
    $hitCount = count($hitGroups);
    $stake = (float) $bet['total_amount'];
    $payout = $hitCount * $per * $mult;
    $totalStake += $stake;
    $totalPayout += $payout;
    $hitLabels = array();
    foreach ($hitGroups as $g) {
        $hitLabels[] = fmt_group($g, $stype);
    }
    $rows[] = array(
        'no' => $no,
        'line' => $line,
        'play' => $bet['play_label'],
        'selection' => pingma_format_selection($bet),
        'n' => count($bet['selection'] ?? array()),
        'group_count' => (int) $bet['group_count'],
        'per' => $per,
        'stake' => $stake,
        'odds' => $mult,
        'hit_count' => $hitCount,
        'payout' => $payout,
        'hit_detail' => $hitLabels,
        'all_groups' => $groups,
        'stype' => $stype,
    );
}

echo "## DRAW\n";
echo '平码: ' . implode(', ', $zhengMa) . "\n";
echo '特码: ' . $teMa . "\n";
echo '七球生肖集合: ' . implode('、', $zodiacHitList) . "\n\n";

foreach ($zhengMa as $b) {
    echo $b . '→' . lhc_shengxiao_for_number($b, $drawTs) . ' ';
}
echo '| ' . $teMa . '→' . lhc_shengxiao_for_number($teMa, $drawTs) . "\n\n";

echo "## SUMMARY stake={$totalStake} payout={$totalPayout} net=" . ($totalStake - $totalPayout) . "\n\n";

foreach ($rows as $r) {
    echo '### #' . $r['no'] . ' ' . $r['line'] . "\n";
    echo '- play: ' . $r['play'] . ' | selection: ' . $r['selection'] . "\n";
    echo '- groups: ' . $r['group_count'] . ' | per: ' . $r['per'] . ' | stake: ' . $r['stake'] . "\n";
    echo '- odds: ' . $r['odds'] . ' | hits: ' . $r['hit_count'] . ' | payout: ' . $r['payout'] . "\n";
    if ($r['hit_detail']) {
        echo '- hit: ' . implode(', ', $r['hit_detail']) . "\n";
    }
    echo "\n";
}
