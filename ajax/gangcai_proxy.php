<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/** 港彩最新一期 JSON（与 skin/gangcai.html 对接） */
const HK_LOTTERY_API = 'https://api3.marksix6.net/lottery_api.php?type=hk';

/**
 * 含港彩多期 history（多节点依次尝试，避免单域名超时/阻断导致只剩 1 条兜底）
 */
const MARKSIX_AGGREGATE_URLS = [
    'https://marksix6.net/index.php?api=1',
    'https://www.marksix6.net/index.php?api=1',
    'http://marksix6.net/index.php?api=1',
];

/** 已解析的港彩历史 rows 缓存（小文件，命中后不再解析百 KB 聚合 JSON） */
const CACHE_TTL_HK_HISTORY_ROWS = 180;

/** 最新一期接口：iframe 轮询时命中缓存 */
const CACHE_TTL_HK_LOTTERY = 20;

$action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
$issue = isset($_GET['issue']) ? trim((string) $_GET['issue']) : '';

$map = [
    'current' => HK_LOTTERY_API,
    'list'    => HK_LOTTERY_API,
    'ball'    => HK_LOTTERY_API,
    'live'    => 'https://xg-hk.com/gw/ball/api/getCurrentVedio',
];

if (!isset($map[$action])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'invalid action'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 历史列表：聚合接口 history → { rows: [...] }（xghistory.html 依赖 data.rows）
if ($action === 'list') {
    $rows = hk_history_rows();
    echo json_encode(['ok' => true, 'data' => ['rows' => $rows]], JSON_UNESCAPED_UNICODE);
    exit;
}

// 按期查询：在聚合 history 中匹配
if ($action === 'ball' && $issue !== '') {
    $want = normalize_issue_to_expect($issue);
    $rows = hk_history_rows();
    $row = null;
    foreach ($rows as $r) {
        $e = ($r['yearNo'] ?? '') . ($r['periods'] ?? '');
        if ($e === $want) {
            $row = $r;
            break;
        }
    }
    echo json_encode(['ok' => true, 'data' => $row ?? new stdClass()], JSON_UNESCAPED_UNICODE);
    exit;
}

$url = $map[$action];
if ($action === 'ball' && $issue !== '' && strpos($url, 'marksix6.net') === false) {
    $digits = preg_replace('/\D+/', '', $issue);
    $yearNo = (string) date('Y');
    $periods = $digits;
    if (strlen($digits) >= 7) {
        $yearNo = substr($digits, 0, 4);
        $periods = substr($digits, 4);
    }
    if (ctype_digit($periods) && strlen($periods) < 3) {
        $periods = str_pad($periods, 3, '0', STR_PAD_LEFT);
    }
    if ($periods === '') {
        $periods = '000';
    }

    $query = http_build_query([
        'yearNo' => $yearNo,
        'year'   => $yearNo,
        'periods'=> $periods,
        'period' => $issue,
        'qishu'  => $issue,
        'issue'  => $issue,
        'expect' => $issue,
    ]);
    $url .= '?' . $query;
}

[$ok, $msg, $body] = fetch_json_for_action($url);
if (!$ok) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'message' => $msg, 'url' => $url], JSON_UNESCAPED_UNICODE);
    exit;
}

$json = json_decode((string) $body, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'message' => 'invalid json from upstream', 'raw' => mb_substr((string) $body, 0, 300)], JSON_UNESCAPED_UNICODE);
    exit;
}

if (is_array($json) && strpos((string) ($map[$action] ?? ''), 'marksix6.net') !== false) {
    $json = normalize_marksix_hk($json);
}

echo json_encode(['ok' => true, 'data' => $json], JSON_UNESCAPED_UNICODE);

/**
 * @return list<array<string,mixed>>
 */
function hk_history_rows(): array
{
    $cached = hk_history_rows_load_parsed_cache();
    if ($cached !== null) {
        return $cached;
    }

    $rows = hk_history_rows_build_from_aggregate();
    if ($rows !== []) {
        hk_history_rows_save_parsed_cache($rows, false);
        return $rows;
    }

    // 单期兜底不写入磁盘，避免长时间只显示 1 条
    return hk_history_rows_fallback_single();
}

/**
 * @return list<array<string,mixed>>|null
 */
