<?php
/**
 * 生成二中二 / 三中三 极限模拟数据：
 * - 12 生肖均有代表号（7+5 拆条，单条最多 7 号）
 * - 01–49 全号覆盖（7 条 × 7 号，复式展开组数最大）
 * - 按肖全号（12 肖各自全部球号一条，共 49 号无遗漏）
 *
 * php tools/gen_number_fushi_49_test.php [每组单价，默认50]
 * php tools/gen_number_fushi_49_test.php 50 --out=doc/pingma_number_fushi_49_sample.txt
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

$per = 50;
$outFile = '';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--out=(.+)$/', $arg, $m)) {
        $outFile = $m[1];
    } elseif (is_numeric($arg)) {
        $per = max(1, (int) $arg);
    }
}

$drawTs = time();
$zodiacOrder = array('鼠', '牛', '虎', '兔', '龙', '蛇', '马', '羊', '猴', '鸡', '狗', '猪');
$repByZodiac = array();
foreach (lhc_shengxiao_spec_map_2026() as $sx => $csv) {
    $first = trim(explode(',', $csv)[0]);
    $repByZodiac[$sx] = str_pad($first, 2, '0', STR_PAD_LEFT);
}
$z7Nums = array();
$z5Nums = array();
foreach ($zodiacOrder as $i => $sx) {
    $n = $repByZodiac[$sx] ?? '';
    if ($n === '') {
        continue;
    }
    if ($i < 7) {
        $z7Nums[] = $n;
    } else {
        $z5Nums[] = $n;
    }
}

function fmt_num_line(array $nums): string
{
    return implode('.', $nums);
}

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

function zodiac_all_number_lines(array $zodiacOrder): array
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

function build_fushi_line(string $play, array $nums, int $per): string
{
    return '复式' . $play . fmt_num_line($nums) . '各组' . $per;
}

function summarize_lines(array $lines, string $title, int $per): array
{
    echo "=== {$title} ===\n";
    $subStake = 0.0;
    $subGroups = 0;
    $subBets = 0;
    foreach ($lines as $i => $line) {
        $p = pingma_parse_submit_text($line);
        $bet = $p['bets'][0];
        $zods = array();
        foreach ($bet['selection'] as $num) {
            $sx = lhc_shengxiao_for_number((string) $num, time());
            if ($sx !== '' && !in_array($sx, $zods, true)) {
                $zods[] = $sx;
            }
        }
        echo sprintf(
            "  %2d. %s\n      → %s | n=%d 组=%d 每组=%s 合计=%s元 | 肖:%s\n",
            $i + 1,
            $line,
            $bet['play_label'],
            count($bet['selection']),
            $bet['group_count'],
            $bet['amount_per_group'],
            $bet['total_amount'],
            implode('', $zods)
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
echo "12肖代表号：\n";
foreach ($zodiacOrder as $sx) {
    echo "  {$sx}→" . ($repByZodiac[$sx] ?? '?') . "\n";
}
echo "\n";

// A. 12肖代表（7+5）
$secA_er = array(
    build_fushi_line('二中二', $z7Nums, $per),
    build_fushi_line('二中二', $z5Nums, $per),
);
$secA_san = array(
    build_fushi_line('三中三', $z7Nums, $per),
    build_fushi_line('三中三', $z5Nums, $per),
);
$r = summarize_lines($secA_er, 'A1 二中二 · 12肖代表（7+5）', $per);
$allSubmit = array_merge($allSubmit, $r['lines']);
$grand['bets'] += $r['bets'];
$grand['groups'] += $r['groups'];
$grand['stake'] += $r['stake'];
$r = summarize_lines($secA_san, 'A2 三中三 · 12肖代表（7+5）', $per);
$allSubmit = array_merge($allSubmit, $r['lines']);
$grand['bets'] += $r['bets'];
$grand['groups'] += $r['groups'];
$grand['stake'] += $r['stake'];

// B. 49号全覆盖（7条×7号，单条 C(7,2)=21 / C(7,3)=35 为极限）
$chunks49 = chunk_49_full();
$secB_er = array();
$secB_san = array();
foreach ($chunks49 as $nums) {
    $secB_er[] = build_fushi_line('二中二', $nums, $per);
    $secB_san[] = build_fushi_line('三中三', $nums, $per);
}
$r = summarize_lines($secB_er, 'B1 二中二 · 49号全覆盖（7条×7号）', $per);
$allSubmit = array_merge($allSubmit, $r['lines']);
$grand['bets'] += $r['bets'];
$grand['groups'] += $r['groups'];
$grand['stake'] += $r['stake'];
$r = summarize_lines($secB_san, 'B2 三中三 · 49号全覆盖（7条×7号）', $per);
$allSubmit = array_merge($allSubmit, $r['lines']);
$grand['bets'] += $r['bets'];
$grand['groups'] += $r['groups'];
$grand['stake'] += $r['stake'];

// C. 按肖全号（12肖各自全部球号）
$secC_er = array();
$secC_san = array();
foreach (zodiac_all_number_lines($zodiacOrder) as $row) {
    $secC_er[] = build_fushi_line('二中二', $row['nums'], $per);
    $secC_san[] = build_fushi_line('三中三', $row['nums'], $per);
}
$r = summarize_lines($secC_er, 'C1 二中二 · 按肖全号（12条）', $per);
$allSubmit = array_merge($allSubmit, $r['lines']);
$grand['bets'] += $r['bets'];
$grand['groups'] += $r['groups'];
$grand['stake'] += $r['stake'];
$r = summarize_lines($secC_san, 'C2 三中三 · 按肖全号（12条）', $per);
$allSubmit = array_merge($allSubmit, $r['lines']);
$grand['bets'] += $r['bets'];
$grand['groups'] += $r['groups'];
$grand['stake'] += $r['stake'];

$batchText = implode("\n", $allSubmit);
$parsed = pingma_parse_submit_text($batchText);

echo "=== 整单汇总（极限全套）===\n";
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

// 最坏派彩粗算：假设 6 平码恰为某条 7 号中的 6 个
$oddsEr = 63.0;
$oddsSan = 705.0;
$worstErOneLine = 15 * $per * $oddsEr; // C(6,2)=15 中组
$worstSanOneLine = 20 * $per * $oddsSan; // C(6,3)=20 中组
echo "=== 性能 / 派彩参考 ===\n";
echo "单条 n=7 复式展开：二中二 C(7,2)=21 组；三中三 C(7,3)=35 组（系统上限）\n";
echo "若某条 7 号中有 6 个命中平码：二中二最多中 15 组，三中三最多中 20 组\n";
echo sprintf(
    "单条最坏派彩约：二中二 %s 元；三中三 %s 元（每组 %d × 赔率）\n",
    number_format($worstErOneLine, 0, '.', ','),
    number_format($worstSanOneLine, 0, '.', ','),
    $per
);
echo "推荐开奖会对多组 6 平码方案逐笔验算；49 号全买时赔付压力权重计算量最大。\n\n";

echo "=== 可复制提交正文（平码 · 极限全套 " . count($allSubmit) . " 行）===\n";
echo $batchText . "\n";

if ($outFile !== '') {
    $path = $outFile;
    if ($path[0] !== '/' && !preg_match('/^[A-Za-z]:/', $path)) {
        $path = dirname(__DIR__) . '/' . ltrim($path, '/');
    }
    file_put_contents($path, $batchText . "\n");
    echo "\n已写入：{$path}\n";
}
