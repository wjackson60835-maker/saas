<?php
/**
 * 生成肖类玩法极限模拟数据（覆盖 12 生肖）：
 * - A 一肖 12 条（无复式，每肖 1 条）+ 二肖～五肖（5+5+5 拆条）
 * - B 二连肖～五连肖（同样 5+5+5 拆条）
 *
 * php tools/gen_zodiac_fushi_12_test.php [每组单价，默认50]
 * php tools/gen_zodiac_fushi_12_test.php 50 --out=doc/pingma_zodiac_fushi_12_sample.txt
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

$per = 50;
$outFile = '';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--out=(.+)$/', $arg, $m)) {
        $outFile = $m[1];
    } elseif (is_numeric($arg)) {
        $per = max(1, (int) $arg);
    }
}

$zodiacOrder = array('鼠', '牛', '虎', '兔', '龙', '蛇', '马', '羊', '猴', '鸡', '狗', '猪');
$zA = '鼠牛虎兔龙';
$zB = '蛇马羊猴鸡';
$zC = '狗猪鼠牛龙';
function build_zodiac_line(string $play, string $zodiacs, int $per): string
{
    return '复式' . $play . $zodiacs . '各组' . $per;
}

function summarize_zodiac_lines(array $lines, string $title): array
{
    echo "=== {$title} ===\n";
    $subStake = 0.0;
    $subGroups = 0;
    $subBets = 0;
    foreach ($lines as $i => $line) {
        $p = pingma_parse_submit_text($line);
        $bet = $p['bets'][0];
        $z = implode('', $bet['selection']);
        echo sprintf(
            "  %2d. %s\n      → %s | n=%d 组=%d 每组=%s 合计=%s元 | 肖:%s\n",
            $i + 1,
            $line,
            $bet['play_label'],
            count($bet['selection']),
            $bet['group_count'],
            $bet['amount_per_group'],
            $bet['total_amount'],
            $z
        );
        $subStake += (float) $bet['total_amount'];
        $subGroups += (int) $bet['group_count'];
        $subBets++;
    }
    echo sprintf("  小计：%d 笔 / %d 组 / %s 元\n\n", $subBets, $subGroups, $subStake);
    return array('lines' => $lines, 'bets' => $subBets, 'groups' => $subGroups, 'stake' => $subStake);
}

$allSubmit = array();
$grand = array('bets' => 0, 'groups' => 0, 'stake' => 0.0);

echo "每组单价：{$per} 元\n";
echo '十二生肖：' . implode('', $zodiacOrder) . "\n";
echo "拆条：{$zA} | {$zB} | {$zC}\n\n";

// A. 一肖（无复式，12 肖各 1 条）+ 二肖～五肖
$secA = array();
foreach ($zodiacOrder as $sx) {
    $secA[] = '一肖' . $sx . '各组' . $per;
}
$xiaoPlays = array('二肖', '三肖', '四肖', '五肖');
foreach ($xiaoPlays as $play) {
    $secA[] = build_zodiac_line($play, $zA, $per);
    $secA[] = build_zodiac_line($play, $zB, $per);
    $secA[] = build_zodiac_line($play, $zC, $per);
}
$r = summarize_zodiac_lines($secA, 'A 一肖12条 + 二肖～五肖（7+5）');
$allSubmit = array_merge($allSubmit, $r['lines']);
$grand['bets'] += $r['bets'];
$grand['groups'] += $r['groups'];
$grand['stake'] += $r['stake'];

// B. 二连肖～五连肖
$lianPlays = array('二连肖', '三连肖', '四连肖', '五连肖');
$secC = array();
foreach ($lianPlays as $play) {
    $secC[] = build_zodiac_line($play, $zA, $per);
    $secC[] = build_zodiac_line($play, $zB, $per);
    $secC[] = build_zodiac_line($play, $zC, $per);
}
$r = summarize_zodiac_lines($secC, 'B 二连肖～五连肖 · 12肖覆盖');
$allSubmit = array_merge($allSubmit, $r['lines']);
$grand['bets'] += $r['bets'];
$grand['groups'] += $r['groups'];
$grand['stake'] += $r['stake'];

$batchText = implode("\n", $allSubmit);
$parsed = pingma_parse_submit_text($batchText);

echo "=== 整单汇总（肖类极限全套）===\n";
echo sprintf(
    "总笔数：%d  总组数：%d  总金额：%s 元\n",
    $parsed['total_bets'],
    $parsed['total_groups'],
    $parsed['total_amount']
);
echo sprintf(
    "（分段小计：%d 笔 / %d 组 / %s 元）\n\n",
    $grand['bets'],
    $grand['groups'],
    $grand['stake']
);

echo "=== 组数 / 赔率参考 ===\n";
echo "一肖无复式：每条仅 1 肖；二肖～五肖单条最多 5 肖\n";
echo "赔率（含本金）：一肖1.1 二肖3.1 三肖11 四肖31 五肖108\n";
echo "肖类对照开奖七球生肖集合；推荐开奖模拟仅按六平码肖集合。\n\n";

echo "=== 可复制提交正文（平码 · 肖类极限 " . count($allSubmit) . " 行）===\n";
echo $batchText . "\n";

if ($outFile !== '') {
    $path = $outFile;
    if ($path[0] !== '/' && !preg_match('/^[A-Za-z]:/', $path)) {
        $path = dirname(__DIR__) . '/' . ltrim($path, '/');
    }
    file_put_contents($path, $batchText . "\n");
    echo "\n已写入：{$path}\n";
}
