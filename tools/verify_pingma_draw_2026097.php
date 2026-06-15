<?php
/**
 * 验算平码全覆盖测试数据在 2026097 实际开奖 vs 穷举推荐最优的派彩。
 * php tools/verify_pingma_draw_2026097.php
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

require_once dirname(__DIR__) . '/ajax/pingma_bet_parser.php';
require_once dirname(__DIR__) . '/ajax/lhc_lookup.php';

$batch = file_get_contents(dirname(__DIR__) . '/doc/pingma_full_coverage_test.txt');
$parsed = pingma_parse_submit_text($batch);
$prepared = array();
foreach ($parsed['bets'] ?? array() as $bet) {
    $prepared[] = pingma_recalc_bet_amounts($bet);
}

$drawTs = strtotime('2026-06-09 21:30:00');
$actualZheng = array('07', '18', '29', '28', '37', '40');
$actualTeMa = '32';

function eval_all(array $prepared, array $zheng, string $teMa, int $drawTs): array
{
    $stake = 0.0;
    $payout = 0.0;
    $hits = 0;
    $byPlay = array();
    foreach ($prepared as $bet) {
        $calc = pingma_calc_bet_payout($bet, $zheng, $teMa, $drawTs);
        $stake += (float) $calc['stake'];
        $payout += (float) $calc['payout'];
        $hits += (int) $calc['hit_count'];
        $label = (string) ($bet['play_label'] ?? '');
        if (!isset($byPlay[$label])) {
            $byPlay[$label] = array('stake' => 0.0, 'payout' => 0.0, 'hits' => 0);
        }
        $byPlay[$label]['stake'] += (float) $calc['stake'];
        $byPlay[$label]['payout'] += (float) $calc['payout'];
        $byPlay[$label]['hits'] += (int) $calc['hit_count'];
    }
    return array(
        'stake' => round($stake, 2),
        'payout' => round($payout, 2),
        'net' => round($stake - $payout, 2),
        'hits' => $hits,
        'byPlay' => $byPlay,
    );
}

function print_summary(array $r, array $zheng, string $teMa, string $label): void
{
    echo "=== {$label} ===\n";
    echo '平码: ' . implode(',', $zheng) . "  特码: {$teMa}\n";
    echo "下注 {$r['stake']}  派彩 {$r['payout']}  盈利 {$r['net']}  中 {$r['hits']} 组\n";
    $by = $r['byPlay'];
    uasort($by, static function ($a, $b) {
        return $b['payout'] <=> $a['payout'];
    });
    foreach ($by as $name => $row) {
        if ($row['payout'] <= 0) {
            continue;
        }
        echo sprintf("  %-8s 下注%6.0f 派彩%7.0f 中%3d组\n", $name, $row['stake'], $row['payout'], $row['hits']);
    }
    echo "\n";
}

function exposure_weights(array $prepared, int $drawTs): array
{
    $w = array();
    for ($i = 1; $i <= 49; $i++) {
        $w[str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = 0.0;
    }
    foreach ($prepared as $bet) {
        $groups = pingma_bet_resolve_groups($bet);
        $odds = pingma_payout_odds_for_play_type((string) ($bet['play_type'] ?? ''));
        $unit = (float) ($bet['amount_per_group'] ?? 0) * $odds;
        if ($unit <= 0 || !$groups) {
            continue;
        }
        $stype = (string) ($bet['selection_type'] ?? 'number');
        foreach ($groups as $g) {
            if (!is_array($g) || !$g) {
                continue;
            }
            if ($stype === 'zodiac') {
                foreach ($g as $sx) {
                    $balls = lhc_numbers_for_shengxiao((string) $sx, $drawTs);
                    $share = $unit / max(1, count($balls));
                    foreach ($balls as $b) {
                        $n = str_pad((string) ((int) $b), 2, '0', STR_PAD_LEFT);
                        $w[$n] += $share;
                    }
                }
            } else {
                $share = $unit / count($g);
                foreach ($g as $num) {
                    $n = pingma_normalize_number_token((string) $num);
                    if ($n !== null) {
                        $w[$n] += $share;
                    }
                }
            }
        }
    }
    $sorted = array_keys($w);
    usort($sorted, static function ($a, $b) use ($w) {
        return ($w[$a] <=> $w[$b]) ?: strcmp($a, $b);
    });
    return array($w, $sorted);
}

function partition_bets(array $prepared): array
{
    $num = array();
    $zod = array();
    foreach ($prepared as $bet) {
        if ((string) ($bet['selection_type'] ?? 'number') === 'zodiac') {
            $zod[] = $bet;
        } else {
            $num[] = $bet;
        }
    }
    return array($num, $zod);
}

function fast_eval(array $numBets, array $zodBets, float $stake, array $zheng, int $drawTs): array
{
    $np = 0.0;
    foreach ($numBets as $bet) {
        $np += (float) pingma_calc_bet_payout($bet, $zheng, '', $drawTs)['payout'];
    }
    $zp = 0.0;
    foreach ($zodBets as $bet) {
        $zp += (float) pingma_calc_bet_payout($bet, $zheng, '', $drawTs)['payout'];
    }
    $payout = round($np + $zp, 2);
    return array('stake' => $stake, 'payout' => $payout, 'net' => round($stake - $payout, 2));
}

function foreach_c6(array $pool, callable $fn): int
{
    $n = count($pool);
    if ($n < 6) {
        return 0;
    }
    $idx = range(0, 5);
    $cnt = 0;
    while (true) {
        $combo = array();
        foreach ($idx as $i) {
            $combo[] = $pool[$i];
        }
        $fn($combo);
        $cnt++;
        $i = 5;
        while ($i >= 0 && $idx[$i] === $n - 6 + $i) {
            $i--;
        }
        if ($i < 0) {
            break;
        }
        $idx[$i]++;
        for ($j = $i + 1; $j < 6; $j++) {
            $idx[$j] = $idx[$j - 1] + 1;
        }
    }
    return $cnt;
}

echo '笔数 ' . count($prepared) . '  组数 ' . (int) ($parsed['total_groups'] ?? 0) . "\n\n";

$actual = eval_all($prepared, $actualZheng, $actualTeMa, $drawTs);
print_summary($actual, $actualZheng, $actualTeMa, '2026097 实际开奖（后台应对齐此数）');

list($weights, $sorted) = exposure_weights($prepared, $drawTs);
list($numBets, $zodBets) = partition_bets($prepared);
$stake = 0.0;
foreach ($prepared as $bet) {
    $stake += (float) ($bet['total_amount'] ?? 0);
}
$stake = round($stake, 2);

$pool = array_slice($sorted, 0, 22);
$best = null;
$bestZ = array();
$t0 = microtime(true);
$cnt = foreach_c6($pool, static function (array $z) use ($numBets, $zodBets, $stake, $drawTs, &$best, &$bestZ) {
    sort($z, SORT_STRING);
    $ev = fast_eval($numBets, $zodBets, $stake, $z, $drawTs);
    if ($best === null || $ev['payout'] < $best['payout']) {
        $best = $ev;
        $bestZ = $z;
    }
});
$sec = round(microtime(true) - $t0, 2);
echo "穷举 C(22,6)={$cnt} 耗时 {$sec}s\n";
$rec = eval_all($prepared, $bestZ, '', $drawTs);
print_summary($rec, $bestZ, '(肖类按6平码/配套特码不新增肖)', '穷举推荐最优');

echo "=== 差距 ===\n";
echo '实际比推荐多亏 ' . round($rec['net'] - $actual['net'], 2) . " 元\n";
echo '实际派彩比推荐多 ' . round($actual['payout'] - $rec['payout'], 2) . " 元\n\n";

echo "=== 实际开奖派彩 TOP10 ===\n";
$top = array();
foreach ($prepared as $i => $bet) {
    $c = pingma_calc_bet_payout($bet, $actualZheng, $actualTeMa, $drawTs);
    if ((float) $c['payout'] <= 0) {
        continue;
    }
    $top[] = array(
        'no' => $i + 1,
        'label' => (string) ($bet['play_label'] ?? ''),
        'payout' => (float) $c['payout'],
        'hits' => (int) $c['hit_count'],
        'disp' => (string) ($c['hit_groups_display'] ?? ''),
    );
}
usort($top, static function ($a, $b) {
    return $b['payout'] <=> $a['payout'];
});
foreach (array_slice($top, 0, 10) as $t) {
    echo sprintf("#%02d %-8s 派彩%7.0f 中%2d组 %s\n", $t['no'], $t['label'], $t['payout'], $t['hits'], $t['disp']);
}