function hk_history_rows_load_parsed_cache(): ?array
{
    $path = gangcai_cache_file_path('hk_history_rows');
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['ts'], $data['rows']) || !is_array($data['rows'])) {
        return null;
    }
    $age = time() - (int) $data['ts'];
    // 旧版曾缓存「仅 1 条」兜底，直接废弃以强制重新拉聚合
    if (!empty($data['grace'])) {
        return null;
    }
    if ($age >= CACHE_TTL_HK_HISTORY_ROWS) {
        return null;
    }
    return $data['rows'];
}

/**
 * @param list<array<string,mixed>> $rows
 */
function hk_history_rows_save_parsed_cache(array $rows, bool $graceFallback): void
{
    if ($graceFallback) {
        return;
    }
    $path = gangcai_cache_file_path('hk_history_rows');
    gangcai_cache_write($path, json_encode([
        'ts' => time(),
        'grace' => false,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * @return list<array<string,mixed>>
 */
function hk_history_rows_build_from_aggregate(): array
{
    [$ok, , $body] = fetch_json_aggregate();
    if (!$ok || $body === null) {
        return [];
    }
    $root = json_decode((string) $body, true);
    if (!is_array($root) || json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }

    $hk = null;
    foreach ($root['lottery_data'] ?? [] as $lot) {
        if (!is_array($lot)) {
            continue;
        }
        if (($lot['code'] ?? '') === 'hk') {
            $hk = $lot;
            break;
        }
    }
    if ($hk === null) {
        return [];
    }

    $history = $hk['history'] ?? [];
    if (!is_array($history)) {
        return [];
    }

    $expectCurrent = (string) ($hk['expect'] ?? '');
    $openTime = (string) ($hk['openTime'] ?? '');
    $rows = [];
    $flatIdx = 0;
    foreach ($history as $line) {
        if (!is_string($line)) {
            continue;
        }
        foreach (expand_marksix_history_entry($line) as $chunk) {
            $row = parse_marksix_history_line($chunk);
            if ($row === null) {
                continue;
            }
            if ($flatIdx === 0 && $expectCurrent !== '' && ($row['_expect'] ?? '') === $expectCurrent && $openTime !== '') {
                $row['ballTime'] = $openTime;
            }
            unset($row['_expect']);
            $rows[] = $row;
            $flatIdx++;
        }
    }

    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function hk_history_rows_fallback_single(): array
{
    [$ok, , $body] = fetch_json_cached(HK_LOTTERY_API, CACHE_TTL_HK_LOTTERY, 'hk_lottery_current');
    if (!$ok || $body === null) {
        return [];
    }
    $j = json_decode((string) $body, true);
    if (!is_array($j)) {
        return [];
    }
    $j = normalize_marksix_hk($j);
    $nums = $j['numbers'] ?? [];
    if (!is_array($nums) || count($nums) < 7) {
        return [];
    }
    $exp = (string) ($j['expect'] ?? '');
    if ($exp === '' || strlen($exp) !== 7) {
        return [];
    }
    $row = parse_marksix_history_line($exp . ' 期：' . implode(',', array_slice(array_map('strval', $nums), 0, 7)));
    if ($row === null) {
        return [];
    }
    $row['ballTime'] = (string) ($j['openTime'] ?? '');
    unset($row['_expect']);
    return [$row];
}

/**
 * 如 089 → 当前年 + 089；2026089 保持 7 位
 */
function normalize_issue_to_expect(string $issue): string
{
    $digits = preg_replace('/\D+/', '', $issue);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) >= 7) {
        return substr($digits, 0, 7);
    }
    if (strlen($digits) <= 3) {
        return (string) date('Y') . str_pad($digits, 3, '0', STR_PAD_LEFT);
    }
    return $digits;
}

/**
 * 接口偶有多期挤在同一字符串：2026033 期：… 2026032 期：…
 *
 * @return list<string>
 */
function expand_marksix_history_entry(string $line): array
{
    $line = trim($line);
    if ($line === '') {
        return [];
    }
    if (preg_match_all('/(\d{7})\s*期[：:]\s*([\d,\s\x{FF0C}]+?)(?=\s*\d{7}\s*期|$)/u', $line, $m, PREG_SET_ORDER)) {
        $chunks = [];
        foreach ($m as $seg) {
            $chunks[] = $seg[1] . ' 期：' . trim(preg_replace('/[\x{FF0C}]/u', ',', $seg[2]));
        }
        return $chunks !== [] ? $chunks : [$line];
    }
    return [$line];
}

/**
 * 单行示例：2026036 期：32,45,40,20,35,28,43
 *
 * @return ?array<string,mixed>
 */
function parse_marksix_history_line(string $line): ?array
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }
    if (!preg_match('/^(\d{7})\s*期[：:]\s*(.+)$/u', $line, $m)) {
        return null;
    }
    $expect = $m[1];
    $rest = trim(preg_replace('/[\x{FF0C}]/u', ',', $m[2]));
    $parts = preg_split('/[\s,]+/', $rest);
    if (!is_array($parts)) {
        return null;
    }
    $nums = [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p === '') {
            continue;
        }
        if (preg_match('/^(\d{1,2})$/', $p, $nm)) {
            $nums[] = str_pad($nm[1], 2, '0', STR_PAD_LEFT);
        }
    }
    if (count($nums) < 7) {
        return null;
    }
    $nums = array_slice($nums, 0, 7);
    $yearNo = (int) substr($expect, 0, 4);
    $periods = substr($expect, 4, 3);
    $row = [
        'yearNo' => $yearNo,
        'periods' => $periods,
        'ballTime' => '',
        '_expect' => $expect,
    ];
    for ($i = 0; $i < 7; $i++) {
        $row['b' . ($i + 1)] = $nums[$i];
    }
    return $row;
}

