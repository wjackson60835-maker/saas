<?php
/**
 * 微信样例批量验算（与用户截图一致）
 */
if (!function_exists('mb_strlen')) {
    function mb_strlen($str, $encoding = 'UTF-8')
    {
        if ($encoding !== 'UTF-8') {
            return strlen((string) $str);
        }
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

$samples = array(
    array('id' => 1, 'text' => '复式三中三 08-17-45-28-各 20 元'),
    array('id' => 2, 'text' => '三中三 01-02-14-20 元'),
    array('id' => 3, 'text' => '三连肖猴猪马 100 元复式三连肖马狗蛇猴各组 100 元'),
    array('id' => 4, 'text' => "二中二 05-10-50 元\n二中二 05-10-50 元"),
    array('id' => 5, 'text' => '复式二中二 08-18-25 各 50 元'),
    array('id' => '5b', 'text' => '复式二中二 08-18-25 50 元'),
    array('id' => 7, 'text' => '四连肖马猴猪狗 100 元复式四连肖马猪狗猴龙各组 50 元'),
    array('id' => 8, 'text' => '五连肖马狗蛇猴猪 50 元'),
);

echo "=== 微信样例平码解析验算 ===\n\n";

foreach ($samples as $s) {
    echo "--- #{$s['id']} ---\n";
    echo "原文: " . str_replace("\n", ' / ', $s['text']) . "\n";
    try {
        $r = pingma_parse_submit_text($s['text']);
        echo "合计: {$r['total_amount']} 元 | {$r['total_bets']} 笔 | {$r['total_groups']} 组\n";
        foreach ($r['bets'] as $i => $b) {
            $sel = is_array($b['selection']) ? implode(',', $b['selection']) : '';
            $mode = $b['amount_mode'] ?? '';
            echo '  [' . ($i + 1) . '] ' . $b['play_label']
                . ' | ' . $sel
                . ' | ' . $b['group_count'] . '组'
                . ' × ' . $b['amount_per_group'] . '元/组'
                . ' = ' . $b['total_amount'] . '元'
                . ($mode === 'flat_total' ? ' (整单)' : '')
                . "\n";
        }
    } catch (Throwable $e) {
        echo "错误: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
