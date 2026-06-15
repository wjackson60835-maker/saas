<?php
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $e = 'UTF-8') { return preg_match_all('/./us', $s, $m) ? count($m[0]) : 0; }
    function mb_substr($s, $st, $l = null, $e = 'UTF-8') {
        preg_match_all('/./us', $s, $m);
        $c = $m[0];
        if ($l === null) $l = count($c) - $st;
        return implode('', array_slice($c, $st, $l));
    }
}
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

$tests = [
    '三中三 40、35、22/50',
    '复式五连肖鼠牛虎兔龙蛇各50',
    '复式五连肖鼠牛虎兔龙蛇马各组50',
    '复复式三中三02.26.43.36.25.29各组50',
    '肖连 马狗蛇猴 5元',
    '三中三 40、35、22、50各50',
];

foreach ($tests as $t) {
    try {
        $p = pingma_parse_submit_text($t);
        $b = $p['bets'][0];
        echo $t . "\n";
        echo '  => ' . $p['total_amount'] . ' 元, label=' . $b['play_label']
            . ', n=' . count($b['selection']) . ', groups=' . $b['group_count']
            . ', sel=' . implode(',', $b['selection']) . "\n\n";
    } catch (Throwable $e) {
        echo $t . "\n  => ERR: " . $e->getMessage() . "\n\n";
    }
}