/**
 * 港彩单期 URL 走短缓存（iframe 轮询）；其它如 live 仍直连
 *
 * @return array{0:bool,1:string,2:string|null}
 */
function fetch_json_for_action(string $url): array
{
    if ($url === HK_LOTTERY_API) {
        return fetch_json_cached($url, CACHE_TTL_HK_LOTTERY, 'hk_lottery_current');
    }
    return fetch_json($url);
}

/**
 * 磁盘短缓存（runtime/cache），避免同一秒内多用户重复拉取大 JSON
 *
 * @return array{0:bool,1:string,2:string|null}
 */
function fetch_json_cached(string $url, int $ttlSeconds, string $cacheKey): array
{
    if ($ttlSeconds <= 0) {
        return fetch_json($url);
    }
    $path = gangcai_cache_file_path($cacheKey);
    $now = time();
    if (is_readable($path)) {
        $raw = @file_get_contents($path);
        if ($raw !== false && $raw !== '') {
            $meta = json_decode($raw, true);
            if (is_array($meta) && isset($meta['ts'], $meta['body']) && is_string($meta['body'])
                && ($now - (int) $meta['ts']) < $ttlSeconds) {
                return [true, '', $meta['body']];
            }
        }
    }
    $res = fetch_json($url);
    if ($res[0] && $res[2] !== null && $res[2] !== '') {
        gangcai_cache_write($path, json_encode(['ts' => $now, 'body' => $res[2]], JSON_UNESCAPED_UNICODE));
    }
    return $res;
}

function gangcai_cache_file_path(string $cacheKey): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $cacheKey) ?: 'default';
    return dirname(__DIR__) . '/runtime/cache/gangcai_' . $safe . '.json';
}

function gangcai_cache_write(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $tmp = $path . '.' . uniqid('tmp', true);
    if (@file_put_contents($tmp, $contents) !== false) {
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}

/**
 * 聚合接口体量大：多镜像、gzip、IPv4、略长超时
 *
 * @return array{0:bool,1:string,2:string|null}
 */
function fetch_json_aggregate(): array
{
    $lastErr = 'no mirror';
    foreach (MARKSIX_AGGREGATE_URLS as $apiUrl) {
        $res = fetch_json_aggregate_one($apiUrl);
        if ($res[0] && $res[2] !== null && $res[2] !== '') {
            return $res;
        }
        $lastErr = $res[1];
    }
    return [false, $lastErr, null];
}

/**
 * @return array{0:bool,1:string,2:string|null}
 */
function fetch_json_aggregate_one(string $apiUrl): array
{
    if (!function_exists('curl_init')) {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 22,
                'header'  => "Accept: application/json\r\nUser-Agent: Mozilla/5.0\r\n",
            ],
        ]);
        $body = @file_get_contents($apiUrl, false, $context);
        if ($body === false) {
            return [false, 'file_get_contents failed', null];
        }
        return [true, '', $body];
    }
    $ch = curl_init($apiUrl);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 22,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0',
        ],
    ];
    if (defined('CURL_IPRESOLVE_V4')) {
        $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0 || $body === false) {
        return [false, 'curl error: ' . $error, null];
    }
    if ($code < 200 || $code >= 300) {
        return [false, 'http status ' . $code, null];
    }
    return [true, '', $body];
}

