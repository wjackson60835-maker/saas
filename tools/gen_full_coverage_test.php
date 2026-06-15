<?php
/**
 * 生成平码全覆盖测试数据（符合提交规则：号码≤7/条，生肖≤5/条）：
 * - A 01–49 全号：二中二 + 三中三（各 7 条 × 7 号）
 * - B 12 生肖全覆：一肖 12 条（无复式）+ 二肖～五连肖（5+5+5 三条）
 * - C 按肖全号：每肖全部球号各一条（二中二 + 三中三，共 49 号无遗漏）
 *
 * php tools/gen_full_coverage_test.php [每组单价，默认10]
 * php tools/gen_full_coverage_test.php 10 --out=doc/pingma_full_coverage_test.txt
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
require dirname(__DIR__) . '/ajax/pingma_bet_parser.php';
require dirname(__DIR__) . '/ajax/lhc_lookup.php';

$per = 10;
$outFile = '';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--out=(.+)$/', $arg, $m)) {
        $outFile = $m[1];
    } elseif (is_numeric($arg)) {
        $per = max(1, (int) $arg);
    }
}

$zodiacOrder = array('鼠', '牛', '虎', '兔', '龙', '蛇', '马', '羊', '猴', '鸡', '狗', '猪');
// 12 肖拆 3 条（每条≤5）：10 肖不重复 + 第 3 条含狗猪并重复鼠牛龙以满足五肖/五连肖
$zA = '鼠牛虎兔龙';
$zB = '蛇马羊猴鸡';
$zC = '狗猪鼠牛龙';

function chunk_49_full(): array
{
    $chunks = array();
    for ($start = 1; $start <= 49; $start += 7) {
        $row = array();
        for ($i = 0; $i < 7 && ($start + $i) <= 49; $i++) {
            $row[] = str_pad((string) ($start + $i), 2, '0', STR_PAD_LEFT);
        }
        $chunks[] = $row;
    }
    return $chunks;
}

function zodiac_all_number_rows(array $zodiacOrder): array
{
    $lines = array();
    foreach (lhc_shengxiao_spec_map_2026() as $sx => $csv) {
        $nums = array();
        foreach (explode(',', $csv) as $x) {
            $x = trim($x);
            if ($x !== '') {
                $nums[] = str_pad($x, 2, '0', STR_PAD_LEFT);
            }
        }
        sort($nums, SORT_STRING);
        $lines[] = array('zodiac' => $sx, 'nums' => $nums);
    }
    usort($lines, static function ($a, $b) use ($zodiacOrder) {
        return array_search($a['zodiac'], $zodiacOrder, true) <=> array_search($b['zodiac'], $zodiacOrder, true);
    });
    return $lines;
}

function build_num_line(string $play, array $nums, int $per): string
{
    return '复式' . $play . implode('.', $nums) . '各组' . $per;
}

function build_zodiac_line(string $play, string $zodiacs, int $per): string
{
    return '复式' . $play . $zodiacs . '各组' . $per;
}

function summarize_section(array $lines, string $title): array
{
    echo "=== {$title} ===\n";
    $stake = 0.0;
    $groups = 0;
    $bets = 0;
    foreach ($lines as $i => $line) {
        $p = pingma_parse_submit_text($line);
        $bet = $p['bets'][0];
        echo sprintf(
            "  %2d. %s → %s | 组=%d 合计=%s元\n",
            $i + 1,
            $line,
            $bet['play_label'],
            $bet['group_count'],
            $bet['total_amount']
        );
        $stake += (float) $bet['total_amount'];
        $groups += (int) $bet['group_count'];
        $bets++;
    }
    echo sprintf("  小计：%d 笔 / %d 组 / %s 元\n\n", $bets, $groups, $stake);
    return array('lines' => $lines, 'bets' => $bets, 'groups' => $groups, 'stake' => $stake);
}

$all = array();
$grand = array('bets' => 0, 'groups' => 0, 'stake' => 0.0);

echo "每组单价：{$per} 元\n";
echo "规则：号码≤" . PINGMA_MAX_NUMBER_SELECTION . "/条，生肖≤" . PINGMA_MAX_ZODIAC_SELECTION . "/条\n";
echo "12肖拆条：{$zA} | {$zB} | {$zC}\n\n";

// A. 49 号全覆盖
$secA = array();
foreach (chunk_49_full() as $nums) {
    $secA[] = build_num_line('二中二', $nums, $per);
    $secA[] = build_num_line('三中三', $nums, $per);
}
$r = summarize_section($secA, 'A 01–49 全号（二中二+三中三，7条×7号）');
$all = array_merge($all, $r['lines']);
$grand['bets'] += $r['bets'];
$grand['groups'] += $r['groups'];
$grand['stake'] += $r['stake'];

// B. 12 肖全覆盖（一肖无复式：12 条各 1 肖；二肖～五肖 + 二～五连肖 各 3 条）
$secB = array();
foreach ($zodiacOrder as $sx) {
    $secB[] = '一肖' . $sx . '各组' . $per;
}
$xiaoPlays = array('二肖', '三肖', '四肖', '五肖');
$lianPlays = array('二连肖', '三连肖', '四连肖', '五连肖');
foreach (array_merge($xiaoPlays, $lianPlays) as $play) {
    $secB[] = build_zodiac_line($play, $zA, $per);
    $secB[] = build_zodiac_line($play, $zB, $per);
    $secB[] = build_zodiac_line($play, $zC, $per);
}
$r = summarize_section($secB, 'B 12 肖全覆盖（一肖12条 + 二肖～五连肖各3条）');
$all = array_merge($all, $r['lines']);
$grand['bets'] += $r['bets'];
$grand['groups'] += $r['groups'];
$grand['stake'] += $r['stake'];

// C. 按肖全号（每肖全部球，二中二+三中三）
$secC = array();
foreach (zodiac_all_number_rows($zodiacOrder) as $row) {
    $secC[] = build_num_line('二中二', $row['nums'], $per);
    $secC[] = build_num_line('三中三', $row['nums'], $per);
}
$r = summarize_section($secC, 'C 按肖全号（12肖×二中二/三中三，49号无遗漏）');
$all = array_merge($all, $r['lines']);
$grand['bets'] += $r['bets'];
$grand['groups'] += $r['groups'];
$grand['stake'] += $r['stake'];

$batch = implode("\n", $all);
$parsed = pingma_parse_submit_text($batch);

echo "=== 整单汇总 ===\n";
echo sprintf(
    "总笔数：%d  总组数：%d  总金额：%s 元\n",
    $parsed['total_bets'],
    $parsed['total_groups'],
    $parsed['total_amount']
);
echo sprintf("（分段小计：%d 笔 / %d 组 / %s 元）\n\n", $grand['bets'], $grand['groups'], $grand['stake']);

echo "=== 可复制提交正文（平码 · " . count($all) . " 行）===\n";
echo $batch . "\n";

if ($outFile !== '') {
    $path = $outFile;
    if ($path[0] !== '/' && !preg_match('/^[A-Za-z]:/', $path)) {
        $path = dirname(__DIR__) . '/' . ltrim($path, '/');
    }
    file_put_contents($path, $batch . "\n");
    echo "\n已写入：{$path}\n";
}
