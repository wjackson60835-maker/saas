<?php
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

$cases = array(
    '二中二 01-02 100元',
    '二中二 05-10-50 元',
    '复式二中二 08-18-25 50 元',
    '复式二中二 08-18-25 各 50 元',
    '三中三 01-02-14-20 元',
    '复式三中三 08-17-45-28-各 20 元',
    '四肖 马狗蛇猴 每组1元',
    '三连肖猴猪马 100 元',
    '三连肖猴猪马 100 元复式三连肖马狗蛇猴各组 100 元',
);

foreach ($cases as $c) {
    try {
        $r = pingma_parse_submit_text($c);
        echo $c, ' => ', $r['total_amount'], ' (', $r['total_groups'], " groups)\n";
        foreach ($r['bets'] as $b) {
            echo '  ', $b['play_label'], ' sel=', implode(',', $b['selection']), ' gc=', $b['group_count'], ' per=', $b['amount_per_group'], "\n";
        }
    } catch (Throwable $e) {
        echo 'ERR ', $c, ': ', $e->getMessage(), "\n";
    }
}
