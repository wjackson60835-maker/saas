<?php
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $e = 'UTF-8') { return preg_match_all('/./us', $s, $m) ? count($m[0]) : 0; }
    function mb_substr($s, $st, $l = null, $e = 'UTF-8') {
        preg_match_all('/./us', $s, $m); $c = $m[0];
        if ($l === null) $l = count($c) - $st;
        return implode('', array_slice($c, $st, $l));
    }
}
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

$cases = array(
    array('复式四连肖羊猴鸡狗猪各组50', 5, '四连肖5肖'),
    array('复式四连肖鼠牛虎兔龙蛇马各组50', 35, '四连肖7肖'),
    array('复式三连肖鼠牛虎兔龙蛇马各组50', 35, '三连肖7肖'),
    array('复式四连肖鼠牛虎兔龙各组10', 5, '四连肖5肖-doc'),
    array('一肖马各10', 1, '平特一肖'),
    array('平特一肖马各10', 1, '平特一肖显式'),
);

$ok = 0;
foreach ($cases as $c) {
    $text = $c[0];
    $expect = $c[1];
    $note = $c[2];
    try {
        $r = pingma_parse_submit_text($text);
        $b = $r['bets'][0];
        $gc = (int) $b['group_count'];
        $label = $b['play_label'];
        $pass = $gc === $expect;
        if ($pass) {
            $ok++;
        }
        echo ($pass ? 'OK' : 'FAIL') . " [{$note}] gc={$gc} expect={$expect} label={$label} display=" . pingma_format_bet_line($b) . "\n";
    } catch (Throwable $e) {
        echo "ERR [{$note}] {$e->getMessage()}\n";
    }
}
echo "passed {$ok}/" . count($cases) . "\n";
