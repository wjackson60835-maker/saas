<?php
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';

$raw = '复式三中三 43-44-45-46-47-48-49 各10';
$parsed = pingma_parse_submit_text($raw);
echo "parse: " . $parsed['formatted_text'] . PHP_EOL;

$lines = array();
foreach ($parsed['bets'] as $bet) {
    $lines[] = pingma_format_bet_line(pingma_recalc_bet_amounts($bet));
}
echo "preview: " . implode("\n", $lines) . PHP_EOL;

$expect = '复式三中三 43-44-45-46-47-48-49 · 35组×10元=350元';
$ok = ($parsed['formatted_text'] === $expect && (float) $parsed['total_amount'] === 350.0);
echo ($ok ? 'OK' : 'FAIL') . ' total=' . $parsed['total_amount'] . PHP_EOL;
