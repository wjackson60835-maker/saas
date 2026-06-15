<?php
/**
 * 客户原文（无空格）批量验算 — 断言回归
 * 期望合计 1700 元 / 10 笔 / 36 组
 */
if (!function_exists('mb_strlen')) {
    function mb_strlen($str, $encoding = 'UTF-8')
    {
        return preg_match_all('/./us', (string) $str, $m) ? count($m[0]) : 0;
    }
    function mb_substr($str, $start, $length = null, $encoding = 'UTF-8')
    {
        preg_match_all('/./us', (string) $str, $m);
        $chars = $m[0];
        if ($length === null) {
            $length = count($chars) - $start;
        }
        return implode('', array_slice($chars, $start, $length));
    }
}
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

$customerBatch = <<<'TEXT'
复式三中三08-17-45-28-各20元
三中三01-02-14-20元
三连肖猴猪马100元复式三连肖马狗蛇猴各组100元
二中二05-10-50元
二中二05-10-50元
复式二中二08-18-25各50元
四连肖马猴猪狗100元复式四连肖马猪狗猴龙各组50元
五连肖马狗蛇猴猪50元
TEXT;

$lineExpect = array(
    array('amount' => 80.0, 'bets' => 1, 'groups' => 4),
    array('amount' => 20.0, 'bets' => 1, 'groups' => 1),
    array('amount' => 700.0, 'bets' => 2, 'groups' => 9),
    array('amount' => 50.0, 'bets' => 1, 'groups' => 1),
    array('amount' => 50.0, 'bets' => 1, 'groups' => 1),
    array('amount' => 150.0, 'bets' => 1, 'groups' => 3),
    array('amount' => 600.0, 'bets' => 2, 'groups' => 16),
    array('amount' => 50.0, 'bets' => 1, 'groups' => 1),
);

$betExpect = array(
    array('play' => '三中三', 'groups' => 4, 'per' => 20.0, 'total' => 80.0, 'mode' => 'per_group'),
    array('play' => '三中三', 'groups' => 1, 'per' => 20.0, 'total' => 20.0, 'mode' => 'per_group'),
    array('play' => '三连肖', 'groups' => 3, 'per' => 33.33, 'total' => 100.0, 'mode' => 'flat_total'),
    array('play' => '三连肖', 'groups' => 6, 'per' => 100.0, 'total' => 600.0, 'mode' => 'per_group'),
    array('play' => '二中二', 'groups' => 1, 'per' => 50.0, 'total' => 50.0, 'mode' => 'per_group'),
    array('play' => '二中二', 'groups' => 1, 'per' => 50.0, 'total' => 50.0, 'mode' => 'per_group'),
    array('play' => '二中二', 'groups' => 3, 'per' => 50.0, 'total' => 150.0, 'mode' => 'per_group'),
    array('play' => '四连肖', 'groups' => 6, 'per' => 16.67, 'total' => 100.0, 'mode' => 'flat_total'),
    array('play' => '四连肖', 'groups' => 10, 'per' => 50.0, 'total' => 500.0, 'mode' => 'per_group'),
    array('play' => '五连肖', 'groups' => 1, 'per' => 50.0, 'total' => 50.0, 'mode' => 'flat_total'),
);

function assert_near(float $a, float $b, string $msg): void
{
    if (abs($a - $b) > 0.02) {
        throw new RuntimeException($msg . " (got {$a}, want {$b})");
    }
}

function verify_bet_amounts(array $bet, int $idx): void
{
    $gc = (int) ($bet['group_count'] ?? 0);
    $mode = (string) ($bet['amount_mode'] ?? '');
    $total = (float) ($bet['total_amount'] ?? 0);
    $per = (float) ($bet['amount_per_group'] ?? 0);
    if ($mode === 'per_group') {
        assert_near(round($per * $gc, 2), $total, "bet#{$idx} 按组金额不一致");
    } elseif ($mode === 'flat_total') {
        if ($total <= 0) {
            throw new RuntimeException("bet#{$idx} 整单金额须为正");
        }
    }
    $recalc = pingma_recalc_bet_amounts($bet);
    assert_near((float) $recalc['total_amount'], $total, "bet#{$idx} recalc 不一致");
}

$failed = 0;
$lines = preg_split('/\R/u', trim($customerBatch));

echo "=== 客户批量断言验算 ===\n";

foreach ($lines as $i => $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $n = $i + 1;
    $exp = $lineExpect[$i] ?? null;
    try {
        $r = pingma_parse_submit_text($line);
        if (!$exp) {
            throw new RuntimeException('缺少期望配置');
        }
        assert_near((float) $r['total_amount'], $exp['amount'], "第{$n}行合计");
        if ((int) $r['total_bets'] !== $exp['bets']) {
            throw new RuntimeException("第{$n}行笔数 got {$r['total_bets']} want {$exp['bets']}");
        }
        if ((int) $r['total_groups'] !== $exp['groups']) {
            throw new RuntimeException("第{$n}行组数 got {$r['total_groups']} want {$exp['groups']}");
        }
        foreach ($r['bets'] as $bi => $bet) {
            verify_bet_amounts($bet, $n * 10 + $bi);
        }
        echo "OK 第{$n}行 => {$r['total_amount']}元\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL 第{$n}行: {$e->getMessage()}\n";
    }
}

