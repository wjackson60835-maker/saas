<?php
if (!function_exists('mb_strlen')) {
    function mb_strlen($str, $enc = 'UTF-8')
    {
        return preg_match_all('/./us', (string) $str, $m) ? count($m[0]) : 0;
    }
    function mb_substr($str, $start, $len = null, $enc = 'UTF-8')
    {
        preg_match_all('/./us', (string) $str, $m);
        $chars = $m[0];
        if ($len === null) {
            $len = count($chars) - $start;
        }
        return implode('', array_slice($chars, $start, $len));
    }
}
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

$raw = <<<'TEXT'
复式三中三08-17-45-28-各20元
三中三01-02-14-20元
三连肖猴猪马100元复式三连肖马狗蛇猴各组100元
二中二05-10-50元
二中二05-10-50元
复式二中二08-18-25各50元
四连肖马猴猪狗100元复式四连肖马猪狗猴龙各组50元
五连肖马狗蛇猴猪50元
TEXT;

$lines = preg_split('/\R/u', trim($raw));
$grandTotal = 0.0;
$grandGroups = 0;
$idx = 0;

echo "=== 客户原文逐条计算 ===\n\n";

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $idx++;
    echo "--- 第{$idx}行 ---\n";
    echo "原文: {$line}\n";
    try {
        $r = pingma_parse_submit_text($line);
        echo "本行合计: {$r['total_amount']} 元 | {$r['total_bets']} 笔 | {$r['total_groups']} 组\n";
        foreach ($r['bets'] as $i => $b) {
            echo '  [' . ($i + 1) . '] ' . ($b['display_text'] ?? pingma_format_bet_line($b)) . "\n";
        }
        $grandTotal += (float) $r['total_amount'];
        $grandGroups += (int) $r['total_groups'];
    } catch (Throwable $e) {
        echo "  错误: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

echo "========================\n";
echo "全部合计: " . round($grandTotal, 2) . " 元\n";
echo "总组数: {$grandGroups} 组\n";

// 整单一次提交（8行合并）
echo "\n=== 若客户一次粘贴全部（换行分隔）===\n";
try {
    $all = pingma_parse_submit_text(trim($raw));
    echo "合计: {$all['total_amount']} 元 | {$all['total_bets']} 笔 | {$all['total_groups']} 组\n";
    foreach ($all['bets'] as $i => $b) {
        echo ($i + 1) . '. ' . ($b['display_text'] ?? pingma_format_bet_line($b)) . "\n";
    }
} catch (Throwable $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