function fetch_json(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json,text/plain,*/*',
                'User-Agent: Mozilla/5.0',
            ],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            return [false, 'curl error: ' . $error, null];
        }
        if ($code < 200 || $code >= 300) {
            return [false, 'http status ' . $code, null];
        }
        return [true, '', $body];
    }

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 15,
            'header'  => "Accept: application/json,text/plain,*/*\r\nUser-Agent: Mozilla/5.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return [false, 'file_get_contents failed', null];
    }
    return [true, '', $body];
}

/**
 * 与 skin/gangcai.html 中 extractDraw / tryGetNumbers 字段对齐：expect、openCode、numbers、yearNo
 * marksix 单期接口无下一期/倒计时，按港彩常见开奖日（周二、四、六 21:30 HKT）推算。
 *
 * @param array<string,mixed> $j
 * @return array<string,mixed>
 */
function normalize_marksix_hk(array $j): array
{
    if (!empty($j['expect']) && is_string($j['expect']) && preg_match('/^(\d{4})(\d{3})$/', $j['expect'], $m)) {
        $j['yearNo'] = (int) $m[1];
        $j['periods'] = $m[2];
        $j['period'] = $m[2];
        $j['qishu'] = $m[2];
        $j['issue'] = $j['expect'];
    }
    if (!empty($j['openCode']) && is_string($j['openCode']) && empty($j['numbers'])) {
        $parts = array_filter(array_map('trim', preg_split('/[,\s|+]+/', $j['openCode'])));
        if ($parts !== []) {
            $j['numbers'] = array_values($parts);
        }
    }
    return enrich_marksix_next_draw($j);
}

/**
 * 解析「M月d日…H点i分」为香港时间，若已不晚于当前时间则按日历逐日顺延到下一档同一时间（例如当天已过 21:30 则展示次日同时刻）。
 *
 * @return array{text: string, until: \DateTimeImmutable|null, rolled: bool}
 */
function normalize_next_time_string_hk(string $txt, \DateTimeImmutable $nowHk): array
{
    if (!preg_match('/(\d{1,2})月(\d{1,2})日.*?(\d{1,2})点(\d{1,2})分/u', $txt, $m)) {
        return ['text' => $txt, 'until' => null, 'rolled' => false];
    }
    $mo = (int) $m[1];
    $d = (int) $m[2];
    $h = (int) $m[3];
    $mi = (int) $m[4];
    $tz = $nowHk->getTimezone();
    $y = (int) $nowHk->format('Y');
    $make = static function (int $year) use ($mo, $d, $h, $mi, $tz): ?\DateTimeImmutable {
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return null;
        }
        try {
            return new \DateTimeImmutable(
                sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $mo, $d, $h, $mi),
                $tz
            );
        } catch (\Throwable $e) {
            return null;
        }
    };
    $slot = $make($y);
    if ($slot === null) {
        return ['text' => $txt, 'until' => null, 'rolled' => false];
    }
    if ($slot < $nowHk->modify('-275 days')) {
        $slot = $make($y + 1) ?? $slot;
    }
    $rolled = false;
    $guard = 0;
    while ($slot <= $nowHk && $guard < 400) {
        $slot = $slot->modify('+1 day');
        $guard++;
        $rolled = true;
    }
    if (!$rolled) {
        return ['text' => $txt, 'until' => $slot, 'rolled' => false];
    }
    $wd = ['日', '一', '二', '三', '四', '五', '六'];
    $widx = (int) $slot->format('w');
    $newText = $slot->format('n') . '月' . $slot->format('j') . '日 星期' . ($wd[$widx] ?? '') . ' '
        . $slot->format('G') . '点' . $slot->format('i') . '分';

    return ['text' => $newText, 'until' => $slot, 'rolled' => true];
}