try {
    $all = pingma_parse_submit_text(trim($customerBatch));
    assert_near((float) $all['total_amount'], 1700.0, '整单合计');
    if ((int) $all['total_bets'] !== 10) {
        throw new RuntimeException('整单笔数应为10');
    }
    if ((int) $all['total_groups'] !== 36) {
        throw new RuntimeException('整单组数应为36');
    }
    assert_near(pingma_sum_bets_total($all['bets']), 1700.0, 'sum_bets_total');
    if (count($all['bets']) !== count($betExpect)) {
        throw new RuntimeException('明细笔数与期望不符');
    }
    foreach ($all['bets'] as $i => $bet) {
        $e = $betExpect[$i];
        if (($bet['play_label'] ?? '') !== $e['play']) {
            throw new RuntimeException('bet#' . ($i + 1) . ' 玩法 got ' . ($bet['play_label'] ?? '') . ' want ' . $e['play']);
        }
        if ((int) $bet['group_count'] !== $e['groups']) {
            throw new RuntimeException('bet#' . ($i + 1) . ' 组数不符');
        }
        assert_near((float) $bet['total_amount'], $e['total'], 'bet#' . ($i + 1) . ' 总价');
        if (($bet['amount_mode'] ?? '') !== $e['mode']) {
            throw new RuntimeException('bet#' . ($i + 1) . ' 计费模式不符');
        }
        if ($e['mode'] === 'per_group') {
            assert_near((float) $bet['amount_per_group'], $e['per'], 'bet#' . ($i + 1) . ' 每组单价');
        }
        verify_bet_amounts($bet, $i + 1);
    }
    echo 'OK 整单8行 => 1700元 / 10笔 / ' . (int) $all['total_groups'] . "组\n";
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL 整单: ' . $e->getMessage() . "\n";
}

// 带空格微信样例也应同为 1700（与无空格等价）
$spaced = <<<'TEXT'
复式三中三 08-17-45-28-各 20 元
三中三 01-02-14-20 元
三连肖猴猪马 100 元复式三连肖马狗蛇猴各组 100 元
二中二 05-10-50 元
二中二 05-10-50 元
复式二中二 08-18-25 各 50 元
四连肖马猴猪狗 100 元复式四连肖马猪狗猴龙各组 50 元
五连肖马狗蛇猴猪 50 元
TEXT;
try {
    $sp = pingma_parse_submit_text(trim($spaced));
    assert_near((float) $sp['total_amount'], 1700.0, '带空格样例合计');
    echo "OK 带空格样例 => 1700元\n";
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL 带空格: ' . $e->getMessage() . "\n";
}

// 五连肖复式：6 肖 = C(6,5)=6 组 × 50 = 300（非 C(6,2)）
try {
    $w5 = pingma_parse_submit_text('复式五连肖鼠牛虎兔龙蛇各50');
    assert_near((float) $w5['total_amount'], 300.0, '复式五连肖6肖');
    if ((int) $w5['bets'][0]['group_count'] !== 6) {
        throw new RuntimeException('复式五连肖6肖组数应为6');
    }
    echo "OK 复式五连肖6肖 => 300元\n";
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL 复式五连肖6肖: ' . $e->getMessage() . "\n";
}

// 三肖复式：6/7 肖按 C(n,3) 计费（非 C(n,2)）
try {
    $s6 = pingma_parse_submit_text('复式三肖鼠牛虎兔龙蛇各组50');
    if ((int) $s6['bets'][0]['group_count'] !== 20) {
        throw new RuntimeException('复式三肖6肖组数应为20');
    }
    assert_near((float) $s6['total_amount'], 1000.0, '复式三肖6肖');
    echo "OK 复式三肖6肖 => 1000元 / 20组\n";
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL 复式三肖6肖: ' . $e->getMessage() . "\n";
}
try {
    $s7 = pingma_parse_submit_text('复式三肖鼠牛虎兔龙蛇马各组50');
    if ((int) $s7['bets'][0]['group_count'] !== 35) {
        throw new RuntimeException('复式三肖7肖组数应为35');
    }
    assert_near((float) $s7['total_amount'], 1750.0, '复式三肖7肖');
    echo "OK 复式三肖7肖 => 1750元 / 35组\n";
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL 复式三肖7肖: ' . $e->getMessage() . "\n";
}

// 四肖复式：6/7 肖按 C(n,4) 计费
try {
    $x6 = pingma_parse_submit_text('复式四肖鼠牛虎兔龙蛇各组50');
    if ((int) $x6['bets'][0]['group_count'] !== 15) {
        throw new RuntimeException('复式四肖6肖组数应为15');
    }
    if (($x6['bets'][0]['play_label'] ?? '') !== '四肖') {
        throw new RuntimeException('复式四肖6肖玩法名应为四肖');
    }
    assert_near((float) $x6['total_amount'], 750.0, '复式四肖6肖');
    echo "OK 复式四肖6肖 => 750元 / 15组\n";
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL 复式四肖6肖: ' . $e->getMessage() . "\n";
}
try {
    $x7 = pingma_parse_submit_text('复式四肖鼠牛虎兔龙蛇马各组50');
    if ((int) $x7['bets'][0]['group_count'] !== 35) {
        throw new RuntimeException('复式四肖7肖组数应为35');
    }
    assert_near((float) $x7['total_amount'], 1750.0, '复式四肖7肖');
    echo "OK 复式四肖7肖 => 1750元 / 35组\n";
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL 复式四肖7肖: ' . $e->getMessage() . "\n";
}

// 金额单位「圆」与「元/园」等价
try {
    $yuan = pingma_parse_submit_text('二中二26-36 50圆');
    assert_near((float) $yuan['total_amount'], 50.0, '二中二50圆');
    if ((int) $yuan['bets'][0]['group_count'] !== 1) {
        throw new RuntimeException('二中二26-36 50圆 应为1组');
    }
    echo "OK 二中二26-36 50圆 => 50元\n";
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL 二中二50圆: ' . $e->getMessage() . "\n";
}

if ($failed > 0) {
    echo "\n失败 {$failed} 项\n";
    exit(1);
}
echo "\n全部通过 ({$failed} 失败)\n";
exit(0);