/**
 * 下一期期号：当前 expect +1（每年约 134 期，跨年归零由简单规则处理）
 *
 * @param array<string,mixed> $j
 * @return array<string,mixed>
 */
function enrich_marksix_next_draw(array $j): array
{
    $tz = new \DateTimeZone('Asia/Hong_Kong');
    $now = new \DateTimeImmutable('now', $tz);

    if (!empty($j['nextTime']) && is_string($j['nextTime'])) {
        $norm = normalize_next_time_string_hk($j['nextTime'], $now);
        if ($norm['until'] !== null) {
            $secFromText = max(0, $norm['until']->getTimestamp() - $now->getTimestamp());
            $hadUpstreamSec = (isset($j['secondsToNextDraw']) && is_numeric($j['secondsToNextDraw']))
                || (isset($j['seconds_to_next_draw']) && is_numeric($j['seconds_to_next_draw']))
                || (isset($j['nextLeftSeconds']) && is_numeric($j['nextLeftSeconds']));
            if ($norm['rolled'] || !$hadUpstreamSec) {
                $j['nextTime'] = $norm['text'];
                $j['nextDrawTime'] = $norm['text'];
                $j['nextLotteryTime'] = $norm['text'];
                $j['secondsToNextDraw'] = $secFromText;
                $j['seconds_to_next_draw'] = $secFromText;
                $j['nextLeftSeconds'] = $secFromText;
            }
        }
    }

    if (!empty($j['nextIssue']) && !empty($j['nextTime'])) {
        return $j;
    }
    $expect = isset($j['expect']) && is_string($j['expect']) ? $j['expect'] : '';
    if ($expect === '' || !preg_match('/^\d{7}$/', $expect)) {
        return $j;
    }
    $nextExpect = next_hk_expect_from_current($expect);
    if ($nextExpect !== '') {
        $j['nextIssue'] = $nextExpect;
        $j['nextExpect'] = $nextExpect;
        $j['nextPeriod'] = substr($nextExpect, 4, 3);
        $j['nextPeriods'] = substr($nextExpect, 4, 3);
    }
    $nextDraw = next_mark_six_draw_hk($now);
    $sec = max(0, $nextDraw->getTimestamp() - $now->getTimestamp());
    $j['secondsToNextDraw'] = $sec;
    $j['seconds_to_next_draw'] = $sec;
    $j['nextLeftSeconds'] = $sec;
    $wd = ['日', '一', '二', '三', '四', '五', '六'];
    $widx = (int) $nextDraw->format('w');
    $txt = $nextDraw->format('n') . '月' . $nextDraw->format('j') . '日 星期' . ($wd[$widx] ?? '') . ' '
        . $nextDraw->format('G') . '点' . $nextDraw->format('i') . '分';
    $j['nextTime'] = $txt;
    $j['nextDrawTime'] = $txt;
    $j['nextLotteryTime'] = $txt;
    return $j;
}

function next_hk_expect_from_current(string $expect): string
{
    if (!preg_match('/^(\d{4})(\d{3})$/', $expect, $m)) {
        return '';
    }
    $y = (int) $m[1];
    $p = (int) $m[2];
    $p++;
    if ($p > 134) {
        $y++;
        $p = 1;
    }
    return sprintf('%04d%03d', $y, $p);
}

/**
 * 香港六合彩通常周二、四、六 21:30（HKT）开奖（遇官方改期无法预知，此处为常规展示）
 */
function next_mark_six_draw_hk(\DateTimeImmutable $from): \DateTimeImmutable
{
    $tz = new \DateTimeZone('Asia/Hong_Kong');
    $fromHk = $from->setTimezone($tz);
    $midnight = $fromHk->setTime(0, 0, 0);
    for ($add = 0; $add < 14; $add++) {
        $d = $midnight->modify('+' . $add . ' days');
        $w = (int) $d->format('w');
        if (!in_array($w, [2, 4, 6], true)) {
            continue;
        }
        $slot = $d->setTime(21, 30, 0);
        if ($slot > $fromHk) {
            return $slot;
        }
    }
    return $fromHk->modify('+7 days')->setTime(21, 30, 0);
}
