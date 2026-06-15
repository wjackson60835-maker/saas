<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$collectConfig = require dirname(__DIR__, 2) . '/config/collect.php';
require_once dirname(__DIR__) . '/bet_line_parser.php';
require_once dirname(__DIR__) . '/pingma_bet_parser.php';
require_once dirname(__DIR__) . '/lhc_lookup.php';
date_default_timezone_set($collectConfig['timezone'] ?? 'Asia/Shanghai');

/** @return array<string, mixed> */
function collect_get_config(): array
{
    static $cache = null;
    if ($cache === null) {
        $p = dirname(__DIR__, 2) . '/config/collect.php';
        $cache = is_file($p) ? require $p : array();
        if (!is_array($cache)) {
            $cache = array();
        }
    }
    return $cache;
}
// 与后台主系统保持一致的会话配置，确保能读取 admin 登录态（sid/M/id）。
if (session_status() !== PHP_SESSION_ACTIVE) {
    $savePath = dirname(__DIR__, 2) . '/runtime/session/';
    if (!is_dir($savePath)) {
        @mkdir($savePath, 0777, true);
    }
    // 后台默认使用文件会话并落地到 runtime/session，深度目录为 1。
    @ini_set('session.save_handler', 'files');
    @ini_set('session.save_path', '1;' . $savePath);
    // 补齐一级目录，避免首次访问时因目录不存在导致会话读取失败。
    foreach (array_merge(range('0', '9'), range('a', 'z')) as $d) {
        $dir = $savePath . $d;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }
    session_name('PbootSystem');
}
session_start();

function json_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = require dirname(__DIR__, 2) . '/config/database.php';
    $host = $db['database']['host'] ?? '127.0.0.1';
    $dbname = $db['database']['dbname'] ?? '';
    $user = $db['database']['user'] ?? '';
    $passwd = $db['database']['passwd'] ?? '';
    $port = $db['database']['port'] ?? '3306';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbname);
    $pdo = new PDO($dsn, $user, $passwd, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
    return $pdo;
}

function collect_normalize_ball_scope(string $scope): string
{
    $s = strtolower(trim($scope));
    return $s === 'pingma' ? 'pingma' : 'tema';
}

/** 按 parsed_json.ball_scope 筛选提交单（无字段或 null 视为特码） */
function collect_ball_scope_sql(string $scope, string $alias = 's'): string
{
    $scope = collect_normalize_ball_scope($scope);
    $col = "JSON_UNQUOTE(JSON_EXTRACT({$alias}.parsed_json, '$.ball_scope'))";
    if ($scope === 'pingma') {
        return "{$col} = 'pingma'";
    }
    return "({$col} IS NULL OR {$col} = '' OR {$col} <> 'pingma')";
}

function collect_items_has_is_special(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        // 用 DATABASE() 与当前连接一致，避免 config 里库名与实际库名大小写不一致导致误判
        $stmt = $pdo->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'collect_submission_items'
               AND COLUMN_NAME = 'is_special'
             LIMIT 1"
        );
        $cached = (bool) $stmt->fetch();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

function collect_db_has_table(PDO $pdo, string $table): bool
{
    static $cache = array();
    $k = strtolower($table);
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(array(':t' => $table));
        $cache[$k] = (bool) $stmt->fetch();
    } catch (Throwable $e) {
        $cache[$k] = false;
    }
    return $cache[$k];
}

function collect_db_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = array();
    $k = strtolower($table . '.' . $column);
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
        );
        $stmt->execute(array(':t' => $table, ':c' => $column));
        $cache[$k] = (bool) $stmt->fetch();
    } catch (Throwable $e) {
        $cache[$k] = false;
    }
    return $cache[$k];
}

function collect_submissions_has_distributor_cols(PDO $pdo): bool
{
    return collect_db_has_column($pdo, 'collect_submissions', 'distributor_id')
        && collect_db_has_column($pdo, 'collect_submissions', 'distributor_name');
}

function collect_bets_table_exists(PDO $pdo): bool
{
    return collect_db_has_table($pdo, 'collect_submission_bets');
}

function collect_distributors_has_parent_id(PDO $pdo): bool
{
    return collect_db_has_table($pdo, 'collect_distributors')
        && collect_db_has_column($pdo, 'collect_distributors', 'parent_id');
}

function collect_distributors_has_pass_hash(PDO $pdo): bool
{
    return collect_db_has_table($pdo, 'collect_distributors')
        && collect_db_has_column($pdo, 'collect_distributors', 'pass_hash');
}

/**
 * @return array{hash:?string,error:?string} hash 为 null 表示库无 pass_hash 列；error 非空为校验失败
 */
function collect_distributor_prepare_pass_hash(PDO $pdo, array $input): array
{
    if (!collect_distributors_has_pass_hash($pdo)) {
        return array('hash' => null, 'error' => null);
    }
    $pwd = isset($input['password']) ? (string) $input['password'] : '';
    if (strlen($pwd) < 4) {
        return array('hash' => null, 'error' => '请设置代理登录密码（至少4位）');
    }
    if (strlen($pwd) > 200) {
        return array('hash' => null, 'error' => '密码过长（最多200字符）');
    }
    return array('hash' => password_hash($pwd, PASSWORD_DEFAULT), 'error' => null);
}

/**
 * @param string|null $passHash 已哈希；库无 pass_hash 列时传 null
 */
function collect_distributor_execute_insert(PDO $pdo, int $passId, int $parentId, string $name, ?string $passHash): void
{
    $hasP = collect_distributors_has_parent_id($pdo);
    $hasW = collect_distributors_has_pass_hash($pdo);
    if ($hasP && $hasW) {
        $stmt = $pdo->prepare(
            'INSERT INTO collect_distributors(pass_id, parent_id, name, pass_hash, sort_order, status, created_at, updated_at)
             VALUES(:p, :par, :n, :ph, 0, 1, NOW(), NOW())'
        );
        $stmt->execute(array(':p' => $passId, ':par' => $parentId, ':n' => $name, ':ph' => (string) $passHash));
        return;
    }
    if ($hasP && !$hasW) {
        $stmt = $pdo->prepare(
            'INSERT INTO collect_distributors(pass_id, parent_id, name, sort_order, status, created_at, updated_at)
             VALUES(:p, :par, :n, 0, 1, NOW(), NOW())'
        );
        $stmt->execute(array(':p' => $passId, ':par' => $parentId, ':n' => $name));
        return;
    }
    if (!$hasP && $hasW) {
        $stmt = $pdo->prepare(
            'INSERT INTO collect_distributors(pass_id, name, pass_hash, sort_order, status, created_at, updated_at)
             VALUES(:p, :n, :ph, 0, 1, NOW(), NOW())'
        );
        $stmt->execute(array(':p' => $passId, ':n' => $name, ':ph' => (string) $passHash));
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO collect_distributors(pass_id, name, sort_order, status, created_at, updated_at)
         VALUES(:p, :n, 0, 1, NOW(), NOW())'
    );
    $stmt->execute(array(':p' => $passId, ':n' => $name));
}

/**
 * @return int 0=渠道下顶级
 */
function collect_distributor_input_parent_id(PDO $pdo, array $input): int
{
    if (!collect_distributors_has_parent_id($pdo)) {
        return 0;
    }
    $pid = (int) ($input['parentId'] ?? $input['parent_id'] ?? 0);
    return $pid < 0 ? 0 : $pid;
}

/** @return string|null 错误文案；null 表示通过 */
function collect_distributor_validate_parent_for_pass(PDO $pdo, int $passId, int $parentId): ?string
{
    if ($parentId === 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id FROM collect_distributors WHERE id = :id AND pass_id = :p AND status = 1 LIMIT 1'
    );
    $stmt->execute(array(':id' => $parentId, ':p' => $passId));
    if (!$stmt->fetch()) {
        return '上级代理不存在、已停用或不属于本渠道';
    }
    return null;
}

/**
 * BFS 收集某节点及其所有后代 id（含自身）。
 *
 * @return int[]
 */
function collect_distributor_descendant_ids_including_self(PDO $pdo, int $passId, int $rootId): array
{
    if (!collect_distributors_has_parent_id($pdo)) {
        return array($rootId);
    }
    $out = array();
    $frontier = array($rootId);
    $seen = array();
    while ($frontier !== array()) {
        $id = (int) array_shift($frontier);
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = $id;
        $stmt = $pdo->prepare('SELECT id FROM collect_distributors WHERE pass_id = :p AND parent_id = :pid');
        $stmt->execute(array(':p' => $passId, ':pid' => $id));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int) ($row['id'] ?? 0);
            if ($cid > 0 && !isset($seen[$cid])) {
                $frontier[] = $cid;
            }
        }
    }
    return $out;
}

/** 自浅到深排序 id，便于先删子再删父（同一 pass_id） */
function collect_distributor_ids_deepest_first(PDO $pdo, int $passId, array $ids): array
{
    if ($ids === array()) {
        return array();
    }
    $depth = array();
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id <= 0) {
            continue;
        }
        $d = 0;
        $cur = $id;
        $g = 0;
        while ($cur > 0 && $g++ < 200) {
            $st = $pdo->prepare(
                collect_distributors_has_parent_id($pdo)
                    ? 'SELECT parent_id FROM collect_distributors WHERE id = :id AND pass_id = :p LIMIT 1'
                    : 'SELECT 0 AS parent_id FROM collect_distributors WHERE id = :id AND pass_id = :p LIMIT 1'
            );
            $st->execute(array(':id' => $cur, ':p' => $passId));
            $rw = $st->fetch(PDO::FETCH_ASSOC);
            if (!$rw) {
                break;
            }
            $pid = collect_distributors_has_parent_id($pdo) ? (int) ($rw['parent_id'] ?? 0) : 0;
            if ($pid <= 0) {
                break;
            }
            $d++;
            $cur = $pid;
        }
        $depth[$id] = $d;
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    usort($ids, static function ($a, $b) use ($depth) {
        $da = $depth[$a] ?? 0;
        $db = $depth[$b] ?? 0;
        if ($da !== $db) {
            return $db <=> $da;
        }
        return $b <=> $a;
    });
    return $ids;
}

/** 为列表行附加 label（路径「父 / 子」）与规范化 parent_id */
function collect_distributors_attach_path_label(array $rows): array
{
    if ($rows === array()) {
        return array();
    }
    $byId = array();
    foreach ($rows as $r) {
        if (!is_array($r) || !isset($r['id'])) {
            continue;
        }
        $byId[(int) $r['id']] = $r;
    }
    foreach ($rows as &$r) {
        if (!is_array($r) || !isset($r['id'])) {
            continue;
        }
        $r['parent_id'] = isset($r['parent_id']) ? (int) $r['parent_id'] : 0;
        $names = array();
        $cur = (int) $r['id'];
        $g = 0;
        while ($cur > 0 && isset($byId[$cur]) && $g++ < 200) {
            $node = $byId[$cur];
            array_unshift($names, trim((string) ($node['name'] ?? '')));
            $pid = isset($node['parent_id']) ? (int) $node['parent_id'] : 0;
            $cur = $pid > 0 ? $pid : 0;
        }
        $r['label'] = implode(' / ', array_filter($names, static function ($x) {
            return $x !== '';
        }));
    }
    unset($r);
    return $rows;
}

function collect_timezone_id(): string
{
    $cfg = collect_get_config();
    $tz = trim((string) ($cfg['timezone'] ?? ''));
    return $tz !== '' ? $tz : 'Asia/Shanghai';
}

function collect_today_ymd_shanghai(): string
{
    $dt = new DateTime('now', new DateTimeZone(collect_timezone_id()));
    return $dt->format('Y-m-d');
}

function collect_yesterday_ymd_shanghai(): string
{
    $dt = new DateTime('now', new DateTimeZone(collect_timezone_id()));
    $dt->modify('-1 day');
    return $dt->format('Y-m-d');
}

/** 明细号码是否命中特码（01–49 归一化；否则原样相等） */
function collect_item_num_matches_tema(string $num, string $teMa): bool
{
    $a = collect_normalize_lhc_ball_num($num);
    $b = collect_normalize_lhc_ball_num($teMa);
    if ($a !== null && $b !== null) {
        return $a === $b;
    }
    return trim($num) === trim($teMa);
}

function collect_parse_draw_data(string $data): array
{
    $balls = array_map('trim', explode(',', $data));
    $balls = array_values(array_filter($balls, static function ($v) {
        return $v !== '';
    }));
    $nums = array();
    foreach ($balls as $b) {
        $b = trim($b);
        if (!preg_match('/^\d{1,2}$/', $b)) {
            continue;
        }
        $n = (int) $b;
        if ($n >= 1 && $n <= 49) {
            $nums[] = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        }
    }
    return $nums;
}

function collect_kjdata_row_to_draw(?array $row): ?array
{
    if (!$row || empty($row['data'])) {
        return null;
    }
    $nums = collect_parse_draw_data((string) $row['data']);
    $te = count($nums) >= 7 ? $nums[6] : '';
    $t = $row['time'] ?? 0;
    return array(
        'periodNumber' => (string) $row['number'],
        'drawTime' => is_numeric($t) ? (int) $t : 0,
        'zhengMa' => array_slice($nums, 0, 6),
        'teMa' => $te,
        'allSeven' => $nums,
    );
}

function collect_lottery_last_number(PDO $pdo): string
{
    try {
        $stmt = $pdo->prepare(
            'SELECT number FROM ay_kjdata WHERE type = 1 AND time < :t ORDER BY CAST(number AS UNSIGNED) DESC, number DESC LIMIT 1'
        );
        $stmt->execute(array(':t' => (string) time()));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['number']) && trim((string) $row['number']) !== '') {
            $n = trim((string) $row['number']);
            if (ctype_digit($n)) {
                return $n;
            }
        }
    } catch (Throwable $e) {
    }
    return '';
}

/** 将 1～3 位期序补成与 ay_kjdata.number 常见的 7 位格式（年4+序3），年份取最近已开奖期号前四位。 */
function collect_expand_short_period_seq(PDO $pdo, string $oneToThreeDigits): string
{
    if (!preg_match('/^\d{1,3}$/', $oneToThreeDigits)) {
        return trim($oneToThreeDigits);
    }
    $suffix = str_pad((string) ((int) $oneToThreeDigits), 3, '0', STR_PAD_LEFT);
    $last = collect_lottery_last_number($pdo);
    $y = (strlen($last) >= 4 && ctype_digit(substr($last, 0, 4))) ? substr($last, 0, 4) : date('Y');
    return $y . $suffix;
}

/**
 * 下期期号不得小于等于「最近已开奖」：时间表 actionNo 展开后常仍等于上一期（如已开 2026098 仍推 098→2026098），应强制为 last+1（2026099）。
 */
function collect_lottery_suggested_at_least_after_last(PDO $pdo, string $candidate): string
{
    $last = collect_lottery_last_number($pdo);
    if ($last === '' || !ctype_digit($last)) {
        return trim($candidate);
    }
    $nextMin = (string) ((int) $last + 1);
    if ($candidate === '' || !ctype_digit($candidate)) {
        return $nextMin;
    }
    if ((int) $candidate <= (int) $last) {
        return $nextMin;
    }
    return $candidate;
}

/**
 * 用户端默认期号：优先数据库 collect_settings.default_period_no（后台可改）；空则用 config/collect.php 的 default_period_no（兼容）。
 */
function collect_get_default_period_no(PDO $pdo): string
{
    $db = trim((string) (get_setting($pdo, 'default_period_no', '') ?? ''));
    if ($db !== '') {
        return $db;
    }
    return trim((string) (collect_get_config()['default_period_no'] ?? ''));
}

function collect_lottery_suggested_period(PDO $pdo): string
{
    $fixed = collect_get_default_period_no($pdo);
    if ($fixed !== '') {
        return $fixed;
    }
    $candidate = '';
    try {
        $stmt = $pdo->prepare(
            'SELECT actionNo FROM ay_kjdata_time WHERE type = 1 AND lhcTime > :t ORDER BY lhcTime ASC LIMIT 1'
        );
        $stmt->execute(array(':t' => time()));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && trim((string) ($row['actionNo'] ?? '')) !== '') {
            $an = trim((string) $row['actionNo']);
            if (preg_match('/^\d{1,3}$/', $an)) {
                $candidate = collect_expand_short_period_seq($pdo, $an);
            } else {
                $candidate = $an;
            }
        } else {
            $last = collect_lottery_last_number($pdo);
            if ($last !== '') {
                $candidate = (string) ((int) $last + 1);
            }
        }
    } catch (Throwable $e) {
    }
    return collect_lottery_suggested_at_least_after_last($pdo, $candidate);
}

function collect_resolve_period_to_kj_number(PDO $pdo, string $periodNo): array
{
    $original = trim($periodNo);
    $resolved = $original;
    $via = 'verbatim';
    if ($original === '') {
        return array('resolved' => '', 'original' => '', 'via' => $via);
    }
    if (preg_match('/^\d{1,3}$/', $original)) {
        return array(
            'resolved' => collect_expand_short_period_seq($pdo, $original),
            'original' => $original,
            'via' => 'yearPlusSeq',
        );
    }
    if (preg_match('/^\d{8}$/', $original)) {
        try {
            $d = substr($original, 0, 4) . '-' . substr($original, 4, 2) . '-' . substr($original, 6, 2);
            $stmt = $pdo->prepare(
                "SELECT number FROM ay_kjdata WHERE type = 1 AND nyr LIKE :nyr ORDER BY CAST(number AS UNSIGNED) DESC LIMIT 1"
            );
            $stmt->execute(array(':nyr' => $d . '%'));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && trim((string) ($row['number'] ?? '')) !== '') {
                $resolved = trim((string) $row['number']);
                $via = 'byDrawDate';
            }
        } catch (Throwable $e) {
        }
    }
    return array('resolved' => $resolved, 'original' => $original, 'via' => $via);
}

/**
 * 用于 lhc_shengxiao_year_spec：取指定期号年份在配置时区内的「年中」时间戳，
 * 使生肖→球号与期号前四位年份一致，避免仍用服务器当前日历年选错表。
 */
function collect_midyear_unix_ts_shanghai(int $year): int
{
    if ($year < 1970 || $year > 2099) {
        return time();
    }
    $dt = new DateTime(sprintf('%04d-07-02', $year), new DateTimeZone(collect_timezone_id()));
    return (int) $dt->format('U');
}

/**
 * 仅凭期号字符串推断生肖表参考时间（无 DB 时导出等兜底）。
 */
function collect_zodiac_reference_unix_ts_from_period_string_only(string $periodNo): int
{
    $periodNo = trim($periodNo);
    if ($periodNo === '') {
        return time();
    }
    if (preg_match('/^\d{8}$/', $periodNo)) {
        $y = (int) substr($periodNo, 0, 4);
        $mo = (int) substr($periodNo, 4, 2);
        $d = (int) substr($periodNo, 6, 2);
        if ($y >= 2000 && $y <= 2099 && $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31) {
            $dt = DateTime::createFromFormat(
                'Y-n-j H:i:s',
                sprintf('%04d-%d-%d 12:00:00', $y, $mo, $d),
                new DateTimeZone(collect_timezone_id())
            );
            if ($dt instanceof DateTime) {
                $u = (int) $dt->format('U');
                if ($u > 0) {
                    return $u;
                }
            }
        }
    }
    if (ctype_digit($periodNo) && strlen($periodNo) >= 7) {
        $y = (int) substr($periodNo, 0, 4);
        if ($y >= 2000 && $y <= 2099) {
            return collect_midyear_unix_ts_shanghai($y);
        }
    }
    return time();
}

/**
 * 生肖→球号：优先该期在 ay_kjdata 的开奖时间；否则按期号推断年份（与 parse_submit_text 一致）。
 */
function collect_zodiac_reference_unix_ts(PDO $pdo, string $periodNo): int
{
    static $cache = array();
    $periodNo = trim($periodNo);
    $cacheKey = $periodNo !== '' ? $periodNo : '__default__';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    if ($periodNo !== '' && ctype_digit($periodNo)) {
        try {
            $stmt = $pdo->prepare('SELECT time FROM ay_kjdata WHERE type = 1 AND number = :n LIMIT 1');
            $stmt->execute(array(':n' => $periodNo));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $t = $row['time'] ?? 0;
                if (is_numeric($t) && (int) $t > 0) {
                    $cache[$cacheKey] = (int) $t;
                    return $cache[$cacheKey];
                }
            }
        } catch (Throwable $e) {
        }
    }
    $cache[$cacheKey] = collect_zodiac_reference_unix_ts_from_period_string_only($periodNo);
    return $cache[$cacheKey];
}

function collect_lottery_last_draw(PDO $pdo): ?array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT number, data, time, nyr FROM ay_kjdata WHERE type = 1 AND time < :t ORDER BY CAST(number AS UNSIGNED) DESC, number DESC LIMIT 1'
        );
        $stmt->execute(array(':t' => (string) time()));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return collect_kjdata_row_to_draw($row);
    } catch (Throwable $e) {
        return null;
    }
}

function collect_lottery_next_draw(PDO $pdo): ?array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT actionNo, lhcTime FROM ay_kjdata_time WHERE type = 1 AND lhcTime > :t ORDER BY lhcTime ASC LIMIT 1'
        );
        $stmt->execute(array(':t' => time()));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $an = trim((string) ($row['actionNo'] ?? ''));
        $out = array(
            'actionNo' => $an,
            'lhcTime' => (int) ($row['lhcTime'] ?? 0),
        );
        if ($an !== '' && strlen($an) >= 3) {
            $out['qishuShort'] = substr($an, -3);
        }
        return $out;
    } catch (Throwable $e) {
        return null;
    }
}

function collect_lottery_draw_by_period(PDO $pdo, string $periodNo): ?array
{
    $periodNo = trim($periodNo);
    if ($periodNo === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT number, data, time, nyr FROM ay_kjdata WHERE type = 1 AND number = :n LIMIT 1'
        );
        $stmt->execute(array(':n' => $periodNo));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $draw = collect_kjdata_row_to_draw($row);
        if ($draw) {
            $draw['matchHow'] = 'exactNumber';
            return $draw;
        }
        if (preg_match('/^\d{8}$/', $periodNo)) {
            $d = substr($periodNo, 0, 4) . '-' . substr($periodNo, 4, 2) . '-' . substr($periodNo, 6, 2);
            $stmt = $pdo->prepare(
                "SELECT number, data, time, nyr FROM ay_kjdata WHERE type = 1 AND nyr LIKE :nyr ORDER BY CAST(number AS UNSIGNED) DESC LIMIT 1"
            );
            $stmt->execute(array(':nyr' => $d . '%'));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $draw = collect_kjdata_row_to_draw($row);
            if ($draw) {
                $draw['matchHow'] = 'byDrawDate';
                $draw['queryUsed'] = $periodNo;
                return $draw;
            }
        }
        if (preg_match('/^\d{1,3}$/', $periodNo)) {
            $suffix = str_pad((string) ((int) $periodNo), 3, '0', STR_PAD_LEFT);
            $try = date('Y') . $suffix;
            $stmt = $pdo->prepare(
                'SELECT number, data, time, nyr FROM ay_kjdata WHERE type = 1 AND number = :n LIMIT 1'
            );
            $stmt->execute(array(':n' => $try));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $draw = collect_kjdata_row_to_draw($row);
            if ($draw) {
                $draw['matchHow'] = 'yearPlusSeq';
                $draw['queryUsed'] = $periodNo;
                return $draw;
            }
        }
    } catch (Throwable $e) {
    }
    return null;
}

function handle_lottery_context(PDO $pdo): void
{
    $sug = collect_lottery_suggested_period($pdo);
    $last = collect_lottery_last_draw($pdo);
    $next = collect_lottery_next_draw($pdo);
    $connected = $last !== null || $sug !== '' || $next !== null;
    json_response(array(
        'ok' => true,
        'lotteryConnected' => $connected,
        'suggestedPeriodNo' => $sug,
        'periodFormatHint' => '期号须与表 ay_kjdata.number 一致，一般为 7 位：年份4位+期序3位（如 2026278），不要用 20260328 这种日期当作期号。',
        'lastDraw' => $last,
        'nextDraw' => $next,
        'hint' => '开奖 data：前 6 个正码 + 第 7 个特码。收集提交侧：您输入的每一条号码均按特号统计（格式不变，可一次提交很多条）。',
    ));
}

function read_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return array();
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}

function get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM collect_settings WHERE setting_key = :k LIMIT 1');
    $stmt->execute(array(':k' => $key));
    $row = $stmt->fetch();
    return $row ? (string) $row['setting_value'] : $default;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO collect_settings(setting_key, setting_value, updated_at) VALUES(:k, :v, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $stmt->execute(array(':k' => $key, ':v' => $value));
}

function is_positive_int_string(string $value): bool
{
    return (bool) preg_match('/^[1-9]\d*$/', $value);
}

/** legacy「各」后金额尾可跟 元/园/圆（同元）/米，与 bet_line_parse_amount_tail 一致 */
function collect_normalize_legacy_amount_text(string $amountText): string
{
    $amountText = trim($amountText);
    $amountText = preg_replace('/\s*(元|园|圆|米)\s*$/u', '', $amountText);
    return trim($amountText);
}

/** 提交号码：纯数字、原样保存（不限 01–49）；长度受库字段限制，默认最多 32 位 */
function parse_number_token(string $token): ?string
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    if (!preg_match('/^\d{1,32}$/', $token)) {
        return null;
    }
    return $token;
}

/** 仅 01–49 特码球号用于计算导出汇总；其它长度或非数字忽略 */
function collect_normalize_lhc_ball_num(string $num): ?string
{
    $num = trim($num);
    if ($num === '' || !ctype_digit($num)) {
        return null;
    }
    $n = (int) $num;
    if ($n < 1 || $n > 49) {
        return null;
    }
    return $n < 10 ? ('0' . $n) : (string) $n;
}

function normalize_raw_text(string $raw): string
{
    $raw = trim($raw);
    // 斜杠分隔与逗号等价：如 12/23/34各100 → 与 12,23,34各100 相同（走 legacy 时必需；bet_line 已含 / 分隔符）
    $raw = str_replace(array("\r\n", "\r", "\n", '，', '、', ';', '；', ' ', '/', '／'), ',', $raw);
    $raw = preg_replace('/,+/', ',', $raw);
    return trim((string) $raw, ',');
}

/**
 * 将正整数 T 均分到 n 份，余数依次给前若干份。
 *
 * @return int[]
 */
function collect_distribute_int_total(int $total, int $n): array
{
    if ($n < 1 || $total < 0) {
        return array();
    }
    $base = intdiv($total, $n);
    $rem = $total % $n;
    $out = array();
    for ($i = 0; $i < $n; $i++) {
        $out[] = $base + ($i < $rem ? 1 : 0);
    }
    return $out;
}

/**
 * 与 collect_distribute_int_total 一致：每球金额列表；若全部相同则只写一项。
 *
 * @param int[] $amounts
 */
function collect_format_per_ball_amounts(array $amounts): string
{
    if ($amounts === array()) {
        return '0';
    }
    $vals = array_map('intval', $amounts);
    $first = $vals[0];
    foreach ($vals as $v) {
        if ($v !== $first) {
            return implode(',', array_map('strval', $vals));
        }
    }
    return (string) $first;
}

/**
 * 将 bet_line_parse 成功结果转为收集库条目（均按特号计）。
 * - 生肖+肖：行尾金额为「每个生肖」的总注金，在该生肖内按球号个数整数均分（虎猴龙各肖100 ⇒ 三肖各100，本行合计 300）。
 * - 生肖+号：行尾金额为「每个球号」各下多少（虎猴龙各号100 / 虎马各数100米 ⇒ 每球100，按球数累计；各数同各号）。
 * - 纯数字：每球为输入金额（与旧版一致）。
 */
function collect_items_from_bet_line_result(array $parsed): array
{
    if (empty($parsed['ok'])) {
        throw new RuntimeException((string) ($parsed['message'] ?? $parsed['error'] ?? '解析失败'));
    }
    $amtStr = trim((string) ($parsed['amount'] ?? ''));
    if (!is_positive_int_string($amtStr)) {
        throw new RuntimeException('金额须为正整数');
    }
    $amount = (int) $amtStr;
    $type = (string) ($parsed['type'] ?? '');
    $items = array();
    $total = 0;

    if ($type === 'pure_number') {
        $balls = $parsed['balls_flat'] ?? array();
        if (!is_array($balls) || !$balls) {
            throw new RuntimeException('未解析到有效号码');
        }
        foreach ($balls as $ball) {
            $num = (string) $ball;
            $items[] = array(
                'num' => $num,
                'tail' => substr($num, -1),
                'amount' => $amount,
                'is_special' => 1,
            );
            $total += $amount;
        }
    } elseif ($type === 'zodiac_hao') {
        $itemNumbers = $parsed['item_numbers'] ?? array();
        if (!is_array($itemNumbers) || !$itemNumbers) {
            throw new RuntimeException('未解析到有效号码');
        }
        $flatBalls = array();
        foreach ($itemNumbers as $balls) {
            if (!is_array($balls)) {
                continue;
            }
            foreach ($balls as $ball) {
                $flatBalls[] = (string) $ball;
            }
        }
        $nb = count($flatBalls);
        if ($nb < 1) {
            throw new RuntimeException('未解析到有效号码');
        }
        foreach ($flatBalls as $ball) {
            $a = $amount;
            $num = (string) $ball;
            $items[] = array(
                'num' => $num,
                'tail' => substr($num, -1),
                'amount' => $a,
                'is_special' => 1,
            );
            $total += $a;
        }
    } elseif ($type === 'zodiac_xiao') {
        $itemNumbers = $parsed['item_numbers'] ?? array();
        if (!is_array($itemNumbers) || !$itemNumbers) {
            throw new RuntimeException('未解析到有效号码');
        }
        foreach ($itemNumbers as $balls) {
            if (!is_array($balls) || !$balls) {
                continue;
            }
            $pool = $amount;
            $n = count($balls);
            $ballParts = collect_distribute_int_total($pool, $n);
            $bi = 0;
            foreach ($balls as $ball) {
                $a = $ballParts[$bi++];
                $num = (string) $ball;
                $items[] = array(
                    'num' => $num,
                    'tail' => substr($num, -1),
                    'amount' => $a,
                    'is_special' => 1,
                );
                $total += $a;
            }
        }
    } else {
        throw new RuntimeException('未知的解析类型');
    }

    if (!$items) {
        throw new RuntimeException('未解析到有效号码');
    }

    return array(
        'items' => $items,
        'total_amount' => $total,
        'total_items' => count($items),
        'normalized_text' => trim((string) ($parsed['raw'] ?? '')),
    );
}

/** 原「逗号 + 各」解析逻辑，仅处理单行（勿先按生肖拆段）。 */
function parse_submit_text_legacy_one_line(string $rawLine): array
{
    $normalized = normalize_raw_text($rawLine);
    if ($normalized === '') {
        throw new RuntimeException('提交内容为空');
    }

    $tokens = array_values(array_filter(array_map('trim', explode(',', $normalized)), static function ($v) {
        return $v !== '';
    }));

    if (!$tokens) {
        throw new RuntimeException('提交内容为空');
    }

    $lines = array();
    $pending = array();

    foreach ($tokens as $token) {
        if (mb_strpos($token, '各') !== false) {
            $parts = explode('各', $token, 2);
            $left = trim($parts[0] ?? '');
            $amountText = collect_normalize_legacy_amount_text((string) ($parts[1] ?? ''));
            if (!is_positive_int_string($amountText)) {
                throw new RuntimeException('格式错误：' . $token);
            }
            if ($left === '' && !$pending) {
                throw new RuntimeException('金额前缺少号码：' . $token);
            }
            $amount = (int) $amountText;
            $leftNums = array();
            if ($left !== '') {
                $kwBalls = function_exists('lhc_numbers_for_collect_keyword') ? lhc_numbers_for_collect_keyword($left) : null;
                if ($kwBalls !== null && $kwBalls !== array()) {
                    foreach ($kwBalls as $kb) {
                        $leftNums[] = $kb;
                    }
                } else {
                    $leftNorm = normalize_raw_text($left);
                    $leftTokens = array_values(array_filter(array_map('trim', explode(',', $leftNorm)), static function ($v) {
                        return $v !== '';
                    }));
                    foreach ($leftTokens as $lt) {
                        $num = parse_number_token($lt);
                        if ($num === null) {
                            throw new RuntimeException('号码非法：' . $lt);
                        }
                        $leftNums[] = $num;
                    }
                }
            }
            $allNums = array_merge($pending, $leftNums);
            $pending = array();
            foreach ($allNums as $num) {
                $lines[] = array('num' => $num, 'amount' => $amount);
            }
            continue;
        }

        $num = parse_number_token($token);
        if ($num !== null) {
            $pending[] = $num;
            continue;
        }

        $tokenAmount = collect_normalize_legacy_amount_text($token);
        if (is_positive_int_string($tokenAmount)) {
            if (!$pending) {
                throw new RuntimeException('金额前缺少号码：' . $token);
            }
            $amount = (int) $tokenAmount;
            foreach ($pending as $pn) {
                $lines[] = array('num' => $pn, 'amount' => $amount);
            }
            $pending = array();
            continue;
        }

        throw new RuntimeException('无法识别片段：' . $token);
    }

    if ($pending) {
        throw new RuntimeException('号码缺少金额：' . implode(',', $pending));
    }

    $items = array();
    $total = 0;
    foreach ($lines as $line) {
        $num = $line['num'];
        $amount = $line['amount'];
        $items[] = array(
            'num' => $num,
            'tail' => substr($num, -1),
            'amount' => $amount,
            'is_special' => 1,
        );
        $total += $amount;
    }

    return array(
        'items' => $items,
        'total_amount' => $total,
        'total_items' => count($items),
        'normalized_text' => $normalized,
    );
}

/**
 * @param int|null $zodiacMapUnixTs 传给 bet_line_parse 的开奖参考时间；null 则用当前 time()（仅无期号场景）
 */
function parse_submit_text(string $raw, ?int $zodiacMapUnixTs = null): array
{
    $raw = trim($raw);
    if ($raw === '') {
        throw new RuntimeException('提交内容为空');
    }

    $lineParts = preg_split('/\R/u', $raw);
    $allItems = array();
    $totalAmount = 0;
    $normChunks = array();
    $mapTs = $zodiacMapUnixTs !== null ? (int) $zodiacMapUnixTs : time();

    foreach ($lineParts as $part) {
        $line = trim((string) $part);
        if ($line === '') {
            continue;
        }

        $bet = bet_line_parse($line, $mapTs);
        if (!empty($bet['ok'])) {
            $block = collect_items_from_bet_line_result($bet);
            $allItems = array_merge($allItems, $block['items']);
            $totalAmount += $block['total_amount'];
            $normChunks[] = $block['normalized_text'];
            continue;
        }

        $block = parse_submit_text_legacy_one_line($line);
        $allItems = array_merge($allItems, $block['items']);
        $totalAmount += $block['total_amount'];
        $normChunks[] = $block['normalized_text'];
    }

    if (!$allItems) {
        throw new RuntimeException('提交内容为空');
    }

    if (count($allItems) > 3000) {
        throw new RuntimeException('单次提交条目过多（上限 3000 条），请拆成多次提交');
    }

    $merged = collect_merge_duplicate_ball_items($allItems, $totalAmount);
    $allItems = $merged['items'];
    $totalAmount = $merged['total_amount'];

    return array(
        'items' => $allItems,
        'total_amount' => $totalAmount,
        'total_items' => count($allItems),
        'normalized_text' => implode("\n", $normChunks),
    );
}

/**
 * 平码提交：写入 collect_submission_bets。
 *
 * @param array<int, array<string, mixed>> $bets
 */
function collect_save_submission_bets(PDO $pdo, int $submissionId, string $periodNo, array $bets): void
{
    if (!$bets) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO collect_submission_bets(
            submission_id, period_no, play_type, play_label, ball_scope, selection_type,
            selection_json, groups_json, group_count, amount_per_group, total_amount,
            amount_mode, raw_segment, sort_order, created_at
         ) VALUES (
            :submission_id, :period_no, :play_type, :play_label, :ball_scope, :selection_type,
            :selection_json, :groups_json, :group_count, :amount_per_group, :total_amount,
            :amount_mode, :raw_segment, :sort_order, NOW()
         )'
    );
    $order = 0;
    foreach ($bets as $bet) {
        if (!is_array($bet)) {
            continue;
        }
        $bet = pingma_recalc_bet_amounts($bet);
        $stmt->execute(array(
            ':submission_id' => $submissionId,
            ':period_no' => $periodNo,
            ':play_type' => (string) ($bet['play_type'] ?? ''),
            ':play_label' => (string) ($bet['play_label'] ?? ''),
            ':ball_scope' => 'pingma',
            ':selection_type' => (string) ($bet['selection_type'] ?? 'number'),
            ':selection_json' => json_encode($bet['selection'] ?? array(), JSON_UNESCAPED_UNICODE),
            ':groups_json' => json_encode($bet['groups'] ?? array(), JSON_UNESCAPED_UNICODE),
            ':group_count' => (int) ($bet['group_count'] ?? 0),
            ':amount_per_group' => (float) ($bet['amount_per_group'] ?? 0),
            ':total_amount' => (float) ($bet['total_amount'] ?? 0),
            ':amount_mode' => (string) ($bet['amount_mode'] ?? 'per_group'),
            ':raw_segment' => (string) ($bet['display_text'] ?? $bet['raw_segment'] ?? ''),
            ':sort_order' => $order++,
        ));
    }
}

/**
 * 从 parsed_json 或原文生成平码格式化展示。
 *
 * @return array{formatted_text:string,bet_summary:string,bets:array<int,array<string,mixed>>}
 */
function collect_pingma_looks_formatted_display(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }
    return (bool) preg_match('/(?:\d+组×|整单\d)/u', $text);
}

/**
 * @param string[] $sel
 * @return array{selection_display:string,numbers_display:string}
 */
function collect_pingma_expand_selection_display(string $selType, array $sel, int $zodiacTs): array
{
    if ($selType === 'zodiac') {
        $parts = array();
        $expanded = array();
        foreach ($sel as $z) {
            $z = trim((string) $z);
            if ($z === '') {
                continue;
            }
            $balls = collect_pingma_sort_ball_tokens(lhc_numbers_for_shengxiao($z, $zodiacTs));
            $parts[] = $z . '→' . implode(',', $balls);
            foreach ($balls as $b) {
                $expanded[$b] = true;
            }
        }
        return array(
            'selection_display' => implode(' ', $parts),
            'numbers_display' => implode(',', collect_pingma_sort_ball_tokens(array_keys($expanded))),
        );
    }
    $nums = collect_pingma_sort_ball_tokens($sel);
    $joined = implode(',', $nums);
    return array(
        'selection_display' => $joined,
        'numbers_display' => $joined,
    );
}

/**
 * 整单平码提交：汇总选号/选肖与全部球号（用户端「我的提交」用）。
 *
 * @return array{selection_display:string,numbers_display:string}
 */
function collect_pingma_submission_selection_summary(PDO $pdo, ?array $decoded, string $periodNo): array
{
    $bets = (is_array($decoded) && !empty($decoded['bets']) && is_array($decoded['bets'])) ? $decoded['bets'] : array();
    if (!$bets) {
        return array('selection_display' => '', 'numbers_display' => '');
    }
    $zodiacTs = $periodNo !== ''
        ? collect_zodiac_reference_unix_ts($pdo, $periodNo)
        : time();
    $parts = array();
    $expanded = array();
    foreach ($bets as $bet) {
        if (!is_array($bet)) {
            continue;
        }
        $sel = is_array($bet['selection'] ?? null) ? $bet['selection'] : array();
        $disp = collect_pingma_expand_selection_display((string) ($bet['selection_type'] ?? 'number'), $sel, $zodiacTs);
        $label = trim((string) ($bet['play_label'] ?? ''));
        if ($disp['selection_display'] !== '') {
            $parts[] = ($label !== '' ? $label . '：' : '') . $disp['selection_display'];
        }
        foreach (explode(',', $disp['numbers_display']) as $n) {
            $n = trim($n);
            if ($n !== '') {
                $expanded[$n] = true;
            }
        }
    }
    return array(
        'selection_display' => implode("\n", $parts),
        'numbers_display' => implode(',', collect_pingma_sort_ball_tokens(array_keys($expanded))),
    );
}

/**
 * @param array<string, mixed> $parsed pingma_parse_submit_text 返回值
 * @return array<string, mixed>
 */
function collect_pingma_preview_fields_from_parsed(array $parsed, ?PDO $pdo = null, string $periodNo = ''): array
{
    $previewLines = array();
    $slimBets = array();
    foreach ($parsed['bets'] as $bet) {
        if (!is_array($bet)) {
            continue;
        }
        $line = pingma_format_bet_line(pingma_recalc_bet_amounts($bet));
        $previewLines[] = $line;
        $slimBets[] = array(
            'play_label' => (string) ($bet['play_label'] ?? ''),
            'display_text' => $line,
            'selection_type' => (string) ($bet['selection_type'] ?? 'number'),
        );
    }
    $formatted = pingma_format_bets_text($parsed['bets']);
    if ($formatted === '' && $previewLines) {
        $formatted = implode("\n", $previewLines);
    }
    return array(
        'bets' => $slimBets,
        'previewLines' => $previewLines,
        'previewText' => $formatted,
        'normalizedText' => $parsed['normalized_text'],
        'formattedText' => $formatted,
    );
}

/**
 * 用户端「我的提交」平码展示：玩法 + 选号 + 组数×单价=总价（与后台一致）。
 *
 * @return array{formatted_text:string,bet_summary:string}
 */
function collect_pingma_build_user_display(?array $decoded, string $rawFallback = ''): array
{
    $rawFallback = trim($rawFallback);
    if (is_array($decoded)) {
        $cached = trim((string) ($decoded['formatted_text'] ?? ''));
        if ($cached !== '') {
            return array(
                'formatted_text' => $cached,
                'bet_summary' => $cached,
            );
        }
        if (!empty($decoded['bets']) && is_array($decoded['bets'])) {
            $bets = pingma_attach_display_fields($decoded['bets']);
            $formatted = pingma_format_bets_text($bets);
            if ($formatted !== '') {
                return array(
                    'formatted_text' => $formatted,
                    'bet_summary' => $formatted,
                );
            }
        }
    }
    if ($rawFallback !== '' && collect_pingma_looks_formatted_display($rawFallback)) {
        return array(
            'formatted_text' => $rawFallback,
            'bet_summary' => $rawFallback,
        );
    }
    $parseSource = '';
    if (is_array($decoded)) {
        $parseSource = trim((string) ($decoded['original_raw_text'] ?? ''));
    }
    if ($parseSource === '') {
        $parseSource = $rawFallback;
    }
    if ($parseSource !== '' && !collect_pingma_looks_formatted_display($parseSource)) {
        try {
            $parsed = pingma_parse_submit_text($parseSource);
            $formatted = (string) ($parsed['formatted_text'] ?? $parsed['normalized_text'] ?? '');
            if ($formatted !== '') {
                return array(
                    'formatted_text' => $formatted,
                    'bet_summary' => $formatted,
                );
            }
        } catch (Throwable $e) {
            // fall through
        }
    }
    if ($rawFallback !== '') {
        return array(
            'formatted_text' => $rawFallback,
            'bet_summary' => $rawFallback,
        );
    }
    return array(
        'formatted_text' => '',
        'bet_summary' => '',
    );
}

function collect_pingma_build_display(?array $decoded, string $rawFallback = ''): array
{
    $rawFallback = trim($rawFallback);
    if (is_array($decoded)) {
        $cached = trim((string) ($decoded['formatted_text'] ?? ''));
        if ($cached !== '') {
            $bets = (!empty($decoded['bets']) && is_array($decoded['bets'])) ? $decoded['bets'] : array();
            if ($bets) {
                $bets = pingma_attach_display_fields($bets);
            }
            return array(
                'formatted_text' => $cached,
                'bet_summary' => $bets ? pingma_format_bets_summary($bets) : $cached,
                'bets' => $bets,
                'total_amount' => $bets
                    ? pingma_sum_bets_total($bets)
                    : (float) ($decoded['total_amount'] ?? 0),
            );
        }
    }

    if ($rawFallback !== '' && collect_pingma_looks_formatted_display($rawFallback)) {
        return array(
            'formatted_text' => $rawFallback,
            'bet_summary' => $rawFallback,
            'bets' => array(),
            'total_amount' => is_array($decoded) ? (float) ($decoded['total_amount'] ?? 0) : 0.0,
        );
    }

    $parseSource = '';
    if (is_array($decoded)) {
        $parseSource = trim((string) ($decoded['original_raw_text'] ?? ''));
    }
    if ($parseSource === '') {
        $parseSource = $rawFallback;
    }
    if ($parseSource !== '' && !collect_pingma_looks_formatted_display($parseSource)) {
        try {
            $parsed = pingma_parse_submit_text($parseSource);
            return array(
                'formatted_text' => $parsed['formatted_text'] ?? $parsed['normalized_text'],
                'bet_summary' => pingma_format_bets_summary($parsed['bets']),
                'bets' => $parsed['bets'],
                'total_amount' => $parsed['total_amount'],
            );
        } catch (Throwable $e) {
            // fall through
        }
    }

    $bets = array();
    if (is_array($decoded) && !empty($decoded['bets']) && is_array($decoded['bets'])) {
        $bets = $decoded['bets'];
    }
    if ($bets) {
        $bets = pingma_attach_display_fields($bets);
        $formatted = pingma_format_bets_text($bets);
        return array(
            'formatted_text' => $formatted,
            'bet_summary' => pingma_format_bets_summary($bets),
            'bets' => $bets,
            'total_amount' => pingma_sum_bets_total($bets),
        );
    }

    if ($rawFallback !== '') {
        return array(
            'formatted_text' => $rawFallback,
            'bet_summary' => $rawFallback,
            'bets' => array(),
            'total_amount' => is_array($decoded) ? (float) ($decoded['total_amount'] ?? 0) : 0.0,
        );
    }

    return array(
        'formatted_text' => '',
        'bet_summary' => '',
        'bets' => array(),
        'total_amount' => 0.0,
    );
}

function collect_play_type_label_zh(string $playType): string
{
    static $map = array(
        'erzhonger' => '二中二',
        'sanzhongsan' => '三中三',
        'yixiao' => '平特一肖',
        'erxiao' => '二肖',
        'sanxiao' => '三肖',
        'sixiao' => '四肖',
        'wuxiao' => '五肖',
        'liuxiao' => '六肖',
        'qixiao' => '七肖',
    );
    return $map[$playType] ?? $playType;
}

/**
 * @param string[] $tokens
 * @return string[]
 */
function collect_pingma_sort_ball_tokens(array $tokens): array
{
    $out = array();
    foreach ($tokens as $t) {
        $t = trim((string) $t);
        if ($t === '') {
            continue;
        }
        if (preg_match('/^\d{1,2}$/', $t)) {
            $out[] = str_pad((string) ((int) $t), 2, '0', STR_PAD_LEFT);
        } else {
            $out[] = $t;
        }
    }
    $out = array_values(array_unique($out));
    sort($out, SORT_STRING);
    return $out;
}

/**
 * 特码 01–49 覆盖：未买号码；若全部已买则返回购买金额升序排行。
 *
 * @param array<int, array<string, mixed>> $numberStatsAll
 * @return array<string, mixed>
 */
function collect_tema_coverage_stats(array $numberStatsAll): array
{
    $amounts = array();
    $itemCounts = array();
    foreach ($numberStatsAll as $row) {
        if (!is_array($row)) {
            continue;
        }
        $n = collect_normalize_lhc_ball_num((string) ($row['num'] ?? ''));
        if ($n === null) {
            continue;
        }
        $amounts[$n] = (int) ($amounts[$n] ?? 0) + (int) ($row['total_amount'] ?? 0);
        $itemCounts[$n] = (int) ($itemCounts[$n] ?? 0) + (int) ($row['item_count'] ?? 0);
    }
    $unbought = array();
    $bought = array();
    for ($i = 1; $i <= 49; $i++) {
        $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        if (isset($amounts[$n])) {
            $bought[] = $n;
        } else {
            $unbought[] = $n;
        }
    }
    $allBought = count($unbought) === 0;
    $rankAsc = array();
    if ($allBought) {
        foreach ($amounts as $num => $amt) {
            $rankAsc[] = array(
                'num' => $num,
                'total_amount' => $amt,
                'item_count' => (int) ($itemCounts[$num] ?? 0),
            );
        }
        usort($rankAsc, static function ($a, $b) {
            if ($a['total_amount'] !== $b['total_amount']) {
                return $a['total_amount'] - $b['total_amount'];
            }
            return strcmp((string) $a['num'], (string) $b['num']);
        });
    }
    return array(
        'bought_numbers' => $bought,
        'unbought_numbers' => $unbought,
        'bought_number_count' => count($bought),
        'unbought_number_count' => count($unbought),
        'bought_numbers_display' => implode(',', $bought),
        'unbought_numbers_display' => implode(',', $unbought),
        'all_bought' => $allBought,
        'number_rank_asc' => $rankAsc,
    );
}

/**
 * 平码「按玩法汇总」补充：汇总全部选号/选肖，肖类展开为对应球号（按期号年份表）。
 *
 * @param array<int, array<string, mixed>> $playTypeStats
 * @return array<int, array<string, mixed>>
 */
function collect_pingma_enrich_play_type_stats(PDO $pdo, array $playTypeStats, string $where, array $params): array
{
    if (!$playTypeStats || !collect_bets_table_exists($pdo)) {
        return $playTypeStats;
    }
    $selStmt = $pdo->prepare(
        "SELECT b.play_type, b.play_label, b.selection_type, b.selection_json, s.period_no
         FROM collect_submission_bets b
         INNER JOIN collect_submissions s ON s.id = b.submission_id
         WHERE {$where}"
    );
    $selStmt->execute($params);
    /** @var array<string, array{zodiacs: array<string, true>, numbers: array<string, true>, zodiac_balls: array<string, array<string, true>>, expanded: array<string, true>}> $agg */
    $agg = array();
    while ($row = $selStmt->fetch(PDO::FETCH_ASSOC)) {
        $playType = (string) ($row['play_type'] ?? '');
        $playLabel = (string) ($row['play_label'] ?? '');
        $selType = (string) ($row['selection_type'] ?? 'number');
        $key = $playType . "\0" . $playLabel . "\0" . $selType;
        if (!isset($agg[$key])) {
            $agg[$key] = array(
                'zodiacs' => array(),
                'numbers' => array(),
                'zodiac_balls' => array(),
                'expanded' => array(),
            );
        }
        $sel = json_decode((string) ($row['selection_json'] ?? '[]'), true);
        if (!is_array($sel)) {
            continue;
        }
        $periodNo = trim((string) ($row['period_no'] ?? ''));
        $zodiacTs = $periodNo !== ''
            ? collect_zodiac_reference_unix_ts($pdo, $periodNo)
            : time();
        foreach ($sel as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            if ($selType === 'zodiac') {
                $agg[$key]['zodiacs'][$item] = true;
                if (!isset($agg[$key]['zodiac_balls'][$item])) {
                    $agg[$key]['zodiac_balls'][$item] = array();
                }
                $balls = lhc_numbers_for_shengxiao($item, $zodiacTs);
                foreach ($balls as $ball) {
                    $ball = str_pad((string) ((int) $ball), 2, '0', STR_PAD_LEFT);
                    $agg[$key]['zodiac_balls'][$item][$ball] = true;
                    $agg[$key]['expanded'][$ball] = true;
                }
            } else {
                $ball = str_pad((string) ((int) $item), 2, '0', STR_PAD_LEFT);
                $agg[$key]['numbers'][$ball] = true;
                $agg[$key]['expanded'][$ball] = true;
            }
        }
    }

    foreach ($playTypeStats as &$stat) {
        $playType = (string) ($stat['play_type'] ?? '');
        $playLabel = (string) ($stat['play_label'] ?? '');
        $selType = (string) ($stat['selection_type'] ?? 'number');
        $key = $playType . "\0" . $playLabel . "\0" . $selType;
        $bucket = $agg[$key] ?? array(
            'zodiacs' => array(),
            'numbers' => array(),
            'zodiac_balls' => array(),
            'expanded' => array(),
        );
        $stat['selection_display'] = '';
        $stat['numbers_display'] = '';
        if ($selType === 'zodiac') {
            $zodiacs = array_keys($bucket['zodiacs']);
            sort($zodiacs, SORT_STRING);
            $parts = array();
            foreach ($zodiacs as $z) {
                $balls = collect_pingma_sort_ball_tokens(array_keys($bucket['zodiac_balls'][$z] ?? array()));
                $parts[] = $z . '→' . implode(',', $balls);
            }
            $stat['selection_display'] = implode(' ', $parts);
            $stat['numbers_display'] = implode(',', collect_pingma_sort_ball_tokens(array_keys($bucket['expanded'])));
        } else {
            $nums = collect_pingma_sort_ball_tokens(array_keys($bucket['numbers']));
            $stat['selection_display'] = implode(',', $nums);
            $stat['numbers_display'] = implode(',', $nums);
        }
    }
    unset($stat);

    return $playTypeStats;
}

/** @return array<string, mixed> */
function collect_pingma_selection_agg_empty(string $selType): array
{
    return array(
        'selection_type' => $selType,
        'zodiacs' => array(),
        'numbers' => array(),
        'zodiac_balls' => array(),
        'expanded' => array(),
    );
}

/**
 * @param array<string, mixed> $agg
 * @param string[] $selection
 */
function collect_pingma_selection_agg_add_items(array &$agg, array $selection, string $selType, int $zodiacTs): void
{
    foreach ($selection as $item) {
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }
        if ($selType === 'zodiac') {
            $agg['zodiacs'][$item] = true;
            if (!isset($agg['zodiac_balls'][$item])) {
                $agg['zodiac_balls'][$item] = array();
            }
            foreach (lhc_numbers_for_shengxiao($item, $zodiacTs) as $ball) {
                $ball = str_pad((string) ((int) $ball), 2, '0', STR_PAD_LEFT);
                $agg['zodiac_balls'][$item][$ball] = true;
                $agg['expanded'][$ball] = true;
            }
        } else {
            $ball = str_pad((string) ((int) $item), 2, '0', STR_PAD_LEFT);
            $agg['numbers'][$ball] = true;
            $agg['expanded'][$ball] = true;
        }
    }
}

/**
 * @param array<string, mixed> $agg
 * @return array{selection_display:string,numbers_display:string,selection_type:string}
 */
function collect_pingma_selection_agg_format_display(array $agg): array
{
    $selType = (string) ($agg['selection_type'] ?? 'number');
    if ($selType === 'zodiac') {
        $zodiacs = array_keys($agg['zodiacs'] ?? array());
        sort($zodiacs, SORT_STRING);
        $parts = array();
        foreach ($zodiacs as $z) {
            $balls = collect_pingma_sort_ball_tokens(array_keys($agg['zodiac_balls'][$z] ?? array()));
            $parts[] = $z . '→' . implode(',', $balls);
        }
        return array(
            'selection_type' => 'zodiac',
            'selection_display' => implode(' ', $parts),
            'numbers_display' => implode(',', collect_pingma_sort_ball_tokens(array_keys($agg['expanded'] ?? array()))),
        );
    }
    $nums = collect_pingma_sort_ball_tokens(array_keys($agg['numbers'] ?? array()));
    return array(
        'selection_type' => 'number',
        'selection_display' => implode(',', $nums),
        'numbers_display' => implode(',', $nums),
    );
}

/**
 * 平码球号结构化项（前端着色展示用）。
 *
 * @return array<int, array{number:string,wave:string,zodiac:string}>
 */
function collect_pingma_build_ball_items(array $balls, int $drawTs): array
{
    $items = array();
    foreach ($balls as $b) {
        $ball = str_pad((string) ((int) $b), 2, '0', STR_PAD_LEFT);
        $items[] = array(
            'number' => $ball,
            'wave' => lhc_bose_name_for_number($ball),
            'zodiac' => lhc_shengxiao_for_number($ball, $drawTs),
        );
    }
    return $items;
}

/** 单球展示：号码(波色/生肖)。 */
function collect_pingma_format_ball_display(string $ball, int $drawTs): string
{
    $ball = str_pad((string) ((int) $ball), 2, '0', STR_PAD_LEFT);
    $wave = lhc_bose_name_for_number($ball);
    $sx = lhc_shengxiao_for_number($ball, $drawTs);
    $meta = array();
    if ($wave !== '') {
        $meta[] = $wave;
    }
    if ($sx !== '') {
        $meta[] = $sx;
    }
    return $ball . ($meta ? '(' . implode('/', $meta) . ')' : '');
}

/** 开奖七球：号码(波色/生肖) 展示。 */
function collect_pingma_format_draw_balls_display(array $zhengMa, string $teMa, int $drawTs): string
{
    $parts = array();
    foreach (array_merge($zhengMa, $teMa !== '' ? array($teMa) : array()) as $ball) {
        $parts[] = collect_pingma_format_ball_display((string) $ball, $drawTs);
    }
    return implode(', ', $parts);
}

/**
 * 单条平码 bet 行：选肖/选号 + 展开球号（明细表用）。
 *
 * @param array<string, mixed> $betRow
 * @return array{selection_display:string,numbers_display:string}
 */
function collect_pingma_bet_selection_display(PDO $pdo, array $betRow): array
{
    $sel = $betRow['selection'] ?? array();
    if (!is_array($sel)) {
        $sel = array();
    }
    $periodNo = trim((string) ($betRow['period_no'] ?? ''));
    $zodiacTs = $periodNo !== ''
        ? collect_zodiac_reference_unix_ts($pdo, $periodNo)
        : time();
    return collect_pingma_expand_selection_display((string) ($betRow['selection_type'] ?? 'number'), $sel, $zodiacTs);
}

/**
 * 排行用玩法名（含复式前缀）。
 *
 * @param array<string, mixed> $betRow
 */
function collect_pingma_rank_play_label(array $betRow): string
{
    $label = trim((string) ($betRow['play_label'] ?? ''));
    if ($label === '') {
        return '未知';
    }
    $raw = trim((string) ($betRow['raw_segment'] ?? ''));
    if ($raw !== '' && strpos($raw, '复式') === 0 && strpos($label, '复式') !== 0) {
        return '复式' . $label;
    }
    return $label;
}

/**
 * @param array<string, int> $counts
 * @param array<string, array<string, int>> $typeCountsByKey
 */
function collect_pingma_rank_add_hit(array &$counts, array &$typeCountsByKey, string $key, string $playLabel): void
{
    $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
    if (!isset($typeCountsByKey[$key])) {
        $typeCountsByKey[$key] = array();
    }
    $typeCountsByKey[$key][$playLabel] = (int) ($typeCountsByKey[$key][$playLabel] ?? 0) + 1;
}

/**
 * @param array<string, int> $typeCounts
 */
function collect_pingma_format_play_types_display(array $typeCounts): string
{
    if (!$typeCounts) {
        return '';
    }
    uasort($typeCounts, static function ($a, $b) {
        if ($b !== $a) {
            return $b - $a;
        }
        return 0;
    });
    $parts = array();
    foreach ($typeCounts as $label => $cnt) {
        $parts[] = $label . '×' . (int) $cnt;
    }
    return implode(' ', $parts);
}

/**
 * @param array<string, int> $counts
 * @param array<string, array<string, int>> $typeCountsByKey
 * @return array<int, array<string, mixed>>
 */
function collect_pingma_build_count_rank(array $counts, string $valueKey, array $typeCountsByKey = array()): array
{
    $list = array();
    foreach ($counts as $key => $cnt) {
        $cnt = (int) $cnt;
        if ($cnt <= 0) {
            continue;
        }
        $types = $typeCountsByKey[$key] ?? array();
        $list[] = array(
            $valueKey => (string) $key,
            'count' => $cnt,
            'play_types' => $types,
            'play_types_display' => collect_pingma_format_play_types_display($types),
        );
    }
    usort($list, static function ($a, $b) use ($valueKey) {
        if ($b['count'] !== $a['count']) {
            return $b['count'] - $a['count'];
        }
        return strcmp((string) $a[$valueKey], (string) $b[$valueKey]);
    });
    return $list;
}

/**
 * 平码覆盖统计：已买 / 未买 的球号(01–49) 与 生肖；含出现次数排行。
 *
 * @return array<string, mixed>
 */
function collect_pingma_coverage_stats(PDO $pdo, string $where, array $params, string $periodFilter): array
{
    $empty = array(
        'bought_numbers' => array(),
        'unbought_numbers' => array(),
        'bought_zodiacs' => array(),
        'unbought_zodiacs' => array(),
        'unbought_zodiac_details' => array(),
        'number_rank' => array(),
        'zodiac_rank' => array(),
        'bought_numbers_display' => '',
        'unbought_numbers_display' => '',
        'bought_zodiacs_display' => '',
        'unbought_zodiacs_display' => '',
        'bought_number_count' => 0,
        'unbought_number_count' => 49,
        'bought_zodiac_count' => 0,
        'unbought_zodiac_count' => 0,
    );
    if (!collect_bets_table_exists($pdo)) {
        $allNums = array();
        for ($i = 1; $i <= 49; $i++) {
            $allNums[] = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        }
        $defaultTs = $periodFilter !== ''
            ? collect_zodiac_reference_unix_ts($pdo, $periodFilter)
            : time();
        $allZodiacs = array_keys(lhc_shengxiao_year_spec($defaultTs));
        $details = array();
        foreach ($allZodiacs as $z) {
            $balls = collect_pingma_sort_ball_tokens(lhc_numbers_for_shengxiao($z, $defaultTs));
            $details[] = array(
                'zodiac' => $z,
                'numbers' => implode(',', $balls),
            );
        }
        $empty['unbought_numbers'] = $allNums;
        $empty['unbought_numbers_display'] = implode(',', $allNums);
        $empty['unbought_zodiacs'] = $allZodiacs;
        $empty['unbought_zodiacs_display'] = implode('', $allZodiacs);
        $empty['unbought_zodiac_details'] = $details;
        $empty['unbought_zodiac_count'] = count($allZodiacs);
        return $empty;
    }

    $defaultTs = $periodFilter !== ''
        ? collect_zodiac_reference_unix_ts($pdo, $periodFilter)
        : time();
    $allZodiacs = array_keys(lhc_shengxiao_year_spec($defaultTs));

    $numberCounts = array();
    $zodiacCounts = array();
    $numberTypeCounts = array();
    $zodiacTypeCounts = array();
    $stmt = $pdo->prepare(
        "SELECT b.selection_type, b.selection_json, b.play_label, b.raw_segment, s.period_no
         FROM collect_submission_bets b
         INNER JOIN collect_submissions s ON s.id = b.submission_id
         WHERE {$where}"
    );
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $selType = (string) ($row['selection_type'] ?? 'number');
        $sel = json_decode((string) ($row['selection_json'] ?? '[]'), true);
        if (!is_array($sel)) {
            continue;
        }
        $playLabel = collect_pingma_rank_play_label($row);
        $periodNo = trim((string) ($row['period_no'] ?? ''));
        $zodiacTs = $periodNo !== ''
            ? collect_zodiac_reference_unix_ts($pdo, $periodNo)
            : $defaultTs;
        foreach ($sel as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            if ($selType === 'zodiac') {
                collect_pingma_rank_add_hit($zodiacCounts, $zodiacTypeCounts, $item, $playLabel);
                foreach (lhc_numbers_for_shengxiao($item, $zodiacTs) as $ball) {
                    $n = str_pad((string) ((int) $ball), 2, '0', STR_PAD_LEFT);
                    collect_pingma_rank_add_hit($numberCounts, $numberTypeCounts, $n, $playLabel);
                }
            } else {
                $n = str_pad((string) ((int) $item), 2, '0', STR_PAD_LEFT);
                collect_pingma_rank_add_hit($numberCounts, $numberTypeCounts, $n, $playLabel);
            }
        }
    }

    $boughtNumList = collect_pingma_sort_ball_tokens(array_keys($numberCounts));
    $unboughtNumList = array();
    for ($i = 1; $i <= 49; $i++) {
        $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        if (!isset($numberCounts[$n])) {
            $unboughtNumList[] = $n;
        }
    }

    $boughtZodiacList = array();
    $unboughtZodiacList = array();
    $unboughtDetails = array();
    foreach ($allZodiacs as $z) {
        if (isset($zodiacCounts[$z])) {
            $boughtZodiacList[] = $z;
        } else {
            $unboughtZodiacList[] = $z;
            $balls = collect_pingma_sort_ball_tokens(lhc_numbers_for_shengxiao($z, $defaultTs));
            $unboughtDetails[] = array(
                'zodiac' => $z,
                'numbers' => implode(',', $balls),
            );
        }
    }

    $numberRank = collect_pingma_build_count_rank($numberCounts, 'num', $numberTypeCounts);
    $zodiacRank = collect_pingma_build_count_rank($zodiacCounts, 'zodiac', $zodiacTypeCounts);

    return array(
        'bought_numbers' => $boughtNumList,
        'unbought_numbers' => $unboughtNumList,
        'bought_zodiacs' => $boughtZodiacList,
        'unbought_zodiacs' => $unboughtZodiacList,
        'unbought_zodiac_details' => $unboughtDetails,
        'number_rank' => $numberRank,
        'zodiac_rank' => $zodiacRank,
        'bought_numbers_display' => implode(',', $boughtNumList),
        'unbought_numbers_display' => implode(',', $unboughtNumList),
        'bought_zodiacs_display' => implode('', $boughtZodiacList),
        'unbought_zodiacs_display' => implode('', $unboughtZodiacList),
        'bought_number_count' => count($boughtNumList),
        'unbought_number_count' => count($unboughtNumList),
        'bought_zodiac_count' => count($boughtZodiacList),
        'unbought_zodiac_count' => count($unboughtZodiacList),
    );
}

/** 筛选范围内提交单中最常见的期号（用于未填期号时自动对照开奖）。 */
function collect_guess_period_from_submissions(PDO $pdo, string $where, array $params): string
{
    try {
        $stmt = $pdo->prepare(
            "SELECT TRIM(s.period_no) AS p, COUNT(*) AS c
             FROM collect_submissions s
             WHERE {$where} AND TRIM(s.period_no) <> ''
             GROUP BY TRIM(s.period_no)
             ORDER BY c DESC, p DESC
             LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && trim((string) ($row['p'] ?? '')) !== '') {
            return trim((string) $row['p']);
        }
    } catch (Throwable $e) {
    }
    return '';
}

/**
 * 解析后台统计用的开奖对照：筛选期号 → 提交单期号 → 最近已开奖。
 *
 * @return array{draw: ?array, meta: array<string, string>}
 */
function collect_resolve_lottery_draw_for_admin(PDO $pdo, string $periodFilter, string $where, array $params): array
{
    $meta = array(
        'source' => '',
        'periodInput' => trim($periodFilter),
        'periodUsed' => '',
        'periodGuess' => '',
    );
    $tries = array();
    $seen = array();

    $addTry = static function (string $period, string $source, ?array $prefetchDraw = null) use (&$tries, &$seen): void {
        $period = trim($period);
        if ($period === '' || isset($seen[$period])) {
            return;
        }
        $seen[$period] = true;
        $tries[] = array('period' => $period, 'source' => $source, 'draw' => $prefetchDraw);
    };

    if ($periodFilter !== '') {
        $addTry($periodFilter, 'filter');
        $resolved = collect_resolve_period_to_kj_number($pdo, $periodFilter);
        if ($resolved['resolved'] !== '' && $resolved['resolved'] !== $periodFilter) {
            $addTry($resolved['resolved'], 'filter_resolved');
        }
    }

    $guess = collect_guess_period_from_submissions($pdo, $where, $params);
    $meta['periodGuess'] = $guess;
    if ($guess !== '') {
        $addTry($guess, 'submission');
        $resolvedGuess = collect_resolve_period_to_kj_number($pdo, $guess);
        if ($resolvedGuess['resolved'] !== '' && $resolvedGuess['resolved'] !== $guess) {
            $addTry($resolvedGuess['resolved'], 'submission_resolved');
        }
    }

    $last = collect_lottery_last_draw($pdo);
    if ($last && !empty($last['periodNumber'])) {
        $addTry((string) $last['periodNumber'], 'last_draw', $last);
    }

    foreach ($tries as $t) {
        $draw = $t['draw'] ?? collect_lottery_draw_by_period($pdo, $t['period']);
        if ($draw && !empty($draw['zhengMa'])) {
            $meta['source'] = (string) $t['source'];
            $meta['periodUsed'] = (string) ($draw['periodNumber'] ?? $t['period']);
            return array('draw' => $draw, 'meta' => $meta);
        }
    }

    return array('draw' => null, 'meta' => $meta);
}

/**
 * 读取平码 bet 行（bets 表优先；无记录时从 parsed_json 回退）。
 *
 * @return array<int, array<string, mixed>>
 */
function collect_pingma_fetch_bet_rows_for_payout(PDO $pdo, string $where, array $params): array
{
    $rows = array();
    if (collect_bets_table_exists($pdo)) {
        $stmt = $pdo->prepare(
            "SELECT b.id, b.submission_id, b.play_type, b.play_label, b.selection_type,
                    b.selection_json, b.groups_json, b.group_count, b.amount_per_group,
                    b.total_amount, b.amount_mode, b.raw_segment,
                    s.period_no, s.created_at,
                    COALESCE(NULLIF(TRIM(p.key_name), ''), CONCAT('渠道#', s.pass_id)) AS key_name
             FROM collect_submission_bets b
             INNER JOIN collect_submissions s ON s.id = b.submission_id
             LEFT JOIN collect_passkeys p ON p.id = s.pass_id
             WHERE {$where}
             ORDER BY b.id DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            return $rows;
        }
    }

    $stmt2 = $pdo->prepare(
        "SELECT s.id AS submission_id, s.period_no, s.created_at, s.parsed_json,
                COALESCE(NULLIF(TRIM(p.key_name), ''), CONCAT('渠道#', s.pass_id)) AS key_name
         FROM collect_submissions s
         LEFT JOIN collect_passkeys p ON p.id = s.pass_id
         WHERE {$where}
         ORDER BY s.id DESC"
    );
    $stmt2->execute($params);
    $subs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    $fakeId = 0;
    foreach ($subs as $sub) {
        $decoded = json_decode((string) ($sub['parsed_json'] ?? ''), true);
        if (!is_array($decoded) || collect_normalize_ball_scope((string) ($decoded['ball_scope'] ?? '')) !== 'pingma') {
            continue;
        }
        $bets = $decoded['bets'] ?? array();
        if (!is_array($bets)) {
            continue;
        }
        foreach ($bets as $bet) {
            if (!is_array($bet)) {
                continue;
            }
            $bet = pingma_recalc_bet_amounts($bet);
            $fakeId--;
            $sel = $bet['selection'] ?? array();
            $rows[] = array(
                'id' => $fakeId,
                'submission_id' => (int) ($sub['submission_id'] ?? 0),
                'play_type' => (string) ($bet['play_type'] ?? ''),
                'play_label' => (string) ($bet['play_label'] ?? ''),
                'selection_type' => (string) ($bet['selection_type'] ?? 'number'),
                'selection_json' => json_encode(is_array($sel) ? $sel : array(), JSON_UNESCAPED_UNICODE),
                'groups_json' => json_encode($bet['groups'] ?? array(), JSON_UNESCAPED_UNICODE),
                'group_count' => (int) ($bet['group_count'] ?? 0),
                'amount_per_group' => (float) ($bet['amount_per_group'] ?? 0),
                'total_amount' => (float) ($bet['total_amount'] ?? 0),
                'amount_mode' => (string) ($bet['amount_mode'] ?? 'per_group'),
                'raw_segment' => (string) ($bet['raw_segment'] ?? ''),
                'period_no' => (string) ($sub['period_no'] ?? ''),
                'created_at' => (string) ($sub['created_at'] ?? ''),
                'key_name' => (string) ($sub['key_name'] ?? ''),
            );
        }
    }
    return $rows;
}

/**
 * 各球号赔付压力权重：汇总每条下注展开组的「每组金额×赔率」，按组内号码/肖展开分摊。
 * 49 个号都有人买、但每人组合不同时，压力低的号仍会被优先推荐。
 *
 * @param array<int, array<string, mixed>> $preparedBets
 * @return array<string, float>
 */
function collect_pingma_compute_ball_exposure_weights(array $preparedBets, int $drawTs): array
{
    $weights = array();
    for ($i = 1; $i <= 49; $i++) {
        $weights[str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = 0.0;
    }
    foreach ($preparedBets as $bet) {
        $groups = pingma_bet_resolve_groups($bet);
        $odds = pingma_payout_odds_for_play_type((string) ($bet['play_type'] ?? ''));
        $per = (float) ($bet['amount_per_group'] ?? 0);
        $unit = $per * $odds;
        if ($unit <= 0 || !$groups) {
            continue;
        }
        $selType = (string) ($bet['selection_type'] ?? 'number');
        foreach ($groups as $g) {
            if (!is_array($g) || !$g) {
                continue;
            }
            if ($selType === 'zodiac') {
                foreach ($g as $sx) {
                    $balls = lhc_numbers_for_shengxiao((string) $sx, $drawTs);
                    $nBalls = max(1, count($balls));
                    $share = $unit / $nBalls;
                    foreach ($balls as $ball) {
                        $n = str_pad((string) ((int) $ball), 2, '0', STR_PAD_LEFT);
                        if (isset($weights[$n])) {
                            $weights[$n] += $share;
                        }
                    }
                }
            } else {
                $nNums = count($g);
                if ($nNums <= 0) {
                    continue;
                }
                $share = $unit / $nNums;
                foreach ($g as $num) {
                    $n = pingma_normalize_number_token((string) $num);
                    if ($n !== null && isset($weights[$n])) {
                        $weights[$n] += $share;
                    }
                }
            }
        }
    }
    return $weights;
}

/** 推荐/排序用：统一为 01–49 两位球号字符串。 */
function collect_pingma_normalize_ball_key($ball): string
{
    $n = pingma_normalize_number_token((string) $ball);
    if ($n !== null) {
        return $n;
    }
    $i = (int) $ball;
    if ($i >= 1 && $i <= 49) {
        return str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    }
    return str_pad((string) $ball, 2, '0', STR_PAD_LEFT);
}

/** @param array<string, float> $weights */
function collect_pingma_exposure_sorted_balls(array $weights): array
{
    $balls = array();
    foreach (array_keys($weights) as $ball) {
        $balls[] = collect_pingma_normalize_ball_key($ball);
    }
    $balls = array_values(array_unique($balls));
    usort($balls, static function ($a, $b) use ($weights) {
        $wa = (float) ($weights[$a] ?? $weights[(int) $a] ?? 0);
        $wb = (float) ($weights[$b] ?? $weights[(int) $b] ?? 0);
        $cmp = ($wa <=> $wb);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) $a, (string) $b);
    });
    return $balls;
}

/** @param string[] $sortedBalls @return array<int, string[]> */
function collect_pingma_build_recommend_try_plans(array $sortedBalls): array
{
    $plans = array();
    $seen = array();
    $n = count($sortedBalls);
    if ($n < 6) {
        return $plans;
    }
    $add = static function (array $plan) use (&$plans, &$seen): void {
        $plan = array_map('collect_pingma_normalize_ball_key', $plan);
        $plan = array_values(array_unique($plan));
        if (count($plan) < 6) {
            return;
        }
        $plan = array_slice($plan, 0, 6);
        sort($plan, SORT_STRING);
        $key = implode(',', $plan);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $plans[] = $plan;
    };
    $add(array_slice($sortedBalls, 0, 6));
    foreach (array(
        array(0, 1, 2, 3, 4, 6),
        array(0, 2, 4, 6, 8, 10),
        array(0, 3, 6, 9, 12, 15),
        array(0, 4, 8, 12, 16, 20),
        array(1, 3, 5, 7, 9, 11),
    ) as $idx) {
        $plan = array();
        foreach ($idx as $i) {
            if ($i < $n) {
                $plan[] = $sortedBalls[$i];
            }
        }
        $add($plan);
    }
    return $plans;
}

function collect_pingma_combination_count(int $n, int $k): int
{
    if ($k < 0 || $k > $n) {
        return 0;
    }
    if ($k > $n - $k) {
        $k = $n - $k;
    }
    $c = 1;
    for ($i = 0; $i < $k; $i++) {
        $c = intdiv($c * ($n - $i), ($i + 1));
    }
    return $c;
}

/**
 * 穷举 k 组合并回调；返回 false 时提前结束。
 *
 * @param callable(array<int, string>): (bool|null) $callback
 */
function collect_pingma_foreach_combination_k(array $items, int $k, callable $callback): int
{
    $n = count($items);
    if ($k <= 0 || $k > $n) {
        return 0;
    }
    $idx = range(0, $k - 1);
    $count = 0;
    while (true) {
        $combo = array();
        foreach ($idx as $i) {
            $combo[] = $items[$i];
        }
        $count++;
        if ($callback($combo) === false) {
            return $count;
        }
        $i = $k - 1;
        while ($i >= 0 && $idx[$i] === $n - $k + $i) {
            $i--;
        }
        if ($i < 0) {
            break;
        }
        $idx[$i]++;
        for ($j = $i + 1; $j < $k; $j++) {
            $idx[$j] = $idx[$j - 1] + 1;
        }
    }
    return $count;
}

/** 比较两次验算结果，true 表示 $a 更优（派彩更低，或同派彩时盈利更高）。 */
function collect_pingma_eval_is_better(array $a, ?array $b): bool
{
    if ($b === null) {
        return true;
    }
    if ($a['payout'] < $b['payout']) {
        return true;
    }
    if ($a['payout'] > $b['payout']) {
        return false;
    }
    return $a['net_profit'] > $b['net_profit'];
}

/**
 * 将已解析 bet 分为号码类 / 肖类（推荐验算加速用）。
 *
 * @param array<int, array<string, mixed>> $preparedBets
 * @return array{number:array<int,array<string,mixed>>,zodiac:array<int,array<string,mixed>>}
 */
function collect_pingma_partition_prepared_bets(array $preparedBets): array
{
    $numberBets = array();
    $zodiacBets = array();
    foreach ($preparedBets as $bet) {
        if ((string) ($bet['selection_type'] ?? 'number') === 'zodiac') {
            $zodiacBets[] = $bet;
        } else {
            $numberBets[] = $bet;
        }
    }
    return array('number' => $numberBets, 'zodiac' => $zodiacBets);
}

/** @param array<int, array<string, mixed>> $numberBets */
function collect_pingma_number_payout_sum(array $numberBets, array $zhengMa, int $drawTs): float
{
    $p = 0.0;
    foreach ($numberBets as $bet) {
        $calc = pingma_calc_bet_payout($bet, $zhengMa, '', $drawTs);
        $p += (float) $calc['payout'];
    }
    return $p;
}

/** @param array<int, array<string, mixed>> $zodiacBets */
function collect_pingma_zodiac_payout_sum(array $zodiacBets, array $zhengMa, string $teMa, int $drawTs): float
{
    $p = 0.0;
    foreach ($zodiacBets as $bet) {
        $calc = pingma_calc_bet_payout($bet, $zhengMa, $teMa, $drawTs);
        $p += (float) $calc['payout'];
    }
    return $p;
}

/** @param array<int, array<string, mixed>> $preparedBets */
function collect_pingma_stake_sum(array $preparedBets): float
{
    $s = 0.0;
    foreach ($preparedBets as $bet) {
        $s += (float) ($bet['total_amount'] ?? 0);
    }
    return round($s, 2);
}

/**
 * 配套特码：落在 6 平码已有肖内，肖类按 7 球计时不新增肖。
 *
 * @param string[] $zhengMa
 */
function collect_pingma_pick_tema_no_new_zodiac(array $zhengMa, int $drawTs): string
{
    $zhengSet = array();
    foreach ($zhengMa as $b) {
        $zhengSet[collect_pingma_normalize_ball_key($b)] = true;
    }
    $zodiacHit = pingma_draw_zodiac_hit_set($zhengMa, '', $drawTs);
    for ($i = 1; $i <= 49; $i++) {
        $ball = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        if (isset($zhengSet[$ball])) {
            continue;
        }
        $sx = lhc_shengxiao_for_number($ball, $drawTs);
        if ($sx !== '' && isset($zodiacHit[$sx])) {
            return $ball;
        }
    }
    return '';
}

/**
 * 最不利特码：在剩余号中使肖类派彩最高（保守估算用）。
 *
 * @param array<int, array<string, mixed>> $zodiacBets
 * @return array{teMa:string,payout:float}
 */
function collect_pingma_pick_tema_max_zodiac_payout(array $zodiacBets, array $zhengMa, int $drawTs): array
{
    $zhengSet = array();
    foreach ($zhengMa as $b) {
        $zhengSet[collect_pingma_normalize_ball_key($b)] = true;
    }
    $worstTeMa = '';
    $worstPay = 0.0;
    for ($i = 1; $i <= 49; $i++) {
        $ball = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        if (isset($zhengSet[$ball])) {
            continue;
        }
        $zp = collect_pingma_zodiac_payout_sum($zodiacBets, $zhengMa, $ball, $drawTs);
        if ($zp > $worstPay || ($zp === $worstPay && ($worstTeMa === '' || strcmp($ball, $worstTeMa) < 0))) {
            $worstPay = $zp;
            $worstTeMa = $ball;
        }
    }
    return array('teMa' => $worstTeMa, 'payout' => round($worstPay, 2));
}

/**
 * 预编译派彩引擎：搜索阶段跳过 pingma_calc_bet_payout 重复解析。
 *
 * @param array<int, array<string, mixed>> $preparedBets
 * @return array{stake:float,drawTs:int,number:array<int,array{groups:array,unit:float}>,zodiac:array<int,array{groups:array,unit:float}>}
 */
function collect_pingma_build_payout_engine(array $preparedBets, int $drawTs): array
{
    $numberItems = array();
    $zodiacItems = array();
    foreach ($preparedBets as $bet) {
        $groups = pingma_bet_resolve_groups($bet);
        $per = (float) ($bet['amount_per_group'] ?? 0);
        $odds = pingma_payout_odds_for_play_type((string) ($bet['play_type'] ?? ''));
        $unit = $per * $odds;
        if ($unit <= 0 || !$groups) {
            continue;
        }
        if ((string) ($bet['selection_type'] ?? 'number') === 'zodiac') {
            $zGroups = array();
            foreach ($groups as $g) {
                if (is_array($g) && $g) {
                    $zGroups[] = array_values($g);
                }
            }
            if ($zGroups) {
                $zodiacItems[] = array('groups' => $zGroups, 'unit' => $unit);
            }
        } else {
            $nGroups = array();
            foreach ($groups as $g) {
                if (!is_array($g) || !$g) {
                    continue;
                }
                $nums = array();
                foreach ($g as $num) {
                    $n = pingma_normalize_number_token((string) $num);
                    if ($n !== null) {
                        $nums[] = $n;
                    }
                }
                if ($nums) {
                    $nGroups[] = $nums;
                }
            }
            if ($nGroups) {
                $numberItems[] = array('groups' => $nGroups, 'unit' => $unit);
            }
        }
    }
    return array(
        'stake' => collect_pingma_stake_sum($preparedBets),
        'drawTs' => $drawTs,
        'number' => $numberItems,
        'zodiac' => $zodiacItems,
    );
}

/**
 * @param array{stake:float,drawTs:int,number:array,zodiac:array} $engine
 */
function collect_pingma_engine_zodiac_payout(array $engine, array $zhengMa, string $teMa = ''): float
{
    $zodiacHit = pingma_draw_zodiac_hit_set($zhengMa, $teMa, (int) $engine['drawTs']);
    $payout = 0.0;
    foreach ($engine['zodiac'] as $item) {
        $unit = (float) $item['unit'];
        foreach ($item['groups'] as $g) {
            $ok = true;
            foreach ($g as $sx) {
                if (empty($zodiacHit[$sx])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $payout += $unit;
            }
        }
    }
    return $payout;
}

function collect_pingma_engine_eval_zheng(array $engine, array $zhengMa): array
{
    $zhengSet = array();
    foreach ($zhengMa as $b) {
        $n = collect_pingma_normalize_ball_key($b);
        if ($n !== '') {
            $zhengSet[$n] = true;
        }
    }
    $payout = 0.0;
    foreach ($engine['number'] as $item) {
        $unit = (float) $item['unit'];
        foreach ($item['groups'] as $g) {
            $ok = true;
            foreach ($g as $num) {
                if (!isset($zhengSet[$num])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $payout += $unit;
            }
        }
    }
    $payout += collect_pingma_engine_zodiac_payout($engine, $zhengMa, '');
    $payout = round($payout, 2);
    $stake = (float) $engine['stake'];
    return array(
        'stake' => $stake,
        'payout' => $payout,
        'net_profit' => round($stake - $payout, 2),
        'hit_group_count' => 0,
    );
}

/**
 * 推荐搜索用快速验算：号码类 + 肖类（6 平码；配套特码不新增肖时与 7 球一致）。
 *
 * @param array{number:array,zodiac:array}|null $partition
 * @param array<string, mixed>|null $engine
 */
function collect_pingma_fast_eval_zheng(
    ?array $partition,
    float $stake,
    array $zhengMa,
    int $drawTs,
    ?array $engine = null
): array {
    if ($engine !== null) {
        return collect_pingma_engine_eval_zheng($engine, $zhengMa);
    }
    $partition = $partition ?? array('number' => array(), 'zodiac' => array());
    $numberPayout = collect_pingma_number_payout_sum($partition['number'], $zhengMa, $drawTs);
    $zodiacPayout = collect_pingma_zodiac_payout_sum($partition['zodiac'], $zhengMa, '', $drawTs);
    $payout = round($numberPayout + $zodiacPayout, 2);
    return array(
        'stake' => $stake,
        'payout' => $payout,
        'net_profit' => round($stake - $payout, 2),
        'hit_group_count' => 0,
    );
}

/**
 * @param array<int, array{zheng:string[],eval:array<string,mixed>}> $top
 */
function collect_pingma_topk_push(array &$top, array $zheng, array $eval, int $k = 5): void
{
    $key = implode(',', $zheng);
    foreach ($top as $item) {
        if (implode(',', $item['zheng']) === $key) {
            return;
        }
    }
    $top[] = array('zheng' => $zheng, 'eval' => $eval);
    usort($top, static function ($a, $b) {
        if (collect_pingma_eval_is_better($a['eval'], $b['eval'])) {
            return -1;
        }
        if (collect_pingma_eval_is_better($b['eval'], $a['eval'])) {
            return 1;
        }
        return 0;
    });
    if (count($top) > $k) {
        array_pop($top);
    }
}

/** 解析后台「推荐目标盈利率」百分比（1–99，默认 70）。 */
function collect_pingma_parse_target_profit_pct(string $raw): float
{
    $raw = trim($raw);
    if ($raw === '' || !is_numeric($raw)) {
        return 70.0;
    }
    $pct = (float) $raw;
    return max(1.0, min(99.0, round($pct, 2)));
}

/**
 * 推荐开奖目标盈利率（庄家净利润 / 下注合计）。
 * 后台设置键：pingma_target_profit_pct（百分数，如 70、80、90）。
 */
function collect_pingma_target_profit_rate(): float
{
    static $rate = null;
    if ($rate !== null) {
        return $rate;
    }
    $pct = 70.0;
    try {
        $pdo = get_pdo();
        $pct = collect_pingma_parse_target_profit_pct(
            (string) (get_setting($pdo, 'pingma_target_profit_pct', '70') ?? '70')
        );
    } catch (Throwable $e) {
    }
    $rate = $pct / 100.0;
    return $rate;
}

/** 解析后台「推荐方案数量」（1–5，默认 3）。 */
function collect_pingma_parse_recommend_count(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '' || !is_numeric($raw)) {
        return 3;
    }
    return max(1, min(5, (int) round((float) $raw)));
}

/**
 * 每次平码推荐返回几套方案。
 * 后台设置键：pingma_recommend_count（1–5，默认 3）。
 */
function collect_pingma_recommend_count(): int
{
    static $count = null;
    if ($count !== null) {
        return $count;
    }
    $count = 3;
    try {
        $pdo = get_pdo();
        $count = collect_pingma_parse_recommend_count(
            (string) (get_setting($pdo, 'pingma_recommend_count', '3') ?? '3')
        );
    } catch (Throwable $e) {
    }
    return $count;
}

/**
 * @return array{profit_rate:float,target_profit_rate:float,meets_target:bool,target_net_profit:float,max_payout_for_target:float}
 */
function collect_pingma_profit_rate_meta(float $stake, float $netProfit): array
{
    $target = collect_pingma_target_profit_rate();
    $rate = $stake > 0 ? round(100 * $netProfit / $stake, 2) : 0.0;
    return array(
        'profit_rate' => $rate,
        'target_profit_rate' => round(100 * $target, 0),
        'meets_target' => $stake > 0 && ($netProfit / $stake) >= ($target - 0.0001),
        'target_net_profit' => round($stake * $target, 2),
        'max_payout_for_target' => round($stake * (1 - $target), 2),
    );
}

/**
 * 贪心构造 6 平码：逐颗加入当前边际派彩最低的球号。
 *
 * @param array{number:array,zodiac:array} $partition
 * @param string[] $candidateOrder 按赔付压力从低到高
 * @param string[] $seed 已有平码（可选）
 * @return string[]
 */
function collect_pingma_greedy_build_zheng_ma(
    array $engine,
    array $candidateOrder,
    int $need = 6,
    int $branchWidth = 18,
    array $seed = array()
): array {
    $chosen = array();
    foreach ($seed as $ball) {
        $ball = collect_pingma_normalize_ball_key($ball);
        if ($ball !== '' && !in_array($ball, $chosen, true)) {
            $chosen[] = $ball;
        }
    }
    $chosenSet = array_flip($chosen);
    $remaining = array_values(array_filter($candidateOrder, static function ($b) use ($chosenSet) {
        return !isset($chosenSet[$b]);
    }));
    while (count($chosen) < $need && $remaining) {
        $bestBall = '';
        $bestPayout = null;
        foreach (array_slice($remaining, 0, min($branchWidth, count($remaining))) as $ball) {
            $trial = array_merge($chosen, array($ball));
            $payout = (float) collect_pingma_engine_eval_zheng($engine, $trial)['payout'];
            if ($bestPayout === null || $payout < $bestPayout || ($payout === $bestPayout && strcmp($ball, $bestBall) < 0)) {
                $bestPayout = $payout;
                $bestBall = $ball;
            }
        }
        if ($bestBall === '') {
            break;
        }
        $chosen[] = $bestBall;
        $chosenSet[$bestBall] = true;
        $remaining = array_values(array_filter($remaining, static function ($b) use ($chosenSet) {
            return !isset($chosenSet[$b]);
        }));
    }
    if (count($chosen) < $need) {
        return array();
    }
    $chosen = array_slice($chosen, 0, $need);
    sort($chosen, SORT_STRING);
    return $chosen;
}

/**
 * 贪心 + 多起点 + 单球爬山：快速求低派彩 6 平码（非穷举）。
 *
 * @return array{zheng:string[],eval:array<string,mixed>|null,searched:int,pool_used:int}
 */
function collect_pingma_algorithm_search_best_zheng_ma(
    array $preparedBets,
    array $sortedBalls,
    int $drawTs
): array {
    if (count($sortedBalls) < 6) {
        return array('zheng' => array(), 'eval' => null, 'searched' => 0, 'pool_used' => 0);
    }
    $engine = collect_pingma_build_payout_engine($preparedBets, $drawTs);
    $target = collect_pingma_target_profit_rate();
    $bestZheng = array();
    $bestEval = null;
    $searched = 0;

    $consider = static function (array $zheng) use (
        $engine,
        &$bestZheng,
        &$bestEval,
        &$searched
    ): void {
        $zheng = array_map('collect_pingma_normalize_ball_key', $zheng);
        $zheng = array_values(array_unique($zheng));
        if (count($zheng) < 6) {
            return;
        }
        $zheng = array_slice($zheng, 0, 6);
        sort($zheng, SORT_STRING);
        $eval = collect_pingma_engine_eval_zheng($engine, $zheng);
        $searched++;
        if (collect_pingma_eval_is_better($eval, $bestEval)) {
            $bestEval = $eval;
            $bestZheng = $zheng;
        }
    };

    $starts = array(
        collect_pingma_greedy_build_zheng_ma($engine, $sortedBalls),
        collect_pingma_greedy_build_zheng_ma($engine, array_slice($sortedBalls, 2)),
        collect_pingma_greedy_build_zheng_ma($engine, $sortedBalls, 6, 18, array_slice($sortedBalls, 0, 2)),
    );
    $tryPlans = collect_pingma_build_recommend_try_plans(array_slice($sortedBalls, 0, 24));
    if ($tryPlans) {
        $starts[] = $tryPlans[0];
    }
    $seen = array();
    foreach ($starts as $zheng) {
        if (count($zheng) < 6) {
            continue;
        }
        $key = implode(',', $zheng);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $consider($zheng);
        $stake = (float) ($bestEval['stake'] ?? 0);
        $net = (float) ($bestEval['net_profit'] ?? 0);
        if ($stake > 0 && ($net / $stake) >= ($target - 0.0001)) {
            break;
        }
    }

    if ($bestEval === null || !$bestZheng) {
        return array('zheng' => array(), 'eval' => null, 'searched' => $searched, 'pool_used' => 0);
    }

    $bestZheng = collect_pingma_refine_zheng_ma_swap(
        $preparedBets,
        $bestZheng,
        $sortedBalls,
        $drawTs,
        $bestEval,
        null,
        null,
        $engine,
        18,
        2
    );
    $bestEval = collect_pingma_engine_eval_zheng($engine, $bestZheng);
    $searched += 6;

    return array(
        'zheng' => $bestZheng,
        'eval' => $bestEval,
        'searched' => $searched,
        'pool_used' => 0,
        'engine' => $engine,
    );
}

/**
 * 搜索多套低派彩 6 平码：先走单套快速算法，再对最优方案做单球替换找备选（秒级）。
 *
 * @return array{plans:array<int,array{zheng:string[],eval:array<string,mixed>}>,searched:int,pool_used:int,engine:array}
 */
function collect_pingma_search_top_zheng_ma(
    array $preparedBets,
    array $sortedBalls,
    int $drawTs,
    int $limit = 3
): array {
    $limit = max(1, min(5, $limit));
    if (count($sortedBalls) < 6) {
        return array('plans' => array(), 'searched' => 0, 'pool_used' => 0, 'engine' => array());
    }

    $best = collect_pingma_algorithm_search_best_zheng_ma($preparedBets, $sortedBalls, $drawTs);
    $searched = (int) ($best['searched'] ?? 0);
    if ($best['eval'] === null || !$best['zheng']) {
        return array(
            'plans' => array(),
            'searched' => $searched,
            'pool_used' => 0,
            'engine' => $best['engine'] ?? array(),
        );
    }

    $engine = $best['engine'] ?? collect_pingma_build_payout_engine($preparedBets, $drawTs);
    $candidateMap = array();
    $register = static function (array $zheng, array $eval) use (&$candidateMap, &$searched): void {
        $zheng = array_map('collect_pingma_normalize_ball_key', $zheng);
        $zheng = array_values(array_unique($zheng));
        if (count($zheng) < 6) {
            return;
        }
        $zheng = array_slice($zheng, 0, 6);
        sort($zheng, SORT_STRING);
        $key = implode(',', $zheng);
        $searched++;
        if (!isset($candidateMap[$key]) || collect_pingma_eval_is_better($eval, $candidateMap[$key]['eval'])) {
            $candidateMap[$key] = array('zheng' => $zheng, 'eval' => $eval);
        }
    };

    $register($best['zheng'], $best['eval']);

    if ($limit > 1) {
        $bestKey = implode(',', $best['zheng']);
        $zhengSet = array_flip($best['zheng']);
        $swapCandidates = array_values(array_filter($sortedBalls, static function ($b) use ($zhengSet) {
            return !isset($zhengSet[$b]);
        }));
        if (count($swapCandidates) > 8) {
            $swapCandidates = array_slice($swapCandidates, 0, 8);
        }
        foreach ($best['zheng'] as $pos => $outBall) {
            foreach ($swapCandidates as $inBall) {
                $trial = $best['zheng'];
                $trial[$pos] = collect_pingma_normalize_ball_key($inBall);
                sort($trial, SORT_STRING);
                if (implode(',', $trial) === $bestKey) {
                    continue;
                }
                $register($trial, collect_pingma_engine_eval_zheng($engine, $trial));
            }
        }
        $altGreedy = collect_pingma_greedy_build_zheng_ma($engine, array_slice($sortedBalls, 1), 6, 12);
        if (count($altGreedy) === 6) {
            $register($altGreedy, collect_pingma_engine_eval_zheng($engine, $altGreedy));
        }
    }

    $ranked = array_values($candidateMap);
    usort($ranked, static function ($a, $b) {
        $ea = $a['eval'];
        $eb = $b['eval'];
        if ($ea['payout'] < $eb['payout']) {
            return -1;
        }
        if ($ea['payout'] > $eb['payout']) {
            return 1;
        }
        if ($ea['net_profit'] > $eb['net_profit']) {
            return -1;
        }
        if ($ea['net_profit'] < $eb['net_profit']) {
            return 1;
        }
        return strcmp(implode(',', $a['zheng']), implode(',', $b['zheng']));
    });

    return array(
        'plans' => array_slice($ranked, 0, $limit),
        'searched' => $searched,
        'pool_used' => 0,
        'engine' => $engine,
    );
}

/** @param array{number:array} $engine */
function collect_pingma_engine_number_payout(array $engine, array $zhengMa): float
{
    $zhengSet = array();
    foreach ($zhengMa as $b) {
        $n = collect_pingma_normalize_ball_key($b);
        if ($n !== '') {
            $zhengSet[$n] = true;
        }
    }
    $payout = 0.0;
    foreach ($engine['number'] as $item) {
        $unit = (float) $item['unit'];
        foreach ($item['groups'] as $g) {
            $ok = true;
            foreach ($g as $num) {
                if (!isset($zhengSet[$num])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $payout += $unit;
            }
        }
    }
    return round($payout, 2);
}

/**
 * 保守特码：仅在高热号候选中找肖类派彩最高（加速）。
 *
 * @param string[] $highExposureBalls
 * @return array{teMa:string,payout:float}
 */
function collect_pingma_pick_tema_worst_zodiac_fast(
    array $engine,
    array $zhengMa,
    array $highExposureBalls
): array {
    $zhengSet = array();
    foreach ($zhengMa as $b) {
        $zhengSet[collect_pingma_normalize_ball_key($b)] = true;
    }
    $worstTeMa = '';
    $worstPay = 0.0;
    foreach ($highExposureBalls as $ball) {
        $ball = collect_pingma_normalize_ball_key($ball);
        if ($ball === '' || isset($zhengSet[$ball])) {
            continue;
        }
        $zp = collect_pingma_engine_zodiac_payout($engine, $zhengMa, $ball);
        if ($zp > $worstPay || ($zp === $worstPay && ($worstTeMa === '' || strcmp($ball, $worstTeMa) < 0))) {
            $worstPay = $zp;
            $worstTeMa = $ball;
        }
    }
    return array('teMa' => $worstTeMa, 'payout' => round($worstPay, 2));
}

/**
 * 按目标盈利率搜索（贪心+爬山，秒级完成）。
 *
 * @return array{zheng:string[],eval:array<string,mixed>|null,searched:int,pool_used:int}
 */
function collect_pingma_search_best_zheng_ma_for_target(
    array $preparedBets,
    array $sortedBalls,
    int $drawTs
): array {
    return collect_pingma_algorithm_search_best_zheng_ma($preparedBets, $sortedBalls, $drawTs);
}

/**
 * @deprecated 兼容旧调用；内部走贪心+爬山算法。
 * @return array{zheng:string[],eval:array<string,mixed>|null,searched:int}
 */
function collect_pingma_search_best_zheng_ma(
    array $preparedBets,
    array $sortedBalls,
    int $drawTs,
    int $poolSize = 0,
    int $maxCombos = 0
): array {
    $r = collect_pingma_algorithm_search_best_zheng_ma($preparedBets, $sortedBalls, $drawTs);
    return array(
        'zheng' => $r['zheng'],
        'eval' => $r['eval'],
        'searched' => $r['searched'],
    );
}

/**
 * 对当前 6 平码做逐球替换爬山，进一步压低派彩。
 *
 * @param array<int, array<string, mixed>> $preparedBets
 * @param string[] $zhengMa
 * @param string[] $sortedBalls
 * @return string[]
 */
function collect_pingma_refine_zheng_ma_swap(
    array $preparedBets,
    array $zhengMa,
    array $sortedBalls,
    int $drawTs,
    ?array $bestEval = null,
    ?array $partition = null,
    ?float $stake = null,
    ?array $engine = null,
    int $maxCandidates = 18,
    int $maxRounds = 2
): array {
    $bestZheng = array_map('collect_pingma_normalize_ball_key', $zhengMa);
    sort($bestZheng, SORT_STRING);
    if ($engine === null) {
        $engine = collect_pingma_build_payout_engine($preparedBets, $drawTs);
    }
    $bestEval = $bestEval ?? collect_pingma_engine_eval_zheng($engine, $bestZheng);
    $rounds = 0;
    $improved = true;
    while ($improved && $rounds < $maxRounds) {
        $improved = false;
        $rounds++;
        $zhengSet = array_flip($bestZheng);
        $candidates = array_values(array_filter($sortedBalls, static function ($b) use ($zhengSet) {
            return !isset($zhengSet[$b]);
        }));
        if ($maxCandidates > 0 && count($candidates) > $maxCandidates) {
            $candidates = array_slice($candidates, 0, $maxCandidates);
        }
        foreach ($bestZheng as $pos => $outBall) {
            foreach ($candidates as $inBall) {
                $trial = $bestZheng;
                $trial[$pos] = collect_pingma_normalize_ball_key($inBall);
                sort($trial, SORT_STRING);
                $trial = array_values(array_unique($trial));
                if (count($trial) < 6) {
                    continue;
                }
                $eval = collect_pingma_engine_eval_zheng($engine, $trial);
                if (collect_pingma_eval_is_better($eval, $bestEval)) {
                    $bestZheng = $trial;
                    $bestEval = $eval;
                    $improved = true;
                    break 2;
                }
            }
        }
    }
    return $bestZheng;
}

/** @param array<string, float> $weights */
function collect_pingma_exposure_hot_display(array $weights, int $top = 12): array
{
    $pairs = array();
    foreach ($weights as $ball => $w) {
        if ($w > 0) {
            $pairs[] = array(
                'ball' => collect_pingma_normalize_ball_key($ball),
                'weight' => round($w, 2),
            );
        }
    }
    usort($pairs, static function ($a, $b) {
        return ($b['weight'] <=> $a['weight'])
            ?: strcmp((string) $a['ball'], (string) $b['ball']);
    });
    $pairs = array_slice($pairs, 0, max(1, $top));
    $nums = array();
    $parts = array();
    foreach ($pairs as $p) {
        $nums[] = $p['ball'];
        $parts[] = $p['ball'] . '(' . $p['weight'] . ')';
    }
    return array(
        'numbers' => $nums,
        'display' => implode(',', $parts),
    );
}

/** 从平码明细提取客户热号 / 热肖（推荐开奖时尽量避开）。 */
function collect_pingma_extract_risk_profile(array $dbRows): array
{
    $userNums = array();
    $userZodiacs = array();
    foreach ($dbRows as $row) {
        $sel = json_decode((string) ($row['selection_json'] ?? '[]'), true);
        if (!is_array($sel)) {
            continue;
        }
        $selType = (string) ($row['selection_type'] ?? 'number');
        foreach ($sel as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            if ($selType === 'zodiac') {
                $userZodiacs[$item] = true;
            } else {
                $n = pingma_normalize_number_token($item);
                if ($n !== null) {
                    $userNums[$n] = true;
                }
            }
        }
    }
    $nums = array_keys($userNums);
    sort($nums);
    $zods = array_keys($userZodiacs);
    return array(
        'numbers' => $nums,
        'zodiacs' => $zods,
        'numbers_display' => implode(',', $nums),
        'zodiacs_display' => implode('', $zods),
    );
}

/** 从 collect_submission_bets 行构建 bet，并按当前规则重算组数/金额。 */
function collect_pingma_bet_array_from_db_row(array $row): array
{
    $sel = json_decode((string) ($row['selection_json'] ?? '[]'), true);
    $groups = json_decode((string) ($row['groups_json'] ?? '[]'), true);
    $mode = (string) ($row['amount_mode'] ?? 'per_group');
    $totalAmount = (float) ($row['total_amount'] ?? 0);
    $bet = array(
        'play_type' => (string) ($row['play_type'] ?? ''),
        'play_label' => (string) ($row['play_label'] ?? ''),
        'selection_type' => (string) ($row['selection_type'] ?? 'number'),
        'selection' => is_array($sel) ? $sel : array(),
        'groups' => is_array($groups) ? $groups : array(),
        'group_count' => (int) ($row['group_count'] ?? 0),
        'amount_per_group' => (float) ($row['amount_per_group'] ?? 0),
        'total_amount' => $totalAmount,
        'amount_mode' => $mode,
        'raw_segment' => (string) ($row['raw_segment'] ?? ''),
    );
    if ($mode === 'flat_total') {
        $bet['flat_total'] = $totalAmount;
    }
    return pingma_normalize_bet_for_payout($bet);
}

/** @return array<int, array<string, mixed>> */
function collect_pingma_prepare_bets_from_rows(array $dbRows): array
{
    $prepared = array();
    foreach ($dbRows as $row) {
        $prepared[] = collect_pingma_bet_array_from_db_row($row);
    }
    return $prepared;
}

/**
 * 对指定开奖计算已解析 bet 列表的派彩合计。
 *
 * @param array<int, array<string, mixed>> $dbRows 与 $preparedBets 同序，用于中奖明细展示
 */
function collect_pingma_evaluate_draw_payout_bets(
    array $preparedBets,
    array $zhengMa,
    string $teMa,
    int $drawTs,
    bool $withHitRows = false,
    array $dbRows = array()
): array {
    $stake = 0.0;
    $payout = 0.0;
    $hitGroups = 0;
    $hitRows = array();
    $hitBetCount = 0;
    $hitRowLimit = 50;
    foreach ($preparedBets as $idx => $bet) {
        $calc = pingma_calc_bet_payout($bet, $zhengMa, $teMa, $drawTs);
        $stake += (float) $calc['stake'];
        $payout += (float) $calc['payout'];
        $hitGroups += (int) $calc['hit_count'];
        if ((int) $calc['hit_count'] > 0) {
            $hitBetCount++;
        }
        if ($withHitRows && (int) $calc['hit_count'] > 0 && count($hitRows) < $hitRowLimit) {
            $src = $dbRows[$idx] ?? array();
            $rawSeg = (string) ($src['raw_segment'] ?? '');
            $pt = (string) ($bet['play_type'] ?? '');
            $nSel = count($bet['selection'] ?? array());
            $isFushi = pingma_infer_is_fushi(
                $pt,
                (string) ($bet['play_label'] ?? ''),
                (string) ($bet['selection_type'] ?? 'number'),
                $nSel,
                $rawSeg
            );
            $betMini = array(
                'play_label' => (string) ($bet['play_label'] ?? ''),
                'selection_type' => (string) ($bet['selection_type'] ?? 'number'),
                'selection' => $bet['selection'] ?? array(),
                'group_count' => (int) ($bet['group_count'] ?? 0),
                'amount_per_group' => (float) ($bet['amount_per_group'] ?? 0),
                'total_amount' => (float) ($bet['total_amount'] ?? 0),
                'amount_mode' => (string) ($bet['amount_mode'] ?? 'per_group'),
                'is_fushi' => $isFushi,
            );
            $hitRows[] = array(
                'id' => (int) ($src['id'] ?? 0),
                'play_label' => (string) ($bet['play_label'] ?? ''),
                'display_text' => pingma_format_bet_line(pingma_recalc_bet_amounts($betMini)),
                'hit_count' => (int) $calc['hit_count'],
                'hit_groups_display' => (string) ($calc['hit_groups_display'] ?? ''),
                'hit_groups_numbers_display' => (string) ($calc['hit_groups_numbers_display'] ?? ''),
                'amount_per_group' => (float) $calc['amount_per_group'],
                'odds' => (float) $calc['odds'],
                'payout' => (float) $calc['payout'],
                'period_no' => (string) ($src['period_no'] ?? ''),
                'key_name' => (string) ($src['key_name'] ?? ''),
            );
        }
    }
    $stake = round($stake, 2);
    $payout = round($payout, 2);
    $out = array(
        'stake' => $stake,
        'payout' => $payout,
        'net_profit' => round($stake - $payout, 2),
        'hit_group_count' => $hitGroups,
    );
    if ($withHitRows) {
        usort($hitRows, static function ($a, $b) {
            return ($b['payout'] <=> $a['payout']) ?: ((int) $b['id'] <=> (int) $a['id']);
        });
        $out['hit_rows'] = $hitRows;
        $out['hit_rows_total'] = $hitBetCount;
        $out['hit_rows_truncated'] = $hitBetCount > count($hitRows);
    }
    return $out;
}

function collect_pingma_evaluate_draw_payout(array $dbRows, array $zhengMa, string $teMa, int $drawTs): array
{
    return collect_pingma_evaluate_draw_payout_bets(
        collect_pingma_prepare_bets_from_rows($dbRows),
        $zhengMa,
        $teMa,
        $drawTs
    );
}

/** @param array<int, array{ball: string, zodiac: string, score: int}> $candidates */
function collect_pingma_pick_tema_candidates(array $candidates, array $zhengMa, int $max = 3): array
{
    $zhengSet = array_flip($zhengMa);
    $out = array();
    foreach ($candidates as $c) {
        if (isset($zhengSet[$c['ball']]) || $c['score'] > 1) {
            continue;
        }
        $out[] = $c['ball'];
        if (count($out) >= $max) {
            break;
        }
    }
    if ($out) {
        return $out;
    }
    foreach ($candidates as $c) {
        if (isset($zhengSet[$c['ball']])) {
            continue;
        }
        $out[] = $c['ball'];
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

function collect_pingma_build_recommend_draw_result(
    array $risk,
    array $zhengMa,
    array $eval,
    int $drawTs,
    string $teMa = '',
    ?array $summaryWorst = null,
    int $poolUsed = 0
): array {
    $zhengMa = array_map('collect_pingma_normalize_ball_key', $zhengMa);
    sort($zhengMa, SORT_STRING);
    $teMa = collect_pingma_normalize_ball_key($teMa);
    $zodiacHitList = array_keys(pingma_draw_zodiac_hit_set($zhengMa, $teMa, $drawTs));
    sort($zodiacHitList);
    $ballsItems = collect_pingma_build_ball_items($zhengMa, $drawTs);
    $ballZodiacParts = array();
    foreach ($ballsItems as $item) {
        $meta = array();
        if ($item['wave'] !== '') {
            $meta[] = $item['wave'];
        }
        if ($item['zodiac'] !== '') {
            $meta[] = $item['zodiac'];
        }
        $ballZodiacParts[] = $item['number'] . ($meta ? '(' . implode('/', $meta) . ')' : '');
    }
    $teMaDisplay = '';
    if ($teMa !== '') {
        $teMaDisplay = collect_pingma_format_ball_display($teMa, $drawTs);
    }
    $stake = (float) ($eval['stake'] ?? 0);
    $net = (float) ($eval['net_profit'] ?? ($stake - (float) ($eval['payout'] ?? 0)));
    $profitMeta = collect_pingma_profit_rate_meta($stake, $net);
    $eval = array_merge($eval, $profitMeta);
    return array(
        'available' => true,
        'zhengMa' => $zhengMa,
        'teMa' => $teMa,
        'teMaDisplay' => $teMaDisplay,
        'zodiacHit' => $zodiacHitList,
        'ballsItems' => $ballsItems,
        'ballsDisplay' => implode(', ', $ballZodiacParts),
        'summary' => $eval,
        'summaryWorst' => $summaryWorst,
        'profitRate' => $profitMeta['profit_rate'],
        'targetProfitRate' => $profitMeta['target_profit_rate'],
        'meetsTarget' => $profitMeta['meets_target'],
        'targetNetProfit' => $profitMeta['target_net_profit'],
        'searchPoolUsed' => $poolUsed,
        'avoidNumbers' => $risk['numbers'],
        'avoidZodiacs' => $risk['zodiacs'],
        'avoidNumbersDisplay' => $risk['numbers_display'],
        'avoidZodiacsDisplay' => $risk['zodiacs_display'],
        'note' => (string) ($risk['recommend_note'] ?? '仅推荐 6 个平码（不含特码）；按各笔下注组合的赔付压力选号，使派彩尽量低（仅供参考）。'),
        'exposureHotNumbers' => $risk['exposure_hot_numbers'] ?? array(),
        'exposureHotNumbersDisplay' => (string) ($risk['exposure_hot_display'] ?? ''),
        'boughtNumberCount' => (int) ($risk['bought_number_count'] ?? 0),
    );
}

/**
 * 将单套 6 平码组装为推荐开奖结果。
 *
 * @param array<string, mixed> $risk
 * @param array{number:array,zodiac:array} $engine
 */
/** 推荐开奖明细：超过此笔数时仅用快速引擎合计，不逐笔算中奖明细。 */
function collect_pingma_recommend_hit_row_bet_limit(): int
{
    return 120;
}

/**
 * 推荐区派彩验算：优先用预编译引擎合计；明细仅在笔数可控时逐笔展开。
 *
 * @param array{stake:float,number:array,zodiac:array} $engine
 */
function collect_pingma_evaluate_recommend_draw_bets(
    array $preparedBets,
    array $zhengMa,
    string $teMa,
    int $drawTs,
    array $engine,
    bool $withHitRows,
    array $dbRows
): array {
    $evalDetail = collect_pingma_engine_eval_zheng($engine, $zhengMa);
    if ($teMa !== '') {
        $delta = collect_pingma_engine_zodiac_payout($engine, $zhengMa, $teMa)
            - collect_pingma_engine_zodiac_payout($engine, $zhengMa, '');
        $evalDetail['payout'] = round((float) $evalDetail['payout'] + $delta, 2);
        $evalDetail['net_profit'] = round((float) $evalDetail['stake'] - (float) $evalDetail['payout'], 2);
    }
    if (!$withHitRows || count($preparedBets) > collect_pingma_recommend_hit_row_bet_limit()) {
        if ($withHitRows && count($preparedBets) > collect_pingma_recommend_hit_row_bet_limit()) {
            $evalDetail['hit_rows'] = array();
            $evalDetail['hit_rows_total'] = 0;
            $evalDetail['hit_rows_truncated'] = true;
            $evalDetail['hit_rows_skipped'] = true;
            $evalDetail['hit_rows_skip_note'] = '下注明细超过 '
                . collect_pingma_recommend_hit_row_bet_limit()
                . ' 笔，为加速仅显示派彩合计（不展开中奖明细）。';
        }
        return $evalDetail;
    }
    return collect_pingma_evaluate_draw_payout_bets(
        $preparedBets,
        $zhengMa,
        $teMa,
        $drawTs,
        true,
        $dbRows
    );
}

/**
 * 将单套 6 平码组装为推荐开奖结果。
 *
 * @param array<string, mixed> $risk
 * @param array{number:array,zodiac:array} $engine
 */
function collect_pingma_build_one_recommend_draw(
    array $risk,
    array $preparedBets,
    array $dbRows,
    array $bestZheng,
    array $engine,
    array $sortedBalls,
    int $drawTs,
    int $poolUsed,
    int $rank,
    int $recommendTotal,
    bool $withHitRows,
    ?array $engineEval = null
): array {
    $stake = (float) ($engine['stake'] ?? collect_pingma_stake_sum($preparedBets));
    $bestTeMa = collect_pingma_pick_tema_no_new_zodiac($bestZheng, $drawTs);
    if ($bestTeMa === '' && $engine['zodiac']) {
        foreach (array_slice($sortedBalls, 0, 20) as $ball) {
            if (!in_array($ball, $bestZheng, true)) {
                $bestTeMa = $ball;
                break;
            }
        }
    }

    $summaryWorst = null;
    if ($withHitRows) {
        $hotBalls = array_slice(array_reverse($sortedBalls), 0, 18);
        $worstPick = collect_pingma_pick_tema_worst_zodiac_fast($engine, $bestZheng, $hotBalls);
        if ($worstPick['teMa'] === '') {
            $worstPick = collect_pingma_pick_tema_max_zodiac_payout(
                collect_pingma_partition_prepared_bets($preparedBets)['zodiac'],
                $bestZheng,
                $drawTs
            );
        }
        $numberPayout = collect_pingma_engine_number_payout($engine, $bestZheng);
        $summaryWorst = array(
            'stake' => $stake,
            'payout' => round($numberPayout + (float) ($worstPick['payout'] ?? 0), 2),
            'net_profit' => round($stake - $numberPayout - (float) ($worstPick['payout'] ?? 0), 2),
            'teMa' => (string) ($worstPick['teMa'] ?? ''),
            'note' => '保守估算：特码取最不利号码（肖类派彩最高），实际开奖可能介于推荐与保守之间。',
        );
        $evalDetail = collect_pingma_evaluate_recommend_draw_bets(
            $preparedBets,
            $bestZheng,
            $bestTeMa,
            $drawTs,
            $engine,
            true,
            $dbRows
        );
    } else {
        $evalDetail = $engineEval ?? collect_pingma_engine_eval_zheng($engine, $bestZheng);
        $evalDetail = array_merge($evalDetail, collect_pingma_profit_rate_meta(
            (float) ($evalDetail['stake'] ?? $stake),
            (float) ($evalDetail['net_profit'] ?? 0)
        ));
    }

    $planRisk = $risk;
    if ($rank > 1) {
        $planRisk['recommend_note'] = '备选方案 ' . $rank . '/' . $recommendTotal . '（派彩略高于方案 1，可作对照）。';
    }
    $draw = collect_pingma_build_recommend_draw_result(
        $planRisk,
        $bestZheng,
        $evalDetail,
        $drawTs,
        $bestTeMa,
        $summaryWorst,
        $poolUsed
    );
    $draw['rank'] = $rank;
    $draw['recommendTotal'] = $recommendTotal;
    return $draw;
}

/**
 * 基于当前筛选范围内的平码下注，推荐多套 6 平码使庄家派彩尽量低。
 *
 * @return array{draws:array<int,array<string,mixed>>,primary:?array<string,mixed>}
 */
function collect_pingma_recommend_profitable_draws(PDO $pdo, array $dbRows, int $drawTs): array
{
    if (!$dbRows) {
        return array('draws' => array(), 'primary' => null);
    }
    $preparedBets = collect_pingma_prepare_bets_from_rows($dbRows);
    if (!$preparedBets) {
        return array('draws' => array(), 'primary' => null);
    }

    $risk = collect_pingma_extract_risk_profile($dbRows);
    $exposure = collect_pingma_compute_ball_exposure_weights($preparedBets, $drawTs);
    $hotExposure = collect_pingma_exposure_hot_display($exposure, 12);
    $sortedBalls = collect_pingma_exposure_sorted_balls($exposure);
    $boughtCount = count($risk['numbers']);
    $risk['exposure_hot_numbers'] = $hotExposure['numbers'];
    $risk['exposure_hot_display'] = $hotExposure['display'];
    $risk['bought_number_count'] = $boughtCount;
    $targetPct = (int) round(100 * collect_pingma_target_profit_rate(), 0);
    $recommendCount = collect_pingma_recommend_count();
    if ($boughtCount >= 49) {
        $risk['recommend_note'] = '筛选范围内 49 个号均有人买过，已用贪心构造+爬山替换算法选 6 平码（目标盈利率 ' . $targetPct . '%，共 ' . $recommendCount . ' 套方案）；肖类按 7 球计，配套特码不新增肖。';
    } else {
        $risk['recommend_note'] = '推荐 ' . $recommendCount . ' 套 6 平码（目标盈利率 ' . $targetPct . '%）；贪心构造+爬山替换算法，肖类按 7 球计（配套特码不新增肖）。';
    }

    $search = collect_pingma_search_top_zheng_ma($preparedBets, $sortedBalls, $drawTs, $recommendCount);
    $plans = $search['plans'] ?? array();
    $poolUsed = (int) ($search['pool_used'] ?? 0);
    if (!$plans) {
        $fail = array(
            'available' => false,
            'note' => '无法生成推荐方案，请检查平码明细是否有效。',
            'avoidNumbers' => $risk['numbers'],
            'avoidZodiacs' => $risk['zodiacs'],
            'avoidNumbersDisplay' => $risk['numbers_display'],
            'avoidZodiacsDisplay' => $risk['zodiacs_display'],
            'exposureHotNumbersDisplay' => $hotExposure['display'],
            'boughtNumberCount' => $boughtCount,
        );
        return array('draws' => array($fail), 'primary' => $fail);
    }

    $engine = $search['engine'] ?? collect_pingma_build_payout_engine($preparedBets, $drawTs);
    $stake = (float) ($engine['stake'] ?? collect_pingma_stake_sum($preparedBets));
    $draws = array();
    $rank = 0;
    foreach ($plans as $plan) {
        $bestZheng = $plan['zheng'] ?? array();
        if (!$bestZheng) {
            continue;
        }
        $rank++;
        $draws[] = collect_pingma_build_one_recommend_draw(
            $risk,
            $preparedBets,
            $dbRows,
            $plan['zheng'],
            $engine,
            $sortedBalls,
            $drawTs,
            $poolUsed,
            $rank,
            count($plans),
            $rank === 1,
            $rank === 1 ? null : ($plan['eval'] ?? null)
        );
    }
    if (!$draws) {
        $fail = array(
            'available' => false,
            'note' => '无法生成推荐方案，请检查平码明细是否有效。',
            'avoidNumbers' => $risk['numbers'],
            'avoidZodiacs' => $risk['zodiacs'],
            'avoidNumbersDisplay' => $risk['numbers_display'],
            'avoidZodiacsDisplay' => $risk['zodiacs_display'],
            'exposureHotNumbersDisplay' => $hotExposure['display'],
            'boughtNumberCount' => $boughtCount,
        );
        return array('draws' => array($fail), 'primary' => $fail);
    }

    $primaryEval = $draws[0]['summary'] ?? array();
    $profitMeta = collect_pingma_profit_rate_meta($stake, (float) ($primaryEval['net_profit'] ?? 0));
    if (!$profitMeta['meets_target']) {
        $draws[0]['note'] = ($draws[0]['note'] ?? $risk['recommend_note'])
            . ' 当前下注结构下最优方案盈利率仅 ' . $profitMeta['profit_rate']
            . '%，未达 ' . $targetPct . '% 目标，建议减少全覆盖/高热肖下注。';
    }

    return array('draws' => $draws, 'primary' => $draws[0]);
}

/**
 * 基于当前筛选范围内的平码下注，推荐 6 个平码使庄家派彩尽量低（盈利最大，不含特码）。
 *
 * @return array<string, mixed>|null
 */
function collect_pingma_recommend_profitable_draw(PDO $pdo, array $dbRows, int $drawTs): ?array
{
    $pack = collect_pingma_recommend_profitable_draws($pdo, $dbRows, $drawTs);
    return $pack['primary'];
}

/**
 * 平码赔率派彩统计（对照开奖逐组判中）。
 *
 * @return array<string, mixed>
 */
function collect_pingma_payout_stats(PDO $pdo, string $where, array $params, ?array $lotteryDraw, array $drawMeta = array(), bool $includeRecommend = false): array
{
    $periodGuess = trim((string) ($drawMeta['periodGuess'] ?? ''));
    $periodInput = trim((string) ($drawMeta['periodInput'] ?? ''));
    $dbRows = collect_pingma_fetch_bet_rows_for_payout($pdo, $where, $params);

    $drawTs = time();
    if ($lotteryDraw && !empty($lotteryDraw['zhengMa'])) {
        $drawTs = (int) ($lotteryDraw['drawTime'] ?? 0);
        if ($drawTs <= 0) {
            $drawTs = collect_zodiac_reference_unix_ts($pdo, (string) ($lotteryDraw['periodNumber'] ?? ''));
        }
    } elseif ($periodGuess !== '') {
        $drawTs = collect_zodiac_reference_unix_ts($pdo, $periodGuess);
    } elseif ($periodInput !== '') {
        $drawTs = collect_zodiac_reference_unix_ts($pdo, $periodInput);
    }
    $recommendPack = $includeRecommend
        ? collect_pingma_recommend_profitable_draws($pdo, $dbRows, $drawTs)
        : array('draws' => array(), 'primary' => null);
    $recommendedDraw = $recommendPack['primary'];
    $recommendedDraws = $recommendPack['draws'];

    $empty = array(
        'available' => false,
        'note' => '无法对照开奖：请在筛选栏填写已开奖期号，或确保 ay_kjdata 中已有该期记录。',
        'drawResolve' => $drawMeta,
        'oddsMap' => pingma_payout_odds_map(),
        'recommendedDraw' => $recommendedDraw,
        'recommendedDraws' => $recommendedDraws,
        'summary' => array(
            'total_stake' => 0.0,
            'total_payout' => 0.0,
            'net_profit' => 0.0,
            'bet_row_count' => 0,
            'group_count' => 0,
            'hit_group_count' => 0,
        ),
        'byPlayType' => array(),
        'rows' => array(),
    );
    if ($recommendedDraw && !empty($recommendedDraw['available']) && !empty($recommendedDraw['summary'])) {
        $empty['summary']['total_stake'] = $recommendedDraw['summary']['stake'];
    }
    if ($periodInput !== '') {
        $empty['note'] = '期号「' . $periodInput . '」在 ay_kjdata 中未找到已开奖记录，无法计算派彩。';
    } elseif ($periodGuess !== '') {
        $empty['note'] = '筛选范围内提交期号为「' . $periodGuess . '」，但开奖库无对应记录；请核对 ay_kjdata.number 或手动填写期号。';
    }
    if (!$lotteryDraw || empty($lotteryDraw['zhengMa'])) {
        return $empty;
    }

    $zhengMa = $lotteryDraw['zhengMa'];
    $teMa = (string) ($lotteryDraw['teMa'] ?? '');
    $zodiacHitSet = pingma_draw_zodiac_hit_set($zhengMa, $teMa, $drawTs);
    $zodiacHitList = array_keys($zodiacHitSet);
    sort($zodiacHitList);
    $drawBallsDisplay = collect_pingma_format_draw_balls_display($zhengMa, $teMa, $drawTs);

    $summary = array(
        'total_stake' => 0.0,
        'total_payout' => 0.0,
        'net_profit' => 0.0,
        'bet_row_count' => 0,
        'group_count' => 0,
        'hit_group_count' => 0,
        'zodiac_stake' => 0.0,
        'zodiac_payout' => 0.0,
        'number_stake' => 0.0,
        'number_payout' => 0.0,
    );
    $byPlay = array();
    $outRows = array();
    $displayLimit = 200;

    foreach ($dbRows as $row) {
        $bet = collect_pingma_bet_array_from_db_row($row);
        $calc = pingma_calc_bet_payout($bet, $zhengMa, $teMa, $drawTs);
        $pt = $bet['play_type'];
        $summary['bet_row_count']++;
        $summary['total_stake'] += $calc['stake'];
        $summary['total_payout'] += $calc['payout'];
        $summary['group_count'] += (int) $calc['group_count'];
        $summary['hit_group_count'] += (int) $calc['hit_count'];
        if ($bet['selection_type'] === 'zodiac') {
            $summary['zodiac_stake'] += $calc['stake'];
            $summary['zodiac_payout'] += $calc['payout'];
        } else {
            $summary['number_stake'] += $calc['stake'];
            $summary['number_payout'] += $calc['payout'];
        }

        if (!isset($byPlay[$pt])) {
            $byPlay[$pt] = array(
                'play_type' => $pt,
                'play_label' => $bet['play_label'],
                'odds' => $calc['odds'],
                'bet_count' => 0,
                'group_count' => 0,
                'hit_group_count' => 0,
                'stake' => 0.0,
                'payout' => 0.0,
                '_sel_agg' => collect_pingma_selection_agg_empty($bet['selection_type']),
            );
        }
        $byPlay[$pt]['bet_count']++;
        $byPlay[$pt]['group_count'] += (int) $calc['group_count'];
        $byPlay[$pt]['hit_group_count'] += (int) $calc['hit_count'];
        $byPlay[$pt]['stake'] = round($byPlay[$pt]['stake'] + $calc['stake'], 2);
        $byPlay[$pt]['payout'] = round($byPlay[$pt]['payout'] + $calc['payout'], 2);
        $periodNo = trim((string) ($row['period_no'] ?? ''));
        $rowZodiacTs = $periodNo !== ''
            ? collect_zodiac_reference_unix_ts($pdo, $periodNo)
            : $drawTs;
        collect_pingma_selection_agg_add_items(
            $byPlay[$pt]['_sel_agg'],
            $bet['selection'],
            $bet['selection_type'],
            $rowZodiacTs
        );

        if (count($outRows) < $displayLimit) {
            $rawSeg = (string) ($row['raw_segment'] ?? '');
            $nSel = count($bet['selection']);
            $isFushi = pingma_infer_is_fushi(
                $pt,
                (string) $bet['play_label'],
                (string) $bet['selection_type'],
                $nSel,
                $rawSeg
            );
            $betMini = array(
                'play_label' => $bet['play_label'],
                'selection_type' => $bet['selection_type'],
                'selection' => $bet['selection'],
                'group_count' => $bet['group_count'],
                'amount_per_group' => $bet['amount_per_group'],
                'total_amount' => $bet['total_amount'],
                'amount_mode' => $bet['amount_mode'],
                'is_fushi' => $isFushi,
            );
            $outRows[] = array(
                'id' => (int) ($row['id'] ?? 0),
                'display_text' => pingma_format_bet_line(pingma_recalc_bet_amounts($betMini)),
                'play_label' => $bet['play_label'],
                'play_type' => $pt,
                'period_no' => (string) ($row['period_no'] ?? ''),
                'key_name' => (string) ($row['key_name'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'group_count' => (int) $calc['group_count'],
                'hit_count' => (int) $calc['hit_count'],
                'amount_per_group' => $calc['amount_per_group'],
                'stake' => $calc['stake'],
                'odds' => $calc['odds'],
                'payout_per_hit' => $calc['payout_per_hit'],
                'payout' => $calc['payout'],
                'net_profit' => round((float) $calc['stake'] - (float) $calc['payout'], 2),
                'hit_groups_display' => $calc['hit_groups_display'],
                'hit_groups_numbers_display' => (string) ($calc['hit_groups_numbers_display'] ?? ''),
            );
        }
    }

    $summary['total_stake'] = round($summary['total_stake'], 2);
    $summary['total_payout'] = round($summary['total_payout'], 2);
    $summary['net_profit'] = round($summary['total_stake'] - $summary['total_payout'], 2);
    $summary['zodiac_stake'] = round($summary['zodiac_stake'], 2);
    $summary['zodiac_payout'] = round($summary['zodiac_payout'], 2);
    $summary['number_stake'] = round($summary['number_stake'], 2);
    $summary['number_payout'] = round($summary['number_payout'], 2);
    $summary['zodiac_payout_pct'] = $summary['total_payout'] > 0
        ? round(100 * $summary['zodiac_payout'] / $summary['total_payout'], 1)
        : 0.0;

    $byPlayList = array_values($byPlay);
    foreach ($byPlayList as &$playRow) {
        $playRow['net_profit'] = round((float) $playRow['stake'] - (float) $playRow['payout'], 2);
        if (isset($playRow['_sel_agg']) && is_array($playRow['_sel_agg'])) {
            $disp = collect_pingma_selection_agg_format_display($playRow['_sel_agg']);
            $playRow['selection_type'] = $disp['selection_type'];
            $playRow['selection_display'] = $disp['selection_display'];
            $playRow['numbers_display'] = $disp['numbers_display'];
            unset($playRow['_sel_agg']);
        }
    }
    unset($playRow);
    usort($byPlayList, static function ($a, $b) {
        return ($b['payout'] <=> $a['payout']) ?: strcmp((string) $a['play_type'], (string) $b['play_type']);
    });

    $resolveSource = trim((string) ($drawMeta['source'] ?? ''));
    $note = '派彩 = 中奖组数 × 每组本金 × 赔率（含本金）；号码类对照 6 平码，肖类对照 7 球生肖集合。';
    if ($resolveSource === 'submission' || $resolveSource === 'submission_resolved') {
        $note = '未填写筛选期号，已自动使用提交单期号「' . ($drawMeta['periodUsed'] ?? '') . '」对照开奖。' . $note;
    } elseif ($resolveSource === 'last_draw') {
        $note = '未填写筛选期号，已使用最近已开奖期「' . ($drawMeta['periodUsed'] ?? '') . '」对照。' . $note;
    }
    if ($summary['bet_row_count'] <= 0) {
        $note = '当前筛选范围内无平码明细（collect_submission_bets / parsed_json.bets 均为空）。';
    }

    return array(
        'available' => true,
        'note' => $note,
        'drawResolve' => $drawMeta,
        'oddsMap' => pingma_payout_odds_map(),
        'drawPeriod' => (string) ($lotteryDraw['periodNumber'] ?? ''),
        'zhengMa' => $zhengMa,
        'teMa' => $teMa,
        'zodiacHit' => $zodiacHitList,
        'drawBallsDisplay' => $drawBallsDisplay,
        'summary' => $summary,
        'byPlayType' => $byPlayList,
        'rows' => $outRows,
        'rows_truncated' => count($dbRows) > $displayLimit,
        'rows_total' => count($dbRows),
        'recommendedDraw' => $recommendedDraw,
        'recommendedDraws' => $recommendedDraws,
    );
}

/**
 * 同一提交内同一球号多条明细：按 config/collect.php duplicate_ball_merge 处理（sum|last）。
 */
function collect_merge_duplicate_ball_items(array $allItems, int $totalAmount): array
{
    $cfgPath = dirname(__DIR__, 2) . '/config/collect.php';
    $mode = 'sum';
    if (is_file($cfgPath)) {
        $cfg = require $cfgPath;
        if (is_array($cfg) && isset($cfg['duplicate_ball_merge'])) {
            $m = trim((string) $cfg['duplicate_ball_merge']);
            if ($m === 'last' || $m === 'sum') {
                $mode = $m;
            }
        }
    }
    if ($allItems === array()) {
        return array('items' => $allItems, 'total_amount' => 0);
    }

    if ($mode === 'sum') {
        $byNum = array();
        foreach ($allItems as $it) {
            $n = (string) ($it['num'] ?? '');
            if ($n === '') {
                continue;
            }
            if (!isset($byNum[$n])) {
                $byNum[$n] = array(
                    'num' => $n,
                    'tail' => substr($n, -1),
                    'amount' => 0,
                    'is_special' => (int) ($it['is_special'] ?? 1),
                );
            }
            $byNum[$n]['amount'] += (int) ($it['amount'] ?? 0);
        }
        $out = array_values($byNum);
        usort($out, static function ($a, $b) {
            return strcmp((string) ($a['num'] ?? ''), (string) ($b['num'] ?? ''));
        });
        $sum = 0;
        foreach ($out as $it) {
            $sum += (int) ($it['amount'] ?? 0);
        }
        return array('items' => $out, 'total_amount' => $sum);
    }

    $byNum = array();
    foreach ($allItems as $it) {
        $n = (string) ($it['num'] ?? '');
        if ($n === '') {
            continue;
        }
        $byNum[$n] = $it;
    }
    $out = array_values($byNum);
    usort($out, static function ($a, $b) {
        return strcmp((string) ($a['num'] ?? ''), (string) ($b['num'] ?? ''));
    });
    $sum = 0;
    foreach ($out as $it) {
        $sum += (int) ($it['amount'] ?? 0);
    }
    return array('items' => $out, 'total_amount' => $sum);
}

function get_period_no(): string
{
    $manual = trim((string) ($_POST['periodNo'] ?? ''));
    if ($manual === '') {
        $json = read_json_input();
        $manual = trim((string) ($json['periodNo'] ?? ''));
    }
    if ($manual !== '') {
        return $manual;
    }
    return '';
}

function is_submit_blocked(PDO $pdo): array
{
    $enabled = get_setting($pdo, 'submit_enabled', '1');
    if ($enabled === '0') {
        return array(true, '系统已关闭提交通道');
    }

    $nowMinute = (int) date('H') * 60 + (int) date('i');
    $stmt = $pdo->query('SELECT start_time, end_time FROM collect_block_windows WHERE enabled = 1 ORDER BY id DESC');
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $start = trim((string) $row['start_time']);
        $end = trim((string) $row['end_time']);
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
            continue;
        }
        $startMinute = ((int) substr($start, 0, 2)) * 60 + (int) substr($start, 3, 2);
        $endMinute = ((int) substr($end, 0, 2)) * 60 + (int) substr($end, 3, 2);
        $hit = false;
        if ($startMinute <= $endMinute) {
            $hit = ($nowMinute >= $startMinute && $nowMinute <= $endMinute);
        } else {
            $hit = ($nowMinute >= $startMinute || $nowMinute <= $endMinute);
        }
        if ($hit) {
            return array(true, '当前封盘中，暂不可提交');
        }
    }
    return array(false, '');
}

/**
 * 8 位日期期号（YYYYMMDD）：从每晚 **21:31（含）** 起按「次日」显示/推算。
 * 与封盘时段（collect_block_windows）无关，避免与业务封盘结束时间混用导致期号错位。
 */
function collect_date_period_cutoff_minute(): int
{
    return 21 * 60 + 31;
}

function collect_date_period_cutoff_hhmm(int $minute): string
{
    $m = max(0, min(24 * 60 - 1, $minute));
    $h = intdiv($m, 60);
    $mm = $m % 60;
    return str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $mm, 2, '0', STR_PAD_LEFT);
}

/** 返回 8 位日期期号（YYYYMMDD）；超过 cutoffMinute 则 +1 天。 */
function collect_suggest_date_period_no(int $cutoffMinute): string
{
    $nowMinute = (int) date('H') * 60 + (int) date('i');
    $dt = new DateTime('now');
    if ($nowMinute >= $cutoffMinute) {
        $dt->modify('+1 day');
    }
    return $dt->format('Ymd');
}

/** 当前为下属代理会话（非渠道主秘钥直登） */
function collect_user_is_agent_login(array $user): bool
{
    return (int) ($user['distributor_id'] ?? 0) > 0;
}

/**
 * 代理登录时：可统计/列表的提交 distributor_id（本人 + 所有下级；不含上级、同级）。
 *
 * @return int[]
 */
function collect_agent_viewable_submission_distributor_ids(PDO $pdo, int $passId, int $sessionDistributorId): array
{
    if ($sessionDistributorId <= 0) {
        return array();
    }
    return collect_distributor_descendant_ids_including_self($pdo, $passId, $sessionDistributorId);
}

/**
 * 代理登录时可见的“提交用户名”集合（本人 + 下级的登记名/路径名），用于阻断上级代提数据越权可见。
 *
 * @return array<string,bool>
 */
function collect_agent_viewable_submit_user_name_set(PDO $pdo, int $passId, int $sessionDistributorId): array
{
    $ids = collect_agent_viewable_submission_distributor_ids($pdo, $passId, $sessionDistributorId);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($x) {
        return $x > 0;
    })));
    if ($ids === array() || !collect_db_has_table($pdo, 'collect_distributors')) {
        return array();
    }
    $ph = array();
    $params = array(':p' => $passId);
    foreach ($ids as $i => $id) {
        $k = ':sn_' . $i;
        $ph[] = $k;
        $params[$k] = $id;
    }
    $hasP = collect_distributors_has_parent_id($pdo);
    $stmt = $pdo->prepare(
        'SELECT id, name' . ($hasP ? ', parent_id' : ', 0 AS parent_id') .
        ' FROM collect_distributors WHERE pass_id = :p AND id IN (' . implode(', ', $ph) . ')'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === array()) {
        return array();
    }
    $rows = collect_distributors_attach_path_label($rows);
    $set = array();
    foreach ($rows as $r) {
        $name = trim((string) ($r['name'] ?? ''));
        $label = trim((string) ($r['label'] ?? ''));
        if ($name !== '') {
            $set[$name] = true;
        }
        if ($label !== '') {
            $set[$label] = true;
        }
    }
    return $set;
}

/**
 * @param string $columnExpr 安全列名，如 distributor_id、s.distributor_id
 * @return array{sql:string,params:array<string,int>}
 */
function collect_submission_distributor_in_filter_sql(PDO $pdo, string $columnExpr, int $passId, int $sessionDistributorId, string $paramPrefix): array
{
    $ids = collect_agent_viewable_submission_distributor_ids($pdo, $passId, $sessionDistributorId);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($x) {
        return $x > 0;
    })));
    if ($ids === array()) {
        return array('sql' => ' AND 1=0 ', 'params' => array());
    }
    $ph = array();
    $params = array();
    foreach ($ids as $i => $id) {
        $k = ':' . $paramPrefix . '_' . $i;
        $ph[] = $k;
        $params[$k] = $id;
    }
    return array('sql' => ' AND ' . $columnExpr . ' IN (' . implode(', ', $ph) . ') ', 'params' => $params);
}

function get_collect_user_if_login(PDO $pdo): ?array
{
    if (!isset($_SESSION['collect_user']) || !is_array($_SESSION['collect_user'])) {
        return null;
    }
    $id = (int) ($_SESSION['collect_user']['id'] ?? 0);
    if ($id <= 0) {
        unset($_SESSION['collect_user']);
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, key_name, status FROM collect_passkeys WHERE id = :id LIMIT 1');
    $stmt->execute(array(':id' => $id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) ($row['status'] ?? 0) !== 1) {
        unset($_SESSION['collect_user']);
        return null;
    }
    $user = array(
        'id' => (int) $row['id'],
        'key_name' => trim((string) ($row['key_name'] ?? '')),
        'distributor_id' => 0,
        'distributor_name' => '',
    );
    $distId = (int) ($_SESSION['collect_user']['distributor_id'] ?? 0);
    if ($distId > 0) {
        if (!collect_db_has_table($pdo, 'collect_distributors')) {
            unset($_SESSION['collect_user']);
            return null;
        }
        $dq = $pdo->prepare('SELECT id, name, status FROM collect_distributors WHERE id = :d AND pass_id = :p LIMIT 1');
        $dq->execute(array(':d' => $distId, ':p' => $id));
        $dr = $dq->fetch(PDO::FETCH_ASSOC);
        if (!$dr || (int) ($dr['status'] ?? 0) !== 1) {
            unset($_SESSION['collect_user']);
            return null;
        }
        $user['distributor_id'] = $distId;
        $user['distributor_name'] = trim((string) ($dr['name'] ?? ''));
    }
    $_SESSION['collect_user'] = array(
        'id' => $user['id'],
        'key_name' => $user['key_name'],
        'distributor_id' => (int) ($user['distributor_id'] ?? 0),
        'distributor_name' => (string) ($user['distributor_name'] ?? ''),
    );
    return $user;
}

function require_user_login(PDO $pdo): array
{
    $user = get_collect_user_if_login($pdo);
    if (!$user) {
        json_response(array('ok' => false, 'message' => '请先登录'), 401);
    }
    return $user;
}

function require_admin_login(): void
{
    $cmsAdminLogged = !empty($_SESSION['sid']) && (!empty($_SESSION['id']) || strtolower((string) ($_SESSION['M'] ?? '')) === 'admin');
    $collectAdminLogged = !empty($_SESSION['collect_admin']);
    if (!$cmsAdminLogged && !$collectAdminLogged) {
        json_response(array('ok' => false, 'message' => '请先登录后台'), 401);
    }
}

function handle_admin_status(): void
{
    $cmsAdminLogged = !empty($_SESSION['sid']) && (!empty($_SESSION['id']) || strtolower((string) ($_SESSION['M'] ?? '')) === 'admin');
    $collectAdminLogged = !empty($_SESSION['collect_admin']);
    json_response(array(
        'ok' => true,
        'logged_in' => ($cmsAdminLogged || $collectAdminLogged)
    ));
}

/**
 * 提交页登录用户名：渠道主为 collect_passkeys.key_name；代理为「渠道名@代理登记名」（仅拆第一个 @，渠道名勿含 @）。
 *
 * @return array{mode:string,channel:string,agent:string} mode 为 channel|agent|invalid
 */
function collect_parse_submit_login_username(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return array('mode' => 'invalid', 'channel' => '', 'agent' => '');
    }
    $pos = strpos($raw, '@');
    if ($pos === false) {
        return array('mode' => 'channel', 'channel' => $raw, 'agent' => '');
    }
    $channel = trim(substr($raw, 0, $pos));
    $agent = trim(substr($raw, $pos + 1));
    if ($channel === '' || $agent === '') {
        return array('mode' => 'invalid', 'channel' => $channel, 'agent' => $agent);
    }
    return array('mode' => 'agent', 'channel' => $channel, 'agent' => $agent);
}

function collect_login_emit_agent_from_row(array $drow, bool $featDist): void
{
    $pid = (int) ($drow['pass_id'] ?? 0);
    $did = (int) ($drow['id'] ?? 0);
    $dname = trim((string) ($drow['name'] ?? ''));
    $kname = trim((string) ($drow['key_name'] ?? ''));
    if ($pid <= 0 || $did <= 0) {
        return;
    }
    $_SESSION['collect_user'] = array(
        'id' => $pid,
        'key_name' => $kname,
        'distributor_id' => $did,
        'distributor_name' => $dname,
    );
    json_response(array(
        'ok' => true,
        'message' => '代理登录成功',
        'key_name' => $kname,
        'loginAsAgent' => true,
        'distributorId' => $did,
        'distributorName' => $dname,
        'featureDistributors' => $featDist,
        'agentPasswordRequired' => true,
    ));
}

function handle_user_login(PDO $pdo): void
{
    $input = read_json_input();
    $username = trim((string) ($input['username'] ?? ''));
    $password = trim((string) ($input['password'] ?? ''));
    if ($username === '') {
        json_response(array('ok' => false, 'message' => '请输入用户名'), 400);
    }
    if ($password === '') {
        json_response(array('ok' => false, 'message' => '请输入密码'), 400);
    }

    $featDist = collect_submissions_has_distributor_cols($pdo) && collect_db_has_table($pdo, 'collect_distributors');
    $agentPwCol = collect_distributors_has_pass_hash($pdo);

    $parsed = collect_parse_submit_login_username($username);
    if ($parsed['mode'] === 'invalid') {
        json_response(array(
            'ok' => false,
            'message' => '代理登录请使用：渠道名称@代理名称（与后台一致；渠道名中请勿包含 @ 符号）',
        ), 400);
    }

    if ($parsed['mode'] === 'agent') {
        if (!$agentPwCol) {
            json_response(array('ok' => false, 'message' => '未启用代理密码，无法使用代理方式登录'), 400);
        }
        $stmt = $pdo->prepare(
            'SELECT d.id, d.pass_id, d.name, d.pass_hash, k.key_name
             FROM collect_distributors d
             INNER JOIN collect_passkeys k ON k.id = d.pass_id AND k.status = 1
             WHERE d.status = 1
               AND k.key_name = :kname
               AND d.name = :dname
               AND COALESCE(NULLIF(TRIM(d.pass_hash), \'\'), \'\') <> \'\''
        );
        $stmt->execute(array(':kname' => $parsed['channel'], ':dname' => $parsed['agent']));
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($candidates as $drow) {
            $dhash = (string) ($drow['pass_hash'] ?? '');
            if (password_verify($password, $dhash) || hash_equals($dhash, $password)) {
                collect_login_emit_agent_from_row($drow, $featDist);
            }
        }
        json_response(array('ok' => false, 'message' => '用户名或密码错误'), 401);
    }

    // 渠道主：用户名 = key_name（优先于同名代理，避免抢登）
    $stmt = $pdo->prepare('SELECT id, key_name, pass_hash FROM collect_passkeys WHERE status = 1 AND key_name = :k LIMIT 1');
    $stmt->execute(array(':k' => $parsed['channel']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $hash = (string) ($row['pass_hash'] ?? '');
        if (password_verify($password, $hash) || hash_equals($hash, $password)) {
            $_SESSION['collect_user'] = array(
                'id' => (int) $row['id'],
                'key_name' => (string) $row['key_name'],
                'distributor_id' => 0,
                'distributor_name' => '',
            );
            json_response(array(
                'ok' => true,
                'message' => '登录成功',
                'key_name' => $row['key_name'],
                'loginAsAgent' => false,
                'distributorId' => null,
                'distributorName' => '',
                'featureDistributors' => $featDist,
                'agentPasswordRequired' => $agentPwCol,
            ));
        }
    }

    if ($agentPwCol) {
        // 纯数字：分销主键 id（与列表 #123 一致），不要求 SQL 里预先过滤 pass_hash，便于区分「未设密」与「密码错」
        if (preg_match('/^\d+$/', $username)) {
            $didTry = (int) $username;
            if ($didTry > 0) {
                $stmtId = $pdo->prepare(
                    'SELECT d.id, d.pass_id, d.name, d.pass_hash, k.key_name
                     FROM collect_distributors d
                     INNER JOIN collect_passkeys k ON k.id = d.pass_id AND k.status = 1
                     WHERE d.id = :id AND d.status = 1 LIMIT 1'
                );
                $stmtId->execute(array(':id' => $didTry));
                $drow = $stmtId->fetch(PDO::FETCH_ASSOC);
                if ($drow) {
                    $ph = trim((string) ($drow['pass_hash'] ?? ''));
                    if ($ph === '') {
                        json_response(array(
                            'ok' => false,
                            'message' => '该代理（编号 #' . (int) $drow['id'] . '）尚未写入登录密码。请用渠道主在本页「添加分销」时填写代理密码≥4位，或由管理员在后台维护后再登录。',
                        ), 403);
                    }
                    if (password_verify($password, $ph) || hash_equals($ph, $password)) {
                        collect_login_emit_agent_from_row($drow, $featDist);
                    }
                    json_response(array(
                        'ok' => false,
                        'message' => '代理密码不正确。请确认：用户名为分销列表中的 #编号（纯数字），密码为添加该代理时所设（至少4位）。',
                    ), 401);
                }
            }
        }

        // 登记名与用户名完全一致（支持代理名叫「123456」等，且与 #id 不冲突）
        $stmtN = $pdo->prepare(
            'SELECT d.id, d.pass_id, d.name, d.pass_hash, k.key_name
             FROM collect_distributors d
             INNER JOIN collect_passkeys k ON k.id = d.pass_id AND k.status = 1
             WHERE d.status = 1 AND d.name = :n'
        );
        $stmtN->execute(array(':n' => $username));
        $byName = $stmtN->fetchAll(PDO::FETCH_ASSOC);
        $anyName = false;
        $anySet = false;
        foreach ($byName as $drow) {
            $anyName = true;
            $ph = trim((string) ($drow['pass_hash'] ?? ''));
            if ($ph === '') {
                continue;
            }
            $anySet = true;
            if (password_verify($password, $ph) || hash_equals($ph, $password)) {
                collect_login_emit_agent_from_row($drow, $featDist);
            }
        }
        if ($anyName && !$anySet) {
            json_response(array(
                'ok' => false,
                'message' => '存在同名代理但未设置登录密码。请渠道主或管理员补录代理密码后再试。',
            ), 403);
        }
        if ($anySet) {
            json_response(array('ok' => false, 'message' => '代理密码不正确。'), 401);
        }
    } elseif (preg_match('/^\d+$/', $username)) {
        json_response(array(
            'ok' => false,
            'message' => '当前库表缺少 collect_distributors.pass_hash，代理无法按编号/名称密码登录。请在库中执行迁移：打开项目 doc/collect_distributor_password.sql 执行其中 ALTER（或执行新版 doc/collect_distributor_purge.sql 第 3 段）。完成后刷新再试；渠道主仍可用「渠道名称 + 秘钥密码」登录。',
        ), 400);
    }

    json_response(array(
        'ok' => false,
        'message' => '用户名或密码错误。提示：渠道主=后台渠道名称；代理=列表#数字 或 渠道名@代理名 或与登记完全一致的分销名称。',
    ), 401);
}

function handle_user_status(PDO $pdo): void
{
    list($blocked, $msg) = is_submit_blocked($pdo);
    $user = get_collect_user_if_login($pdo);
    $cutoffMinute = collect_date_period_cutoff_minute();
    $cfg = collect_get_config();
    $cronSecret = trim((string) ($cfg['cron_purge_secret'] ?? ''));
    $isAgent = $user !== null && collect_user_is_agent_login($user);
    json_response(array(
        'ok' => true,
        'logged_in' => $user !== null,
        'key_name' => $user['key_name'] ?? '',
        'loginAsAgent' => $isAgent,
        'distributorId' => $isAgent ? (int) ($user['distributor_id'] ?? 0) : null,
        'distributorName' => $isAgent ? (string) ($user['distributor_name'] ?? '') : '',
        'blocked' => $blocked,
        'message' => $msg,
        // 前端日期期号（8位）默认值与切换点
        'datePeriodCutoffTime' => collect_date_period_cutoff_hhmm($cutoffMinute),
        'suggestedDatePeriodNo' => collect_suggest_date_period_no($cutoffMinute),
        'featureDistributors' => collect_submissions_has_distributor_cols($pdo) && collect_db_has_table($pdo, 'collect_distributors'),
        'agentPasswordRequired' => collect_distributors_has_pass_hash($pdo),
        'cronPurgeConfigured' => $cronSecret !== '',
        'cronPurgeHint' => '每日北京时间 12:00 清理「昨日」提交：在 config/collect.php 填写 cron_purge_secret 后，计划任务 GET ' .
            'ajax/collect/api.php?action=cron_purge_yesterday&secret=与配置一致',
    ));
}

function handle_user_change_password(PDO $pdo): void
{
    $user = require_user_login($pdo);
    $input = read_json_input();
    $oldPassword = trim((string) ($input['oldPassword'] ?? ''));
    $newPassword = trim((string) ($input['newPassword'] ?? ''));
    if ($oldPassword === '') {
        json_response(array('ok' => false, 'message' => '请输入当前密码'), 400);
    }
    if ($newPassword === '') {
        json_response(array('ok' => false, 'message' => '请输入新密码'), 400);
    }
    if (mb_strlen($newPassword) < 4) {
        json_response(array('ok' => false, 'message' => '新密码至少 4 位'), 400);
    }
    if (hash_equals($oldPassword, $newPassword)) {
        json_response(array('ok' => false, 'message' => '新密码不能与当前密码相同'), 400);
    }

    $agentDid = (int) ($user['distributor_id'] ?? 0);
    if ($agentDid > 0 && collect_distributors_has_pass_hash($pdo)) {
        $stmt = $pdo->prepare(
            'SELECT pass_hash FROM collect_distributors WHERE id = :id AND pass_id = :p AND status = 1 LIMIT 1'
        );
        $stmt->execute(array(':id' => $agentDid, ':p' => (int) $user['id']));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(array('ok' => false, 'message' => '账号不可用'), 403);
        }
        $hash = (string) $row['pass_hash'];
        $matched = password_verify($oldPassword, $hash) || hash_equals($hash, $oldPassword);
        if (!$matched) {
            json_response(array('ok' => false, 'message' => '当前密码错误'), 401);
        }
        $upd = $pdo->prepare('UPDATE collect_distributors SET pass_hash = :pass_hash, updated_at = NOW() WHERE id = :id AND pass_id = :p LIMIT 1');
        $upd->execute(array(
            ':pass_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => $agentDid,
            ':p' => (int) $user['id'],
        ));
        json_response(array('ok' => true, 'message' => '代理登录密码已修改，请牢记新密码'));
    }

    $stmt = $pdo->prepare('SELECT pass_hash FROM collect_passkeys WHERE id = :id AND status = 1 LIMIT 1');
    $stmt->execute(array(':id' => $user['id']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(array('ok' => false, 'message' => '账号不可用'), 403);
    }
    $hash = (string) $row['pass_hash'];
    $matched = password_verify($oldPassword, $hash) || hash_equals($hash, $oldPassword);
    if (!$matched) {
        json_response(array('ok' => false, 'message' => '当前密码错误'), 401);
    }

    $upd = $pdo->prepare('UPDATE collect_passkeys SET pass_hash = :pass_hash, updated_at = NOW() WHERE id = :id');
    $upd->execute(array(
        ':pass_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':id' => $user['id'],
    ));
    json_response(array('ok' => true, 'message' => '密码已修改，请牢记新密码'));
}

function collect_resolve_distributor_for_user_submit(PDO $pdo, int $passId, array $input): array
{
    if (!collect_submissions_has_distributor_cols($pdo) || !collect_db_has_table($pdo, 'collect_distributors')) {
        return array('distributor_id' => null, 'distributor_name' => '');
    }
    $did = (int) ($input['distributorId'] ?? 0);
    if ($did <= 0) {
        return array('distributor_id' => null, 'distributor_name' => '');
    }
    $stmt = $pdo->prepare(
        'SELECT id, name FROM collect_distributors WHERE id = :id AND pass_id = :p AND status = 1 LIMIT 1'
    );
    $stmt->execute(array(':id' => $did, ':p' => $passId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(array('ok' => false, 'message' => '分销端不存在或已停用，请刷新分销列表后重选'), 400);
    }
    return array(
        'distributor_id' => (int) $row['id'],
        'distributor_name' => trim((string) ($row['name'] ?? '')),
    );
}

function handle_user_distributors_list(PDO $pdo): void
{
    $user = require_user_login($pdo);
    if (!collect_db_has_table($pdo, 'collect_distributors')) {
        json_response(array('ok' => true, 'rows' => array(), 'hierarchySupported' => false, 'agentPasswordRequired' => false, 'loginAsAgent' => false));
    }
    $hasP = collect_distributors_has_parent_id($pdo);
    $hasW = collect_distributors_has_pass_hash($pdo);
    $pwSel = $hasW
        ? ', (CASE WHEN COALESCE(NULLIF(TRIM(pass_hash), \'\'), \'\') <> \'\' THEN 1 ELSE 0 END) AS has_password'
        : ', 0 AS has_password';
    $sql = $hasP
        ? 'SELECT id, parent_id, name, sort_order, status, created_at' . $pwSel . ' FROM collect_distributors WHERE pass_id = :p ORDER BY parent_id ASC, sort_order ASC, id ASC'
        : 'SELECT id, name, sort_order, status, created_at' . $pwSel . ' FROM collect_distributors WHERE pass_id = :p ORDER BY sort_order ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':p' => (int) $user['id']));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        unset($r['pass_hash']);
    }
    unset($r);
    if ($hasP) {
        $rows = collect_distributors_attach_path_label($rows);
    } else {
        foreach ($rows as &$r) {
            $r['parent_id'] = 0;
            $r['label'] = trim((string) ($r['name'] ?? ''));
        }
        unset($r);
    }
    $agentDid = (int) ($user['distributor_id'] ?? 0);
    if ($agentDid > 0) {
        // 提交归属下拉仅本人；下级数据仅在看板/我的提交中按层级可见
        $rows = array_values(array_filter($rows, static function ($r) use ($agentDid) {
            return (int) ($r['id'] ?? 0) === $agentDid;
        }));
    }
    json_response(array(
        'ok' => true,
        'rows' => $rows,
        'hierarchySupported' => $hasP,
        'agentPasswordRequired' => $hasW,
        'loginAsAgent' => $agentDid > 0,
    ));
}

function handle_user_distributor_save(PDO $pdo): void
{
    $user = require_user_login($pdo);
    if (collect_user_is_agent_login($user)) {
        json_response(array('ok' => false, 'message' => '代理账号不能新增分销，请使用渠道主密码登录'), 403);
    }
    if (!collect_db_has_table($pdo, 'collect_distributors')) {
        json_response(array('ok' => false, 'message' => '请先执行数据库脚本 doc/collect_distributor_purge.sql'), 400);
    }
    $input = read_json_input();
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 40) {
        json_response(array('ok' => false, 'message' => '分销名称 1–40 字'), 400);
    }
    $passId = (int) $user['id'];
    $parentId = collect_distributor_input_parent_id($pdo, $input);
    $err = collect_distributor_validate_parent_for_pass($pdo, $passId, $parentId);
    if ($err !== null) {
        json_response(array('ok' => false, 'message' => $err), 400);
    }
    $pw = collect_distributor_prepare_pass_hash($pdo, $input);
    if ($pw['error'] !== null) {
        json_response(array('ok' => false, 'message' => $pw['error']), 400);
    }
    try {
        collect_distributor_execute_insert($pdo, $passId, $parentId, $name, $pw['hash']);
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            json_response(array('ok' => false, 'message' => '同级下已有同名分销，请换名称'), 400);
        }
        json_response(array('ok' => false, 'message' => '保存失败：' . $e->getMessage()), 500);
    }
    json_response(array('ok' => true, 'message' => '已添加', 'id' => (int) $pdo->lastInsertId(), 'parentId' => $parentId));
}

function handle_user_distributor_delete(PDO $pdo): void
{
    $user = require_user_login($pdo);
    if (collect_user_is_agent_login($user)) {
        json_response(array('ok' => false, 'message' => '代理账号不能删除分销'), 403);
    }
    if (!collect_db_has_table($pdo, 'collect_distributors')) {
        json_response(array('ok' => false, 'message' => '表不存在'), 400);
    }
    $input = read_json_input();
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        json_response(array('ok' => false, 'message' => '参数 id 错误'), 400);
    }
    $passId = (int) $user['id'];
    $ids = collect_distributor_descendant_ids_including_self($pdo, $passId, $id);
    $ids = collect_distributor_ids_deepest_first($pdo, $passId, $ids);
    $n = 0;
    foreach ($ids as $did) {
        $stmt = $pdo->prepare('DELETE FROM collect_distributors WHERE id = :id AND pass_id = :p LIMIT 1');
        $stmt->execute(array(':id' => $did, ':p' => $passId));
        $n += $stmt->rowCount();
    }
    json_response(array('ok' => true, 'message' => $n > 0 ? '已删除（含下级）' : '记录不存在'));
}

/** 渠道主：修改下属分销登记名与/或代理登录密码（须已存在 pass_hash 列方可改密） */
function handle_user_distributor_update(PDO $pdo): void
{
    $user = require_user_login($pdo);
    if (collect_user_is_agent_login($user)) {
        json_response(array('ok' => false, 'message' => '代理账号不能修改分销，请使用渠道主密码登录'), 403);
    }
    if (!collect_db_has_table($pdo, 'collect_distributors')) {
        json_response(array('ok' => false, 'message' => '表不存在'), 400);
    }
    $input = read_json_input();
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        json_response(array('ok' => false, 'message' => '参数 id 错误'), 400);
    }
    $passId = (int) $user['id'];
    $hasP = collect_distributors_has_parent_id($pdo);
    $hasW = collect_distributors_has_pass_hash($pdo);
    if ($hasP) {
        $stmt = $pdo->prepare('SELECT id, name, parent_id FROM collect_distributors WHERE id = :id AND pass_id = :p LIMIT 1');
    } else {
        $stmt = $pdo->prepare('SELECT id, name FROM collect_distributors WHERE id = :id AND pass_id = :p LIMIT 1');
    }
    $stmt->execute(array(':id' => $id, ':p' => $passId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(array('ok' => false, 'message' => '记录不存在或无权限'), 404);
    }
    $oldName = trim((string) ($row['name'] ?? ''));
    $parentId = $hasP ? (int) ($row['parent_id'] ?? 0) : 0;

    $nameProvided = array_key_exists('name', $input);
    $newName = $nameProvided ? trim((string) ($input['name'] ?? '')) : null;
    if ($newName !== null) {
        if ($newName === '' || mb_strlen($newName) > 40) {
            json_response(array('ok' => false, 'message' => '分销名称 1–40 字'), 400);
        }
    }

    $pwdTrim = trim((string) ($input['password'] ?? ''));
    $doPass = false;
    $newHash = '';
    if ($pwdTrim !== '') {
        if (!$hasW) {
            json_response(array('ok' => false, 'message' => '库表无 pass_hash 列，无法修改代理密码'), 400);
        }
        if (strlen($pwdTrim) < 4 || strlen($pwdTrim) > 200) {
            json_response(array('ok' => false, 'message' => '新密码须 4–200 字符'), 400);
        }
        $doPass = true;
        $newHash = password_hash($pwdTrim, PASSWORD_DEFAULT);
    }

    if ($newName !== null && $newName === $oldName) {
        $newName = null;
    }
    if (!$doPass && $newName === null) {
        json_response(array('ok' => false, 'message' => '请填写与当前不同的名称，或填写新密码（至少4位）'), 400);
    }

    if ($newName !== null) {
        if ($hasP) {
            $dup = $pdo->prepare(
                'SELECT id FROM collect_distributors WHERE pass_id = :p AND parent_id = :par AND name = :n AND id <> :id LIMIT 1'
            );
            $dup->execute(array(':p' => $passId, ':par' => $parentId, ':n' => $newName, ':id' => $id));
        } else {
            $dup = $pdo->prepare(
                'SELECT id FROM collect_distributors WHERE pass_id = :p AND name = :n AND id <> :id LIMIT 1'
            );
            $dup->execute(array(':p' => $passId, ':n' => $newName, ':id' => $id));
        }
        if ($dup->fetch()) {
            json_response(array('ok' => false, 'message' => '该上级下已有同名分销'), 400);
        }
    }

    $sets = array();
    $params = array(':id' => $id, ':p' => $passId);
    if ($newName !== null) {
        $sets[] = 'name = :newn';
        $params[':newn'] = $newName;
    }
    if ($doPass) {
        $sets[] = 'pass_hash = :ph';
        $params[':ph'] = $newHash;
    }
    $sets[] = 'updated_at = NOW()';
    $sql = 'UPDATE collect_distributors SET ' . implode(', ', $sets) . ' WHERE id = :id AND pass_id = :p LIMIT 1';
    try {
        $upd = $pdo->prepare($sql);
        $upd->execute($params);
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            json_response(array('ok' => false, 'message' => '该上级下已有同名分销'), 400);
        }
        json_response(array('ok' => false, 'message' => '保存失败：' . $e->getMessage()), 500);
    }
    json_response(array('ok' => true, 'message' => '已保存'));
}

/**
 * 按分销代理聚合提交：名称（树路径）、单数、金额、条目。渠道主看全部下级；代理登录仅看本人及下级的统计。
 * GET: range=all|today（默认 all）
 */
function handle_user_agent_submission_stats(PDO $pdo): void
{
    $user = require_user_login($pdo);
    $passId = (int) $user['id'];
    if (!collect_submissions_has_distributor_cols($pdo) || !collect_db_has_table($pdo, 'collect_distributors')) {
        json_response(array('ok' => true, 'range' => 'all', 'rows' => array()));
        return;
    }

    $agentDidRaw = collect_user_is_agent_login($user) ? (int) ($user['distributor_id'] ?? 0) : 0;
    $useAgentDist = $agentDidRaw > 0 && collect_submissions_has_distributor_cols($pdo);
    $fDistS = $useAgentDist
        ? collect_submission_distributor_in_filter_sql($pdo, 's.distributor_id', $passId, $agentDidRaw, 'ags')
        : array('sql' => '', 'params' => array());

    $range = trim((string) ($_GET['range'] ?? 'all'));
    if ($range !== 'today') {
        $range = 'all';
    }
    $dateSql = '';
    $dateParams = array();
    if ($range === 'today') {
        $today = collect_today_ymd_shanghai();
        $dateSql = ' AND DATE(s.created_at) = :ags_d ';
        $dateParams[':ags_d'] = $today;
    }

    $q = $pdo->prepare(
        "SELECT COALESCE(s.distributor_id, 0) AS distributor_id,
                COALESCE(NULLIF(TRIM(s.distributor_name), ''), '') AS distributor_name,
                IFNULL(SUM(s.total_amount),0) AS amt,
                IFNULL(SUM(s.total_items),0) AS itm,
                COUNT(*) AS n_sub
         FROM collect_submissions s
         WHERE s.pass_id = :p
           AND COALESCE(s.distributor_id, 0) > 0" . $fDistS['sql'] . $dateSql . "
         GROUP BY COALESCE(s.distributor_id, 0), COALESCE(NULLIF(TRIM(s.distributor_name), ''), '')
         ORDER BY amt DESC"
    );
    $q->execute(array_merge(array(':p' => $passId), $fDistS['params'], $dateParams));
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    // 聚合同一代理下出现过的提交用户名（去重）。
    $submitUsersByDid = array();
    $qNames = $pdo->prepare(
        "SELECT COALESCE(s.distributor_id, 0) AS distributor_id, s.parsed_json
         FROM collect_submissions s
         WHERE s.pass_id = :p
           AND COALESCE(s.distributor_id, 0) > 0" . $fDistS['sql'] . $dateSql
    );
    $qNames->execute(array_merge(array(':p' => $passId), $fDistS['params'], $dateParams));
    $nameRows = $qNames->fetchAll(PDO::FETCH_ASSOC);
    foreach ($nameRows as $nr) {
        $did = (int) ($nr['distributor_id'] ?? 0);
        if ($did <= 0) {
            continue;
        }
        $nm = '';
        if (!empty($nr['parsed_json'])) {
            $decoded = json_decode((string) $nr['parsed_json'], true);
            if (is_array($decoded) && isset($decoded['submit_user_name'])) {
                $nm = trim((string) $decoded['submit_user_name']);
            }
        }
        if ($nm === '') {
            continue;
        }
        if (!isset($submitUsersByDid[$did])) {
            $submitUsersByDid[$did] = array();
        }
        $submitUsersByDid[$did][$nm] = true;
    }

    $idx = collect_export_distributor_index_for_pass($pdo, $passId);
    $out = array();
    foreach ($rows as $r) {
        $did = (int) ($r['distributor_id'] ?? 0);
        $snap = trim((string) ($r['distributor_name'] ?? ''));
        if ($did <= 0) {
            continue;
        }
        if (isset($idx['idToLabel'][$did]) && $idx['idToLabel'][$did] !== '') {
            $label = $idx['idToLabel'][$did];
        } elseif ($snap !== '') {
            $label = $snap;
        } else {
            $label = '代理 #' . $did;
        }
        $out[] = array(
            'distributor_id' => $did,
            'display_label' => $label,
            'distributor_name_snapshot' => $snap,
            'submission_count' => (int) ($r['n_sub'] ?? 0),
            'total_amount' => (int) ($r['amt'] ?? 0),
            'total_items' => (int) ($r['itm'] ?? 0),
            'submit_user_names' => isset($submitUsersByDid[$did]) ? implode('、', array_keys($submitUsersByDid[$did])) : '',
        );
    }

    json_response(array(
        'ok' => true,
        'range' => $range,
        'rows' => $out,
        'loginAsAgent' => $agentDidRaw > 0,
    ));
}

function handle_user_today_dashboard(PDO $pdo): void
{
    $user = require_user_login($pdo);
    $passId = (int) $user['id'];
    $agentDidRaw = collect_user_is_agent_login($user) ? (int) ($user['distributor_id'] ?? 0) : 0;
    $useAgentDist = $agentDidRaw > 0 && collect_submissions_has_distributor_cols($pdo);
    $fDist = $useAgentDist
        ? collect_submission_distributor_in_filter_sql($pdo, 'distributor_id', $passId, $agentDidRaw, 'agdt')
        : array('sql' => '', 'params' => array());
    $fDistS = $useAgentDist
        ? collect_submission_distributor_in_filter_sql($pdo, 's.distributor_id', $passId, $agentDidRaw, 'agds')
        : array('sql' => '', 'params' => array());
    $distFilter = $fDist['sql'];
    $distFilterS = $fDistS['sql'];
    $today = collect_today_ymd_shanghai();
    $draw = collect_lottery_last_draw($pdo);
    $teMa = ($draw && !empty($draw['teMa'])) ? (string) $draw['teMa'] : '';
    $drawPeriod = ($draw && !empty($draw['periodNumber'])) ? (string) $draw['periodNumber'] : '';

    $stmt = $pdo->prepare(
        'SELECT IFNULL(SUM(total_amount),0) AS t_amt, IFNULL(SUM(total_items),0) AS t_itm, COUNT(*) AS n_sub
         FROM collect_submissions WHERE pass_id = :p AND DATE(created_at) = :d' . $distFilter
    );
    $pToday = array_merge(array(':p' => $passId, ':d' => $today), $fDist['params']);
    $stmt->execute($pToday);
    $sum = $stmt->fetch(PDO::FETCH_ASSOC);

    $breakdown = array();
    if (collect_submissions_has_distributor_cols($pdo)) {
        $q2 = $pdo->prepare(
            "SELECT COALESCE(s.distributor_id, 0) AS distributor_id,
                    COALESCE(NULLIF(TRIM(s.distributor_name), ''), '（未指定）') AS distributor_name,
                    IFNULL(SUM(s.total_amount),0) AS amt,
                    IFNULL(SUM(s.total_items),0) AS itm,
                    COUNT(*) AS n_sub
             FROM collect_submissions s
             WHERE s.pass_id = :p AND DATE(s.created_at) = :d" . $distFilterS . "
             GROUP BY COALESCE(s.distributor_id, 0), COALESCE(NULLIF(TRIM(s.distributor_name), ''), '（未指定）')
             ORDER BY amt DESC"
        );
        $q2->execute(array_merge(array(':p' => $passId, ':d' => $today), $fDistS['params']));
        $breakdown = $q2->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $breakdown = array(
            array(
                'distributor_id' => 0,
                'distributor_name' => '全部',
                'amt' => $sum['t_amt'] ?? 0,
                'itm' => $sum['t_itm'] ?? 0,
                'n_sub' => $sum['n_sub'] ?? 0,
            ),
        );
    }

    $winBy = array();
    if ($teMa !== '' && $drawPeriod !== '') {
        $hasDist = collect_submissions_has_distributor_cols($pdo);
        if ($hasDist) {
            $stmt3 = $pdo->prepare(
                "SELECT i.num, i.amount, s.distributor_id,
                        COALESCE(NULLIF(TRIM(s.distributor_name), ''), '（未指定）') AS distributor_name
                 FROM collect_submission_items i
                 INNER JOIN collect_submissions s ON s.id = i.submission_id
                 WHERE s.pass_id = :p AND DATE(s.created_at) = :d AND s.period_no = :per" . $distFilterS
            );
        } else {
            $stmt3 = $pdo->prepare(
                'SELECT i.num, i.amount
                 FROM collect_submission_items i
                 INNER JOIN collect_submissions s ON s.id = i.submission_id
                 WHERE s.pass_id = :p AND DATE(s.created_at) = :d AND s.period_no = :per'
            );
        }
        $p3 = array_merge(
            array(':p' => $passId, ':d' => $today, ':per' => $drawPeriod),
            ($useAgentDist && $hasDist) ? $fDistS['params'] : array()
        );
        $stmt3->execute($p3);
        $items = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        $winMap = array();
        foreach ($items as $it) {
            if (!collect_item_num_matches_tema((string) ($it['num'] ?? ''), $teMa)) {
                continue;
            }
            if ($hasDist) {
                $dn = trim((string) ($it['distributor_name'] ?? ''));
                if ($dn === '') {
                    $dn = '（未指定）';
                }
                $did = (int) ($it['distributor_id'] ?? 0);
                $key = $did . '|' . $dn;
            } else {
                $key = '0|全部';
                $dn = '全部';
                $did = 0;
            }
            if (!isset($winMap[$key])) {
                $winMap[$key] = array(
                    'distributor_id' => $did,
                    'distributor_name' => $dn,
                    'win_amount' => 0,
                    'win_lines' => 0,
                );
            }
            $winMap[$key]['win_amount'] += (int) ($it['amount'] ?? 0);
            $winMap[$key]['win_lines']++;
        }
        $winBy = array_values($winMap);
    }

    $allTimeBreakdown = array();
    $allTimePassTotal = 0;
    if (collect_submissions_has_distributor_cols($pdo)) {
        $qa = $pdo->prepare(
            "SELECT COALESCE(s.distributor_id, 0) AS distributor_id,
                    COALESCE(NULLIF(TRIM(s.distributor_name), ''), '（未指定）') AS distributor_name,
                    IFNULL(SUM(s.total_amount),0) AS amt,
                    IFNULL(SUM(s.total_items),0) AS itm,
                    COUNT(*) AS n_sub
             FROM collect_submissions s
             WHERE s.pass_id = :p" . $distFilterS . "
             GROUP BY COALESCE(s.distributor_id, 0), COALESCE(NULLIF(TRIM(s.distributor_name), ''), '（未指定）')
             ORDER BY amt DESC"
        );
        $pAg = array_merge(array(':p' => $passId), $fDistS['params']);
        $qa->execute($pAg);
        $allTimeBreakdown = $qa->fetchAll(PDO::FETCH_ASSOC);
        $qtot = $pdo->prepare('SELECT IFNULL(SUM(total_amount),0) FROM collect_submissions WHERE pass_id = :p' . $distFilter);
        $qtot->execute(array_merge(array(':p' => $passId), $fDist['params']));
        $allTimePassTotal = (int) $qtot->fetchColumn();
    }

    json_response(array(
        'ok' => true,
        'today' => $today,
        'lastDraw' => $draw,
        'hitPeriodNo' => $drawPeriod,
        'teMa' => $teMa,
        'totalAmount' => (int) ($sum['t_amt'] ?? 0),
        'totalItems' => (int) ($sum['t_itm'] ?? 0),
        'submissionCount' => (int) ($sum['n_sub'] ?? 0),
        'breakdown' => $breakdown,
        'winByDistributor' => $winBy,
        'allTimeBreakdown' => $allTimeBreakdown,
        'allTimePassTotal' => $allTimePassTotal,
        'featureDistributors' => collect_submissions_has_distributor_cols($pdo) && collect_db_has_table($pdo, 'collect_distributors'),
        'agentPasswordRequired' => collect_distributors_has_pass_hash($pdo),
        'loginAsAgent' => $agentDidRaw > 0,
        'note' => '中奖金额按「最近已开奖」期号与特码，对今日提交且期号一致的明细逐条比对；请确保提交期号与开奖期号一致。',
    ));
}

function handle_cron_purge_yesterday(PDO $pdo, array $collectConfig): void
{
    $secret = trim((string) ($_GET['secret'] ?? ''));
    $expected = trim((string) ($collectConfig['cron_purge_secret'] ?? ''));
    if ($expected === '' || !hash_equals($expected, $secret)) {
        json_response(array('ok' => false, 'message' => '未授权：请在 config/collect.php 设置 cron_purge_secret 并携带相同 secret'), 403);
    }
    $day = collect_yesterday_ymd_shanghai();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'DELETE i FROM collect_submission_items i
             INNER JOIN collect_submissions s ON s.id = i.submission_id
             WHERE DATE(s.created_at) = :d'
        )->execute(array(':d' => $day));
        $del = $pdo->prepare('DELETE FROM collect_submissions WHERE DATE(created_at) = :d');
        $del->execute(array(':d' => $day));
        $n = $del->rowCount();
        $pdo->commit();
        json_response(array(
            'ok' => true,
            'message' => '已按日期清理昨日采集数据',
            'purgedDate' => $day,
            'deletedSubmissions' => $n,
        ));
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(array('ok' => false, 'message' => '清理失败：' . $e->getMessage()), 500);
    }
}

function handle_user_pingma_preview(PDO $pdo): void
{
    require_user_login($pdo);
    $input = read_json_input();
    $rawText = trim((string) ($input['rawText'] ?? ''));
    if ($rawText === '') {
        json_response(array('ok' => false, 'message' => '请输入平码提交内容'), 400);
    }
    $periodNo = trim((string) ($input['periodNo'] ?? ''));
    try {
        $parsed = pingma_parse_submit_text($rawText);
    } catch (Throwable $e) {
        json_response(array('ok' => false, 'message' => $e->getMessage()), 400);
    }
    json_response(array_merge(
        array('ok' => true, 'ballScope' => 'pingma'),
        collect_pingma_preview_fields_from_parsed($parsed, $pdo, $periodNo)
    ));
}

function handle_user_submit(PDO $pdo): void
{
    $user = require_user_login($pdo);
    list($blocked, $msg) = is_submit_blocked($pdo);
    if ($blocked) {
        json_response(array('ok' => false, 'message' => $msg), 403);
    }

    $input = read_json_input();
    $agentDid = (int) ($user['distributor_id'] ?? 0);
    if ($agentDid > 0) {
        if (!collect_submissions_has_distributor_cols($pdo)) {
            json_response(array('ok' => false, 'message' => '提交表未安装分销字段，代理账号无法提交'), 400);
        }
        $input['distributorId'] = $agentDid;
    }
    $submitUserName = trim((string) ($input['submitUserName'] ?? ''));
    $sessionUserName = trim((string) ($user['key_name'] ?? ''));
    $sessionDistributorName = trim((string) ($user['distributor_name'] ?? ''));
    if ($submitUserName !== '') {
        if ($agentDid > 0) {
            $ok = false;
            if ($sessionDistributorName !== '' && $submitUserName === $sessionDistributorName) {
                $ok = true;
            }
            if ($sessionUserName !== '' && $submitUserName === $sessionUserName) {
                $ok = true;
            }
            if (!$ok) {
                json_response(array('ok' => false, 'message' => '提交用户与登录用户不一致，请刷新后重试'), 400);
            }
        } elseif ($sessionUserName !== '' && $submitUserName !== $sessionUserName) {
            json_response(array('ok' => false, 'message' => '提交用户与登录用户不一致，请刷新后重试'), 400);
        }
    }
    if ($submitUserName === '') {
        $submitUserName = $agentDid > 0
            ? ($sessionDistributorName !== '' ? $sessionDistributorName : $sessionUserName)
            : $sessionUserName;
    }
    $rawText = trim((string) ($input['rawText'] ?? ''));
    $periodNo = trim((string) ($input['periodNo'] ?? ''));
    if ($periodNo === '') {
        $periodNo = collect_lottery_suggested_period($pdo);
    }
    if ($periodNo === '') {
        json_response(array(
            'ok' => false,
            'message' => '请填写期号。本站期号为 ay_kjdata.number 格式（如 2026278）；若已配置 ay_kjdata_time 会自动带出下期。',
        ), 400);
    }
    $resolved = collect_resolve_period_to_kj_number($pdo, $periodNo);
    $periodNo = $resolved['resolved'];
    $dist = collect_resolve_distributor_for_user_submit($pdo, (int) $user['id'], $input);
    $ballScope = collect_normalize_ball_scope((string) ($input['ballScope'] ?? 'tema'));

    if ($ballScope === 'pingma') {
        if (!collect_bets_table_exists($pdo)) {
            json_response(array(
                'ok' => false,
                'message' => '平码功能未就绪：请先在数据库执行 doc/collect_submission_bets.sql',
            ), 400);
        }
        try {
            $parsed = pingma_parse_submit_text($rawText);
        } catch (Throwable $e) {
            json_response(array('ok' => false, 'message' => $e->getMessage()), 400);
        }
        $parsedJsonArr = array(
            'bets' => $parsed['bets'],
            'submit_user_name' => $submitUserName,
            'distributor_id' => $dist['distributor_id'],
            'distributor_name' => $dist['distributor_name'],
            'ball_scope' => 'pingma',
            'total_groups' => $parsed['total_groups'],
            'total_bets' => $parsed['total_bets'],
            'formatted_text' => $parsed['formatted_text'] ?? $parsed['normalized_text'],
            'original_raw_text' => $rawText,
        );
        $formattedRawText = (string) ($parsed['formatted_text'] ?? $parsed['normalized_text'] ?? $rawText);
        $pdo->beginTransaction();
        try {
            $hasDistCol = collect_submissions_has_distributor_cols($pdo);
            if ($hasDistCol) {
                $stmt = $pdo->prepare(
                    'INSERT INTO collect_submissions(period_no, pass_id, distributor_id, distributor_name, raw_text, parsed_json, total_amount, total_items, client_ip, created_at)
                     VALUES(:period_no, :pass_id, :distributor_id, :distributor_name, :raw_text, :parsed_json, :total_amount, :total_items, :client_ip, NOW())'
                );
                $stmt->execute(array(
                    ':period_no' => $periodNo,
                    ':pass_id' => (int) $user['id'],
                    ':distributor_id' => $dist['distributor_id'],
                    ':distributor_name' => $dist['distributor_name'],
                    ':raw_text' => $formattedRawText,
                    ':parsed_json' => json_encode($parsedJsonArr, JSON_UNESCAPED_UNICODE),
                    ':total_amount' => $parsed['total_amount'],
                    ':total_items' => $parsed['total_groups'],
                    ':client_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                ));
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO collect_submissions(period_no, pass_id, raw_text, parsed_json, total_amount, total_items, client_ip, created_at)
                     VALUES(:period_no, :pass_id, :raw_text, :parsed_json, :total_amount, :total_items, :client_ip, NOW())'
                );
                $stmt->execute(array(
                    ':period_no' => $periodNo,
                    ':pass_id' => (int) $user['id'],
                    ':raw_text' => $formattedRawText,
                    ':parsed_json' => json_encode($parsedJsonArr, JSON_UNESCAPED_UNICODE),
                    ':total_amount' => $parsed['total_amount'],
                    ':total_items' => $parsed['total_groups'],
                    ':client_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                ));
            }
            $submissionId = (int) $pdo->lastInsertId();
            collect_save_submission_bets($pdo, $submissionId, $periodNo, $parsed['bets']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_response(array('ok' => false, 'message' => '保存失败：' . $e->getMessage()), 500);
        }
        json_response(array_merge(
            array(
                'ok' => true,
                'message' => '提交成功',
                'submissionId' => $submissionId,
                'submitUserName' => $submitUserName,
                'periodNo' => $periodNo,
                'periodInput' => $resolved['original'],
                'periodResolveVia' => $resolved['via'],
                'ballScope' => 'pingma',
                'distributorId' => $dist['distributor_id'],
                'distributorName' => $dist['distributor_name'],
            ),
            collect_pingma_preview_fields_from_parsed($parsed, $pdo, $periodNo)
        ));
    }

    try {
        $zodiacTs = collect_zodiac_reference_unix_ts($pdo, $periodNo);
        $parsed = parse_submit_text($rawText, $zodiacTs);
    } catch (Throwable $e) {
        json_response(array('ok' => false, 'message' => $e->getMessage()), 400);
    }

    $hasSpecialCol = collect_items_has_is_special($pdo);
    $rowsForDb = $parsed['items'];
    $totalItemsRow = $parsed['total_items'];
    if (!$hasSpecialCol) {
        $byNum = array();
        foreach ($parsed['items'] as $item) {
            $n = $item['num'];
            if (!isset($byNum[$n])) {
                $byNum[$n] = array(
                    'num' => $n,
                    'tail' => $item['tail'],
                    'amount' => 0,
                );
            }
            $byNum[$n]['amount'] += (int) $item['amount'];
        }
        $rowsForDb = array_values($byNum);
        $totalItemsRow = count($rowsForDb);
    }

    $parsedJsonArr = array(
        'items' => $parsed['items'],
        'submit_user_name' => $submitUserName,
        'distributor_id' => $dist['distributor_id'],
        'distributor_name' => $dist['distributor_name'],
        'ball_scope' => 'tema',
    );

    $pdo->beginTransaction();
    try {
        $hasDistCol = collect_submissions_has_distributor_cols($pdo);
        if ($hasDistCol) {
            $stmt = $pdo->prepare(
                'INSERT INTO collect_submissions(period_no, pass_id, distributor_id, distributor_name, raw_text, parsed_json, total_amount, total_items, client_ip, created_at)
                 VALUES(:period_no, :pass_id, :distributor_id, :distributor_name, :raw_text, :parsed_json, :total_amount, :total_items, :client_ip, NOW())'
            );
            $stmt->execute(array(
                ':period_no' => $periodNo,
                ':pass_id' => (int) $user['id'],
                ':distributor_id' => $dist['distributor_id'],
                ':distributor_name' => $dist['distributor_name'],
                ':raw_text' => $rawText,
                ':parsed_json' => json_encode($parsedJsonArr, JSON_UNESCAPED_UNICODE),
                ':total_amount' => $parsed['total_amount'],
                ':total_items' => $totalItemsRow,
                ':client_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ));
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO collect_submissions(period_no, pass_id, raw_text, parsed_json, total_amount, total_items, client_ip, created_at)
                 VALUES(:period_no, :pass_id, :raw_text, :parsed_json, :total_amount, :total_items, :client_ip, NOW())'
            );
            $stmt->execute(array(
                ':period_no' => $periodNo,
                ':pass_id' => (int) $user['id'],
                ':raw_text' => $rawText,
                ':parsed_json' => json_encode($parsedJsonArr, JSON_UNESCAPED_UNICODE),
                ':total_amount' => $parsed['total_amount'],
                ':total_items' => $totalItemsRow,
                ':client_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ));
        }
        $submissionId = (int) $pdo->lastInsertId();

        if ($hasSpecialCol) {
            $itemStmt = $pdo->prepare(
                'INSERT INTO collect_submission_items(submission_id, period_no, num, tail, amount, is_special, created_at)
                 VALUES(:submission_id, :period_no, :num, :tail, :amount, :is_special, NOW())'
            );
            foreach ($rowsForDb as $item) {
                $itemStmt->execute(array(
                    ':submission_id' => $submissionId,
                    ':period_no' => $periodNo,
                    ':num' => $item['num'],
                    ':tail' => $item['tail'],
                    ':amount' => $item['amount'],
                    ':is_special' => !empty($item['is_special']) ? 1 : 0,
                ));
            }
        } else {
            $itemStmt = $pdo->prepare(
                'INSERT INTO collect_submission_items(submission_id, period_no, num, tail, amount, created_at)
                 VALUES(:submission_id, :period_no, :num, :tail, :amount, NOW())'
            );
            foreach ($rowsForDb as $item) {
                $itemStmt->execute(array(
                    ':submission_id' => $submissionId,
                    ':period_no' => $periodNo,
                    ':num' => $item['num'],
                    ':tail' => $item['tail'],
                    ':amount' => $item['amount'],
                ));
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(array('ok' => false, 'message' => '保存失败：' . $e->getMessage()), 500);
    }

    json_response(array(
        'ok' => true,
        'message' => '提交成功',
        'submissionId' => $submissionId,
        'submitUserName' => $submitUserName,
        'periodNo' => $periodNo,
        'periodInput' => $resolved['original'],
        'periodResolveVia' => $resolved['via'],
        'totalAmount' => $parsed['total_amount'],
        'totalItems' => $parsed['total_items'],
        'ballScope' => $ballScope,
        'distributorId' => $dist['distributor_id'],
        'distributorName' => $dist['distributor_name'],
    ));
}

/**
 * 当前登录渠道（passkey）自己的提交列表，不含其他用户数据。
 */
function handle_user_my_submissions(PDO $pdo): void
{
    $user = require_user_login($pdo);
    $passId = (int) $user['id'];
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = max(1, min(100, (int) ($_GET['pageSize'] ?? 30)));
    $offset = ($page - 1) * $pageSize;
    $hasDistCols = collect_submissions_has_distributor_cols($pdo);

    $agentDid = collect_user_is_agent_login($user) && $hasDistCols
        ? (int) ($user['distributor_id'] ?? 0) : 0;
    $fList = $agentDid > 0
        ? collect_submission_distributor_in_filter_sql($pdo, 'distributor_id', $passId, $agentDid, 'myd')
        : array('sql' => '', 'params' => array());
    $distWhere = $fList['sql'];

    // 代理登录：再按“可见提交用户名”收敛，防止看到上级代提到下级分销的数据。
    $visibleUserWhere = '';
    $visibleUserParams = array();
    if ($agentDid > 0) {
        $submitUserNameSet = collect_agent_viewable_submit_user_name_set($pdo, $passId, $agentDid);
        if ($submitUserNameSet !== array()) {
            $parts = array();
            $i = 0;
            foreach (array_keys($submitUserNameSet) as $nm) {
                $name = trim((string) $nm);
                if ($name === '') {
                    continue;
                }
                $kLike = ':myv_like_' . $i;
                $kEq = ':myv_eq_' . $i;
                $needle = '"submit_user_name":' . json_encode($name, JSON_UNESCAPED_UNICODE);
                if ($hasDistCols) {
                    $parts[] = '(parsed_json LIKE ' . $kLike . ' OR distributor_name = ' . $kEq . ')';
                    $visibleUserParams[$kEq] = $name;
                } else {
                    $parts[] = 'parsed_json LIKE ' . $kLike;
                }
                $visibleUserParams[$kLike] = '%' . $needle . '%';
                $i++;
                if ($i >= 200) {
                    break;
                }
            }
            if ($parts === array()) {
                $visibleUserWhere = ' AND 1=0 ';
            } else {
                $visibleUserWhere = ' AND (' . implode(' OR ', $parts) . ') ';
            }
        }
    }

    $periodFilter = trim((string) ($_GET['periodNo'] ?? ''));
    $dateStart = trim((string) ($_GET['dateStart'] ?? ''));
    $dateEnd = trim((string) ($_GET['dateEnd'] ?? ''));
    $submitUserFilter = trim((string) ($_GET['submitUserName'] ?? ''));
    if (mb_strlen($submitUserFilter) > 80) {
        $submitUserFilter = mb_substr($submitUserFilter, 0, 80);
    }

    $extraWhere = '';
    $extraParams = array();
    if ($periodFilter !== '') {
        $extraWhere .= ' AND period_no = :my_period ';
        $extraParams[':my_period'] = $periodFilter;
    }
    if (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $dateStart)) {
        $extraWhere .= ' AND DATE(created_at) >= :my_ds ';
        $extraParams[':my_ds'] = $dateStart;
    }
    if (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $dateEnd)) {
        $extraWhere .= ' AND DATE(created_at) <= :my_de ';
        $extraParams[':my_de'] = $dateEnd;
    }
    if ($submitUserFilter !== '') {
        $needle = '"submit_user_name":' . json_encode($submitUserFilter, JSON_UNESCAPED_UNICODE);
        if ($hasDistCols) {
            $extraWhere .= ' AND (parsed_json LIKE :my_su_like OR distributor_name = :my_su_eq) ';
            $extraParams[':my_su_eq'] = $submitUserFilter;
        } else {
            $extraWhere .= ' AND parsed_json LIKE :my_su_like ';
        }
        $extraParams[':my_su_like'] = '%' . $needle . '%';
    }

    $baseParams = array_merge(array(':pid' => $passId), $fList['params'], $visibleUserParams);
    $countParams = array_merge($baseParams, $extraParams);

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM collect_submissions WHERE pass_id = :pid' . $distWhere . $visibleUserWhere . $extraWhere
    );
    $countStmt->execute($countParams);
    $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

    $sumStmt = $pdo->prepare(
        'SELECT IFNULL(SUM(total_amount),0) AS t_amt, IFNULL(SUM(total_items),0) AS t_itm, COUNT(*) AS n_sub
         FROM collect_submissions WHERE pass_id = :pid' . $distWhere . $visibleUserWhere . $extraWhere
    );
    $sumStmt->execute($countParams);
    $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: array();

    $optionsWhere = '';
    $optionsParams = array();
    if ($periodFilter !== '') {
        $optionsWhere .= ' AND period_no = :myo_period ';
        $optionsParams[':myo_period'] = $periodFilter;
    }
    if (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $dateStart)) {
        $optionsWhere .= ' AND DATE(created_at) >= :myo_ds ';
        $optionsParams[':myo_ds'] = $dateStart;
    }
    if (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $dateEnd)) {
        $optionsWhere .= ' AND DATE(created_at) <= :myo_de ';
        $optionsParams[':myo_de'] = $dateEnd;
    }
    $optSel = $hasDistCols ? 'parsed_json, distributor_name' : 'parsed_json';
    $optStmt = $pdo->prepare(
        'SELECT ' . $optSel . '
         FROM collect_submissions
         WHERE pass_id = :pid' . $distWhere . $visibleUserWhere . $optionsWhere . '
         ORDER BY id DESC
         LIMIT 1000'
    );
    $optStmt->execute(array_merge($baseParams, $optionsParams));
    $optRows = $optStmt->fetchAll(PDO::FETCH_ASSOC);
    $optSet = array();
    foreach ($optRows as $orow) {
        $nm = '';
        if (!empty($orow['parsed_json'])) {
            $decoded = json_decode((string) $orow['parsed_json'], true);
            if (is_array($decoded) && isset($decoded['submit_user_name'])) {
                $nm = trim((string) $decoded['submit_user_name']);
            }
        }
        if ($nm === '') {
            $nm = trim((string) ($orow['distributor_name'] ?? ''));
        }
        if ($nm !== '') {
            $optSet[$nm] = true;
        }
    }
    $submitUserOptions = array_keys($optSet);
    sort($submitUserOptions, SORT_NATURAL);

    $off = (int) $offset;
    $lim = (int) $pageSize;
    $selCols = 'id, period_no, total_amount, total_items, raw_text, created_at, parsed_json';
    if ($hasDistCols) {
        $selCols .= ', distributor_id, distributor_name';
    }
    $stmt = $pdo->prepare(
        "SELECT {$selCols}
         FROM collect_submissions
         WHERE pass_id = :pid" . $distWhere . $visibleUserWhere . $extraWhere . "
         ORDER BY id DESC
         LIMIT {$off}, {$lim}"
    );
    $stmt->execute($countParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['total_amount'] = (int) ($row['total_amount'] ?? 0);
        $row['total_items'] = (int) ($row['total_items'] ?? 0);
        $row['raw_text'] = (string) ($row['raw_text'] ?? '');
        $row['created_at'] = (string) ($row['created_at'] ?? '');
        $row['period_no'] = (string) ($row['period_no'] ?? '');
        $row['submit_user_name'] = '';
        $row['ball_scope'] = 'tema';
        $row['bet_summary'] = '';
        $row['formatted_text'] = '';
        if (!empty($row['parsed_json'])) {
            $decoded = json_decode((string) $row['parsed_json'], true);
            if (is_array($decoded) && isset($decoded['submit_user_name'])) {
                $row['submit_user_name'] = trim((string) $decoded['submit_user_name']);
            }
            if (is_array($decoded) && isset($decoded['ball_scope'])) {
                $row['ball_scope'] = collect_normalize_ball_scope((string) $decoded['ball_scope']);
            }
            if ($row['ball_scope'] === 'pingma') {
                $display = collect_pingma_build_user_display(is_array($decoded) ? $decoded : null, (string) ($row['raw_text'] ?? ''));
                $row['bet_summary'] = (string) ($display['bet_summary'] ?? '');
                $row['formatted_text'] = (string) ($display['formatted_text'] ?? '');
                if ($row['formatted_text'] === '') {
                    $orig = is_array($decoded) ? trim((string) ($decoded['original_raw_text'] ?? '')) : '';
                    if ($orig !== '' && !collect_pingma_looks_formatted_display($orig)) {
                        $row['formatted_text'] = $orig;
                        $row['bet_summary'] = $orig;
                    }
                }
                $row['total_amount'] = null;
                $row['total_items'] = null;
            }
        }
        unset($row['parsed_json']);
        if (isset($row['distributor_id'])) {
            $row['distributor_id'] = $row['distributor_id'] !== null ? (int) $row['distributor_id'] : null;
        }
        if (isset($row['distributor_name'])) {
            $row['distributor_name'] = (string) ($row['distributor_name'] ?? '');
        }
        // 历史数据可能没有 submit_user_name，回退到提交时的代理名称，再回退当前账号名，确保列表可见“提交用户”。
        if ($row['submit_user_name'] === '') {
            $dn = isset($row['distributor_name']) ? trim((string) $row['distributor_name']) : '';
            if ($dn !== '') {
                $row['submit_user_name'] = $dn;
            } else {
                $row['submit_user_name'] = (string) ($user['key_name'] ?? '');
            }
        }
    }
    unset($row);

    json_response(array(
        'ok' => true,
        'key_name' => (string) ($user['key_name'] ?? ''),
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'rows' => $rows,
        'filters' => array(
            'periodNo' => $periodFilter,
            'dateStart' => $dateStart,
            'dateEnd' => $dateEnd,
            'submitUserName' => $submitUserFilter,
        ),
        'summary' => array(
            'submissionCount' => (int) ($sumRow['n_sub'] ?? 0),
            'totalAmount' => (int) ($sumRow['t_amt'] ?? 0),
            'totalItems' => (int) ($sumRow['t_itm'] ?? 0),
        ),
        'submitUserOptions' => $submitUserOptions,
    ));
}

function collect_assert_can_delete_period(PDO $pdo, string $periodNo): void
{
    list($blocked, $bmsg) = is_submit_blocked($pdo);
    if ($blocked) {
        json_response(array('ok' => false, 'message' => '封盘时段内不可删除'), 403);
    }
    // cutoff 后：8位日期期号只能删“当前期号”
    if (preg_match('/^\d{8}$/', $periodNo)) {
        $cutoffMinute = collect_date_period_cutoff_minute();
        $nowMinute = (int) date('H') * 60 + (int) date('i');
        if ($nowMinute >= $cutoffMinute) {
            $cur = collect_suggest_date_period_no($cutoffMinute);
            if ($periodNo !== $cur) {
                json_response(array('ok' => false, 'message' => '已过 ' . collect_date_period_cutoff_hhmm($cutoffMinute) . '，不可删除上一期记录'), 403);
            }
        }
    }
}

/** 用户删除自己提交的记录（含明细）。 */
function handle_user_submission_delete(PDO $pdo): void
{
    $user = require_user_login($pdo);
    $passId = (int) $user['id'];
    $input = read_json_input();
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        json_response(array('ok' => false, 'message' => '参数 id 错误'), 400);
    }

    $selDel = 'id, pass_id, period_no, parsed_json';
    if (collect_submissions_has_distributor_cols($pdo)) {
        $selDel .= ', distributor_id';
    }
    $stmt = $pdo->prepare("SELECT {$selDel} FROM collect_submissions WHERE id = :id LIMIT 1");
    $stmt->execute(array(':id' => $id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(array('ok' => false, 'message' => '记录不存在或已删除'), 404);
    }
    if ((int) ($row['pass_id'] ?? 0) !== $passId) {
        json_response(array('ok' => false, 'message' => '无权删除该记录'), 403);
    }
    if (collect_user_is_agent_login($user) && collect_submissions_has_distributor_cols($pdo)) {
        $root = (int) ($user['distributor_id'] ?? 0);
        $allowed = collect_agent_viewable_submission_distributor_ids($pdo, $passId, $root);
        $okSet = array_fill_keys($allowed, true);
        $got = isset($row['distributor_id']) ? (int) $row['distributor_id'] : 0;
        if ($got <= 0 || !isset($okSet[$got])) {
            json_response(array('ok' => false, 'message' => '无权删除该记录'), 403);
        }
        $submitUserNameSet = collect_agent_viewable_submit_user_name_set($pdo, $passId, $root);
        $submitUserName = '';
        if (!empty($row['parsed_json'])) {
            $decoded = json_decode((string) $row['parsed_json'], true);
            if (is_array($decoded) && isset($decoded['submit_user_name'])) {
                $submitUserName = trim((string) $decoded['submit_user_name']);
            }
        }
        if ($submitUserName !== '' && $submitUserNameSet !== array() && !isset($submitUserNameSet[$submitUserName])) {
            json_response(array('ok' => false, 'message' => '无权删除该记录'), 403);
        }
    }
    $periodNo = trim((string) ($row['period_no'] ?? ''));
    collect_assert_can_delete_period($pdo, $periodNo);

    $pdo->beginTransaction();
    try {
        $delItems = $pdo->prepare('DELETE FROM collect_submission_items WHERE submission_id = :id');
        $delItems->execute(array(':id' => $id));
        $delMain = $pdo->prepare('DELETE FROM collect_submissions WHERE id = :id');
        $delMain->execute(array(':id' => $id));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(array('ok' => false, 'message' => '删除失败：' . $e->getMessage()), 500);
    }
    json_response(array('ok' => true, 'message' => '删除成功'));
}

function handle_admin_login(array $collectConfig): void
{
    $input = read_json_input();
    $password = trim((string) ($input['password'] ?? ''));
    $expected = (string) ($collectConfig['admin_password'] ?? '');
    if ($expected === '' || !hash_equals($expected, $password)) {
        json_response(array('ok' => false, 'message' => '后台密码错误'), 401);
    }
    $_SESSION['collect_admin'] = 1;
    json_response(array('ok' => true, 'message' => '后台登录成功'));
}

function handle_admin_get_settings(PDO $pdo): void
{
    require_admin_login();
    $enabled = get_setting($pdo, 'submit_enabled', '1');
    $rows = $pdo->query('SELECT id, start_time, end_time, enabled FROM collect_block_windows ORDER BY id DESC')->fetchAll();
    json_response(array(
        'ok' => true,
        'settings' => array(
            'submitEnabled' => $enabled === '1',
            'defaultPeriodNo' => trim((string) (get_setting($pdo, 'default_period_no', '') ?? '')),
            'pingmaTargetProfitPct' => (string) (int) collect_pingma_parse_target_profit_pct(
                (string) (get_setting($pdo, 'pingma_target_profit_pct', '70') ?? '70')
            ),
            'pingmaRecommendCount' => (string) collect_pingma_parse_recommend_count(
                (string) (get_setting($pdo, 'pingma_recommend_count', '3') ?? '3')
            ),
            'windows' => $rows
        )
    ));
}

function handle_admin_save_settings(PDO $pdo): void
{
    require_admin_login();
    $input = read_json_input();
    $submitEnabled = !empty($input['submitEnabled']) ? '1' : '0';
    $windows = $input['windows'] ?? array();
    if (!is_array($windows)) {
        json_response(array('ok' => false, 'message' => 'windows 参数错误'), 400);
    }

    if (array_key_exists('defaultPeriodNo', $input)) {
        $defaultPeriodNo = trim((string) $input['defaultPeriodNo']);
        if (mb_strlen($defaultPeriodNo) > 32) {
            json_response(array('ok' => false, 'message' => '默认期号过长（最多 32 字符）'), 400);
        }
    } else {
        $defaultPeriodNo = null;
    }

    $pingmaTargetProfitPct = null;
    if (array_key_exists('pingmaTargetProfitPct', $input)) {
        $rawPct = trim((string) $input['pingmaTargetProfitPct']);
        if ($rawPct === '' || !is_numeric($rawPct)) {
            json_response(array('ok' => false, 'message' => '推荐目标盈利率须为 1–99 的数字'), 400);
        }
        $pctVal = (float) $rawPct;
        if ($pctVal < 1 || $pctVal > 99) {
            json_response(array('ok' => false, 'message' => '推荐目标盈利率须在 1%–99% 之间'), 400);
        }
        $pingmaTargetProfitPct = (string) (int) round($pctVal);
    }

    $pingmaRecommendCount = null;
    if (array_key_exists('pingmaRecommendCount', $input)) {
        $rawCnt = trim((string) $input['pingmaRecommendCount']);
        if ($rawCnt === '' || !is_numeric($rawCnt)) {
            json_response(array('ok' => false, 'message' => '推荐方案数量须为 1–5 的整数'), 400);
        }
        $cntVal = (int) round((float) $rawCnt);
        if ($cntVal < 1 || $cntVal > 5) {
            json_response(array('ok' => false, 'message' => '推荐方案数量须在 1–5 之间'), 400);
        }
        $pingmaRecommendCount = (string) $cntVal;
    }

    $pdo->beginTransaction();
    try {
        set_setting($pdo, 'submit_enabled', $submitEnabled);
        if ($defaultPeriodNo !== null) {
            set_setting($pdo, 'default_period_no', $defaultPeriodNo);
        }
        if ($pingmaTargetProfitPct !== null) {
            set_setting($pdo, 'pingma_target_profit_pct', $pingmaTargetProfitPct);
        }
        if ($pingmaRecommendCount !== null) {
            set_setting($pdo, 'pingma_recommend_count', $pingmaRecommendCount);
        }
        $pdo->exec('DELETE FROM collect_block_windows');
        $stmt = $pdo->prepare(
            'INSERT INTO collect_block_windows(start_time, end_time, enabled, created_at, updated_at)
             VALUES(:start_time, :end_time, :enabled, NOW(), NOW())'
        );
        foreach ($windows as $window) {
            $start = trim((string) ($window['start_time'] ?? ''));
            $end = trim((string) ($window['end_time'] ?? ''));
            $enabledRow = !empty($window['enabled']) ? 1 : 0;
            if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
                continue;
            }
            $stmt->execute(array(
                ':start_time' => $start,
                ':end_time' => $end,
                ':enabled' => $enabledRow
            ));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(array('ok' => false, 'message' => '保存失败：' . $e->getMessage()), 500);
    }

    json_response(array('ok' => true, 'message' => '设置已保存'));
}

function handle_admin_passkey_list(PDO $pdo): void
{
    require_admin_login();
    $rows = $pdo->query(
        'SELECT id, key_name, status, created_at, updated_at
         FROM collect_passkeys
         ORDER BY id DESC'
    )->fetchAll();
    json_response(array('ok' => true, 'rows' => $rows));
}

function handle_admin_passkey_add(PDO $pdo): void
{
    require_admin_login();
    $input = read_json_input();
    $keyName = trim((string) ($input['keyName'] ?? ''));
    $password = trim((string) ($input['password'] ?? ''));
    $status = !empty($input['status']) ? 1 : 0;

    if ($keyName === '') {
        json_response(array('ok' => false, 'message' => '渠道名称不能为空'), 400);
    }
    if (mb_strlen($keyName) > 50) {
        json_response(array('ok' => false, 'message' => '渠道名称过长'), 400);
    }
    if ($password === '') {
        json_response(array('ok' => false, 'message' => '秘钥密码不能为空'), 400);
    }
    if (mb_strlen($password) < 4) {
        json_response(array('ok' => false, 'message' => '秘钥密码至少4位'), 400);
    }

    $exists = $pdo->prepare('SELECT id FROM collect_passkeys WHERE key_name = :key_name LIMIT 1');
    $exists->execute(array(':key_name' => $keyName));
    if ($exists->fetch()) {
        json_response(array('ok' => false, 'message' => '渠道名称已存在'), 400);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO collect_passkeys(key_name, pass_hash, status, created_at, updated_at)
         VALUES(:key_name, :pass_hash, :status, NOW(), NOW())'
    );
    $stmt->execute(array(
        ':key_name' => $keyName,
        ':pass_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':status' => $status
    ));
    json_response(array('ok' => true, 'message' => '秘钥已新增'));
}

function handle_admin_passkey_update(PDO $pdo): void
{
    require_admin_login();
    $input = read_json_input();
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        json_response(array('ok' => false, 'message' => '参数 id 错误'), 400);
    }

    $fields = array();
    $params = array(':id' => $id);

    if (array_key_exists('status', $input)) {
        $fields[] = 'status = :status';
        $params[':status'] = !empty($input['status']) ? 1 : 0;
    }
    if (array_key_exists('password', $input)) {
        $password = trim((string) $input['password']);
        if ($password === '') {
            json_response(array('ok' => false, 'message' => '新密码不能为空'), 400);
        }
        if (mb_strlen($password) < 4) {
            json_response(array('ok' => false, 'message' => '新密码至少4位'), 400);
        }
        $fields[] = 'pass_hash = :pass_hash';
        $params[':pass_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    if (!$fields) {
        json_response(array('ok' => false, 'message' => '没有可更新字段'), 400);
    }
    $fields[] = 'updated_at = NOW()';

    $sql = 'UPDATE collect_passkeys SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(array('ok' => true, 'message' => '秘钥已更新'));
}

function handle_admin_passkey_delete(PDO $pdo): void
{
    require_admin_login();
    $input = read_json_input();
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        json_response(array('ok' => false, 'message' => '参数 id 错误'), 400);
    }

    $stmt = $pdo->prepare('DELETE FROM collect_passkeys WHERE id = :id');
    $stmt->execute(array(':id' => $id));
    json_response(array('ok' => true, 'message' => '秘钥已删除'));
}

/** 后台：查看某渠道下属代理列表 + 各代理累计收款（提交单 total_amount 汇总） */
function handle_admin_distributor_list(PDO $pdo): void
{
    require_admin_login();
    $input = read_json_input();
    $passId = (int) ($input['passId'] ?? 0);
    if ($passId <= 0) {
        json_response(array('ok' => false, 'message' => '请传 passId（秘钥/渠道 ID）'), 400);
    }
    $chk = $pdo->prepare('SELECT id, key_name FROM collect_passkeys WHERE id = :id LIMIT 1');
    $chk->execute(array(':id' => $passId));
    $passRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$passRow) {
        json_response(array('ok' => false, 'message' => '渠道不存在'), 404);
    }
    if (!collect_db_has_table($pdo, 'collect_distributors')) {
        json_response(array(
            'ok' => true,
            'passId' => $passId,
            'key_name' => (string) ($passRow['key_name'] ?? ''),
            'rows' => array(),
            'hierarchySupported' => false,
            'agentPasswordRequired' => false,
            'submissionByAgent' => array(),
            'passTotalAmount' => 0,
            'dbNote' => '请先执行 doc/collect_distributor_purge.sql',
        ));
    }
    $hasP = collect_distributors_has_parent_id($pdo);
    $hasW = collect_distributors_has_pass_hash($pdo);
    $pwSel = $hasW
        ? ', (CASE WHEN COALESCE(NULLIF(TRIM(pass_hash), \'\'), \'\') <> \'\' THEN 1 ELSE 0 END) AS has_password'
        : ', 0 AS has_password';
    $sql = $hasP
        ? 'SELECT id, parent_id, name, sort_order, status, created_at' . $pwSel . ' FROM collect_distributors WHERE pass_id = :p ORDER BY parent_id ASC, sort_order ASC, id ASC'
        : 'SELECT id, name, sort_order, status, created_at' . $pwSel . ' FROM collect_distributors WHERE pass_id = :p ORDER BY sort_order ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':p' => $passId));
    $distRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($distRows as &$r0) {
        unset($r0['pass_hash']);
    }
    unset($r0);
    if ($hasP) {
        $distRows = collect_distributors_attach_path_label($distRows);
    } else {
        foreach ($distRows as &$r0) {
            $r0['parent_id'] = 0;
            $r0['label'] = trim((string) ($r0['name'] ?? ''));
        }
        unset($r0);
    }

    $submissionByAgent = array();
    $passTotalAmount = 0;
    $qs = $pdo->prepare('SELECT IFNULL(SUM(total_amount),0), COUNT(*) FROM collect_submissions WHERE pass_id = :p');
    $qs->execute(array(':p' => $passId));
    $totRow = $qs->fetch(PDO::FETCH_NUM);
    $passTotalAmount = (int) ($totRow[0] ?? 0);
    $passSubCount = (int) ($totRow[1] ?? 0);
    if (collect_submissions_has_distributor_cols($pdo)) {
        $q = $pdo->prepare(
            "SELECT COALESCE(s.distributor_id, 0) AS distributor_id,
                    COALESCE(NULLIF(TRIM(s.distributor_name), ''), '（未指定）') AS distributor_name,
                    IFNULL(SUM(s.total_amount),0) AS amt,
                    COUNT(*) AS n_sub
             FROM collect_submissions s
             WHERE s.pass_id = :p
             GROUP BY COALESCE(s.distributor_id, 0), COALESCE(NULLIF(TRIM(s.distributor_name), ''), '（未指定）')
             ORDER BY amt DESC"
        );
        $q->execute(array(':p' => $passId));
        $submissionByAgent = $q->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($passTotalAmount > 0 || $passSubCount > 0) {
        $submissionByAgent = array(
            array(
                'distributor_id' => 0,
                'distributor_name' => '全部（未安装分销字段）',
                'amt' => $passTotalAmount,
                'n_sub' => $passSubCount,
            ),
        );
    }

    json_response(array(
        'ok' => true,
        'passId' => $passId,
        'key_name' => (string) ($passRow['key_name'] ?? ''),
        'rows' => $distRows,
        'hierarchySupported' => $hasP,
        'agentPasswordRequired' => $hasW,
        'submissionByAgent' => $submissionByAgent,
        'passTotalAmount' => $passTotalAmount,
    ));
}

/** 后台：为指定渠道添加下属代理（与用户端 collect_submit 添加一致，写入 collect_distributors） */
function handle_admin_distributor_add(PDO $pdo): void
{
    require_admin_login();
    if (!collect_db_has_table($pdo, 'collect_distributors')) {
        json_response(array('ok' => false, 'message' => '请先执行 doc/collect_distributor_purge.sql'), 400);
    }
    $input = read_json_input();
    $passId = (int) ($input['passId'] ?? 0);
    $name = trim((string) ($input['name'] ?? ''));
    if ($passId <= 0) {
        json_response(array('ok' => false, 'message' => '请传 passId'), 400);
    }
    if ($name === '' || mb_strlen($name) > 40) {
        json_response(array('ok' => false, 'message' => '代理名称 1–40 字'), 400);
    }
    $chk = $pdo->prepare('SELECT id FROM collect_passkeys WHERE id = :id LIMIT 1');
    $chk->execute(array(':id' => $passId));
    if (!$chk->fetch()) {
        json_response(array('ok' => false, 'message' => '渠道不存在'), 404);
    }
    $parentId = collect_distributor_input_parent_id($pdo, $input);
    $err = collect_distributor_validate_parent_for_pass($pdo, $passId, $parentId);
    if ($err !== null) {
        json_response(array('ok' => false, 'message' => $err), 400);
    }
    $pw = collect_distributor_prepare_pass_hash($pdo, $input);
    if ($pw['error'] !== null) {
        json_response(array('ok' => false, 'message' => $pw['error']), 400);
    }
    try {
        collect_distributor_execute_insert($pdo, $passId, $parentId, $name, $pw['hash']);
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            json_response(array('ok' => false, 'message' => '该上级下已有同名代理，请换名称'), 400);
        }
        json_response(array('ok' => false, 'message' => '保存失败：' . $e->getMessage()), 500);
    }
    json_response(array('ok' => true, 'message' => '已添加', 'id' => (int) $pdo->lastInsertId(), 'parentId' => $parentId));
}

/** 后台：删除代理（须带 passId 与行一致，避免误删其它渠道；历史提交仍保留 distributor_name 快照） */
function handle_admin_distributor_delete(PDO $pdo): void
{
    require_admin_login();
    if (!collect_db_has_table($pdo, 'collect_distributors')) {
        json_response(array('ok' => false, 'message' => '表不存在'), 400);
    }
    $input = read_json_input();
    $id = (int) ($input['id'] ?? 0);
    $passId = (int) ($input['passId'] ?? 0);
    if ($id <= 0) {
        json_response(array('ok' => false, 'message' => '参数 id 错误'), 400);
    }
    if ($passId <= 0) {
        json_response(array('ok' => false, 'message' => '请传 passId 以确认归属渠道'), 400);
    }
    $ids = collect_distributor_descendant_ids_including_self($pdo, $passId, $id);
    $ids = collect_distributor_ids_deepest_first($pdo, $passId, $ids);
    $n = 0;
    foreach ($ids as $did) {
        $stmt = $pdo->prepare('DELETE FROM collect_distributors WHERE id = :id AND pass_id = :p LIMIT 1');
        $stmt->execute(array(':id' => $did, ':p' => $passId));
        $n += $stmt->rowCount();
    }
    json_response(array('ok' => true, 'message' => $n > 0 ? '已删除（含所有下级）' : '记录不存在或渠道不匹配'));
}

function build_where(array $input, array &$params, ?PDO $pdo = null): string
{
    $where = array('1=1');
    if (!empty($input['periodNo'])) {
        $raw = trim((string) $input['periodNo']);
        if ($pdo instanceof PDO) {
            $r = collect_resolve_period_to_kj_number($pdo, $raw);
            $where[] = '(s.period_no = :period_res OR s.period_no = :period_raw)';
            $params[':period_res'] = $r['resolved'];
            $params[':period_raw'] = $r['original'];
        } else {
            $where[] = 's.period_no = :period_no';
            $params[':period_no'] = $raw;
        }
    }
    if (!empty($input['startDate'])) {
        $where[] = 's.created_at >= :start_date';
        $params[':start_date'] = trim((string) $input['startDate']) . ' 00:00:00';
    }
    if (!empty($input['endDate'])) {
        $where[] = 's.created_at <= :end_date';
        $params[':end_date'] = trim((string) $input['endDate']) . ' 23:59:59';
    }
    $channelName = trim((string) ($input['channelName'] ?? ''));
    if ($channelName !== '') {
        if (mb_strlen($channelName) > 64) {
            $channelName = mb_substr($channelName, 0, 64);
        }
        $where[] = 'EXISTS (SELECT 1 FROM collect_passkeys pk WHERE pk.id = s.pass_id AND pk.key_name LIKE :channel_name_like)';
        $params[':channel_name_like'] = '%' . str_replace(
            array('\\', '%', '_'),
            array('\\\\', '\\%', '\\_'),
            $channelName
        ) . '%';
    } else {
        $passId = (int) ($input['passId'] ?? 0);
        if ($passId > 0) {
            $where[] = 's.pass_id = :pass_id';
            $params[':pass_id'] = $passId;
        }
    }
    $ballScope = trim((string) ($input['ballScope'] ?? ''));
    if ($ballScope !== '') {
        $where[] = collect_ball_scope_sql($ballScope, 's');
    }
    return implode(' AND ', $where);
}

function handle_admin_stats(PDO $pdo): void
{
    require_admin_login();
    $input = read_json_input();
    $params = array();
    $where = build_where($input, $params, $pdo);
    $ballScope = collect_normalize_ball_scope((string) ($input['ballScope'] ?? 'tema'));

    $stmt = $pdo->prepare("SELECT IFNULL(SUM(s.total_amount),0) AS total_amount, COUNT(*) AS submit_count, IFNULL(SUM(s.total_items),0) AS total_groups FROM collect_submissions s WHERE {$where}");
    $stmt->execute($params);
    $overview = $stmt->fetch();

    $periodFilter = trim((string) ($input['periodNo'] ?? ''));
    $drawResolveMeta = array();
    $lotteryDraw = null;
    if ($ballScope === 'pingma') {
        $resolvedDraw = collect_resolve_lottery_draw_for_admin($pdo, $periodFilter, $where, $params);
        $lotteryDraw = $resolvedDraw['draw'];
        $drawResolveMeta = $resolvedDraw['meta'];
    } elseif ($periodFilter !== '') {
        $lotteryDraw = collect_lottery_draw_by_period($pdo, $periodFilter);
    }

    $passStmt = $pdo->prepare(
        "SELECT p.key_name, COUNT(*) AS submit_count, IFNULL(SUM(s.total_amount),0) AS total_amount
         FROM collect_submissions s
         INNER JOIN collect_passkeys p ON p.id = s.pass_id
         WHERE {$where}
         GROUP BY s.pass_id
         ORDER BY total_amount DESC"
    );
    $passStmt->execute($params);
    $passStats = $passStmt->fetchAll();

    if ($ballScope === 'pingma') {
        $playTypeStats = array();
        $betRows = array();
        $betsTableOk = collect_bets_table_exists($pdo);
        if ($betsTableOk) {
            $playStmt = $pdo->prepare(
                "SELECT b.play_type, b.play_label, b.selection_type,
                        COUNT(*) AS bet_count,
                        IFNULL(SUM(b.group_count),0) AS group_count,
                        IFNULL(SUM(b.total_amount),0) AS total_amount
                 FROM collect_submission_bets b
                 INNER JOIN collect_submissions s ON s.id = b.submission_id
                 WHERE {$where}
                 GROUP BY b.play_type, b.play_label, b.selection_type
                 ORDER BY total_amount DESC, b.play_type ASC"
            );
            $playStmt->execute($params);
            $playTypeStats = $playStmt->fetchAll(PDO::FETCH_ASSOC);
            $playTypeStats = collect_pingma_enrich_play_type_stats($pdo, $playTypeStats, $where, $params);

            $betListStmt = $pdo->prepare(
                "SELECT b.id, b.submission_id, b.play_type, b.play_label, b.selection_type,
                        b.selection_json, b.group_count, b.amount_per_group, b.total_amount, b.raw_segment,
                        s.period_no, s.created_at,
                        COALESCE(NULLIF(TRIM(p.key_name), ''), CONCAT('渠道#', s.pass_id)) AS key_name
                 FROM collect_submission_bets b
                 INNER JOIN collect_submissions s ON s.id = b.submission_id
                 LEFT JOIN collect_passkeys p ON p.id = s.pass_id
                 WHERE {$where}
                 ORDER BY b.id DESC
                 LIMIT 100"
            );
            $betListStmt->execute($params);
            $betRows = $betListStmt->fetchAll();
            foreach ($betRows as &$br) {
                $norm = collect_pingma_bet_array_from_db_row($br);
                $br['selection'] = $norm['selection'] ?? array();
                $br['group_count'] = (int) ($norm['group_count'] ?? 0);
                $br['amount_per_group'] = (float) ($norm['amount_per_group'] ?? 0);
                $br['total_amount'] = (float) ($norm['total_amount'] ?? 0);
                $br['amount_mode'] = (string) ($norm['amount_mode'] ?? 'per_group');
                $rawSeg = (string) ($br['raw_segment'] ?? '');
                $nSel = count($br['selection']);
                $isFushi = pingma_infer_is_fushi(
                    (string) ($br['play_type'] ?? ''),
                    (string) ($br['play_label'] ?? ''),
                    (string) ($br['selection_type'] ?? 'number'),
                    $nSel,
                    $rawSeg
                );
                $betMini = array(
                    'play_label' => (string) ($br['play_label'] ?? ''),
                    'selection_type' => (string) ($br['selection_type'] ?? 'number'),
                    'selection' => $br['selection'],
                    'group_count' => $br['group_count'],
                    'amount_per_group' => $br['amount_per_group'],
                    'total_amount' => $br['total_amount'],
                    'amount_mode' => $br['amount_mode'],
                    'is_fushi' => $isFushi,
                );
                $br['display_text'] = pingma_format_bet_line($betMini);
                $selDisp = collect_pingma_bet_selection_display($pdo, $br);
                $br['selection_display'] = $selDisp['selection_display'];
                $br['numbers_display'] = $selDisp['numbers_display'];
                unset($br['selection_json']);
            }
            unset($br);
        }
        $pingmaCoverage = $betsTableOk
            ? collect_pingma_coverage_stats($pdo, $where, $params, $periodFilter)
            : collect_pingma_coverage_stats($pdo, '1=0', array(), $periodFilter);
        $pingmaPayout = collect_pingma_payout_stats($pdo, $where, $params, $lotteryDraw, $drawResolveMeta);
        $overview['normal_amount'] = 0;
        $overview['special_amount'] = 0;
        $overview['normal_item_count'] = 0;
        $overview['special_item_count'] = 0;
        json_response(array(
            'ok' => true,
            'specialStatsSupported' => false,
            'ballScope' => 'pingma',
            'betsTableOk' => $betsTableOk,
            'overview' => $overview,
            'lotteryDraw' => $lotteryDraw,
            'numberStatsAll' => array(),
            'tailStatsAll' => array(),
            'numberStatsNormal' => array(),
            'numberStatsSpecial' => array(),
            'tailStatsNormal' => array(),
            'tailStatsSpecial' => array(),
            'passStats' => $passStats,
            'playTypeStats' => $playTypeStats,
            'betRows' => $betRows,
            'pingmaCoverage' => $pingmaCoverage,
            'pingmaPayout' => $pingmaPayout,
        ));
    }

    $hasSpecialCol = collect_items_has_is_special($pdo);

    $numStmtAll = $pdo->prepare(
        "SELECT i.num, SUM(i.amount) AS total_amount, COUNT(*) AS item_count
         FROM collect_submission_items i
         INNER JOIN collect_submissions s ON s.id = i.submission_id
         WHERE {$where}
         GROUP BY i.num
         ORDER BY total_amount DESC, i.num ASC"
    );
    $numStmtAll->execute($params);
    $numberStatsAll = $numStmtAll->fetchAll();

    $tailStmtAll = $pdo->prepare(
        "SELECT i.tail, SUM(i.amount) AS total_amount, COUNT(*) AS item_count
         FROM collect_submission_items i
         INNER JOIN collect_submissions s ON s.id = i.submission_id
         WHERE {$where}
         GROUP BY i.tail
         ORDER BY total_amount DESC, i.tail ASC"
    );
    $tailStmtAll->execute($params);
    $tailStatsAll = $tailStmtAll->fetchAll();

    if ($hasSpecialCol) {
        $breakStmt = $pdo->prepare(
            "SELECT
                IFNULL(SUM(CASE WHEN COALESCE(i.is_special, 0) = 0 THEN i.amount ELSE 0 END), 0) AS normal_amount,
                IFNULL(SUM(CASE WHEN COALESCE(i.is_special, 0) = 1 THEN i.amount ELSE 0 END), 0) AS special_amount,
                SUM(CASE WHEN COALESCE(i.is_special, 0) = 0 THEN 1 ELSE 0 END) AS normal_item_count,
                SUM(CASE WHEN COALESCE(i.is_special, 0) = 1 THEN 1 ELSE 0 END) AS special_item_count
             FROM collect_submission_items i
             INNER JOIN collect_submissions s ON s.id = i.submission_id
             WHERE {$where}"
        );
        $breakStmt->execute($params);
        $breakdown = $breakStmt->fetch();
        $overview['normal_amount'] = $breakdown['normal_amount'] ?? 0;
        $overview['special_amount'] = $breakdown['special_amount'] ?? 0;
        $overview['normal_item_count'] = $breakdown['normal_item_count'] ?? 0;
        $overview['special_item_count'] = $breakdown['special_item_count'] ?? 0;

        $numWhereNormal = $where . ' AND COALESCE(i.is_special, 0) = 0';
        $numWhereSpecial = $where . ' AND COALESCE(i.is_special, 0) = 1';

        $numStmt = $pdo->prepare(
            "SELECT i.num, SUM(i.amount) AS total_amount, COUNT(*) AS item_count
             FROM collect_submission_items i
             INNER JOIN collect_submissions s ON s.id = i.submission_id
             WHERE {$numWhereNormal}
             GROUP BY i.num
             ORDER BY total_amount DESC, i.num ASC"
        );
        $numStmt->execute($params);
        $numberStatsNormal = $numStmt->fetchAll();

        $numStmtSp = $pdo->prepare(
            "SELECT i.num, SUM(i.amount) AS total_amount, COUNT(*) AS item_count
             FROM collect_submission_items i
             INNER JOIN collect_submissions s ON s.id = i.submission_id
             WHERE {$numWhereSpecial}
             GROUP BY i.num
             ORDER BY total_amount DESC, i.num ASC"
        );
        $numStmtSp->execute($params);
        $numberStatsSpecial = $numStmtSp->fetchAll();

        $tailStmt = $pdo->prepare(
            "SELECT i.tail, SUM(i.amount) AS total_amount, COUNT(*) AS item_count
             FROM collect_submission_items i
             INNER JOIN collect_submissions s ON s.id = i.submission_id
             WHERE {$numWhereNormal}
             GROUP BY i.tail
             ORDER BY total_amount DESC, i.tail ASC"
        );
        $tailStmt->execute($params);
        $tailStatsNormal = $tailStmt->fetchAll();

        $tailStmtSp = $pdo->prepare(
            "SELECT i.tail, SUM(i.amount) AS total_amount, COUNT(*) AS item_count
             FROM collect_submission_items i
             INNER JOIN collect_submissions s ON s.id = i.submission_id
             WHERE {$numWhereSpecial}
             GROUP BY i.tail
             ORDER BY total_amount DESC, i.tail ASC"
        );
        $tailStmtSp->execute($params);
        $tailStatsSpecial = $tailStmtSp->fetchAll();
    } else {
        $aggStmt = $pdo->prepare(
            "SELECT IFNULL(SUM(i.amount), 0) AS total_amt, COUNT(*) AS cnt
             FROM collect_submission_items i
             INNER JOIN collect_submissions s ON s.id = i.submission_id
             WHERE {$where}"
        );
        $aggStmt->execute($params);
        $agg = $aggStmt->fetch();
        $overview['normal_amount'] = $agg['total_amt'] ?? 0;
        $overview['special_amount'] = 0;
        $overview['normal_item_count'] = $agg['cnt'] ?? 0;
        $overview['special_item_count'] = 0;

        $numberStatsNormal = $numberStatsAll;
        $numberStatsSpecial = array();
        $tailStatsNormal = $tailStatsAll;
        $tailStatsSpecial = array();
    }

    json_response(array(
        'ok' => true,
        'specialStatsSupported' => $hasSpecialCol,
        'ballScope' => 'tema',
        'overview' => $overview,
        'lotteryDraw' => $lotteryDraw,
        'numberStatsAll' => $numberStatsAll,
        'tailStatsAll' => $tailStatsAll,
        'numberStatsNormal' => $numberStatsNormal,
        'numberStatsSpecial' => $numberStatsSpecial,
        'tailStatsNormal' => $tailStatsNormal,
        'tailStatsSpecial' => $tailStatsSpecial,
        'passStats' => $passStats,
        'temaCoverage' => collect_tema_coverage_stats($numberStatsAll),
    ));
}

/** 平码推荐开奖（独立接口，避免拖慢 admin_stats）。 */
function handle_admin_pingma_recommend(PDO $pdo): void
{
    require_admin_login();
    $input = read_json_input();
    $params = array();
    $where = build_where($input, $params, $pdo);

    $periodFilter = trim((string) ($input['periodNo'] ?? ''));
    $resolvedDraw = collect_resolve_lottery_draw_for_admin($pdo, $periodFilter, $where, $params);
    $drawMeta = $resolvedDraw['meta'];
    $lotteryDraw = $resolvedDraw['draw'];

    $drawTs = time();
    if ($lotteryDraw && !empty($lotteryDraw['zhengMa'])) {
        $drawTs = (int) ($lotteryDraw['drawTime'] ?? 0);
        if ($drawTs <= 0) {
            $drawTs = collect_zodiac_reference_unix_ts($pdo, (string) ($lotteryDraw['periodNumber'] ?? ''));
        }
    } else {
        $periodGuess = trim((string) ($drawMeta['periodGuess'] ?? ''));
        if ($periodGuess !== '') {
            $drawTs = collect_zodiac_reference_unix_ts($pdo, $periodGuess);
        } elseif ($periodFilter !== '') {
            $drawTs = collect_zodiac_reference_unix_ts($pdo, $periodFilter);
        }
    }

    $dbRows = collect_pingma_fetch_bet_rows_for_payout($pdo, $where, $params);
    $recommendPack = collect_pingma_recommend_profitable_draws($pdo, $dbRows, $drawTs);

    json_response(array(
        'ok' => true,
        'recommendedDraw' => $recommendPack['primary'],
        'recommendedDraws' => $recommendPack['draws'],
        'drawResolve' => $drawMeta,
    ));
}

function handle_admin_list(PDO $pdo): void
{
    require_admin_login();
    $input = read_json_input();
    $page = max(1, (int) ($input['page'] ?? 1));
    $pageSize = max(1, min(100, (int) ($input['pageSize'] ?? 20)));
    $offset = ($page - 1) * $pageSize;

    $params = array();
    $where = build_where($input, $params, $pdo);

    $countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM collect_submissions s WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) ($countStmt->fetch()['c'] ?? 0);

    $sql = "SELECT s.id, s.pass_id, s.period_no, s.total_amount, s.total_items, s.raw_text, s.parsed_json, s.created_at, s.client_ip,
                   COALESCE(NULLIF(TRIM(p.key_name), ''), CONCAT('渠道#', s.pass_id)) AS key_name
            FROM collect_submissions s
            LEFT JOIN collect_passkeys p ON p.id = s.pass_id
            WHERE {$where}
            ORDER BY s.id DESC
            LIMIT {$offset}, {$pageSize}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $submitUserName = '';
        $specialNum = '';
        $specialAmount = '';
        $ballScopeRow = 'tema';
        if (!empty($row['parsed_json'])) {
            $decoded = json_decode((string) $row['parsed_json'], true);
            if (is_array($decoded) && isset($decoded['submit_user_name'])) {
                $submitUserName = trim((string) $decoded['submit_user_name']);
            }
            if (is_array($decoded) && isset($decoded['ball_scope'])) {
                $ballScopeRow = collect_normalize_ball_scope((string) $decoded['ball_scope']);
            }
            if ($ballScopeRow === 'pingma') {
                $display = collect_pingma_build_display(is_array($decoded) ? $decoded : null, (string) ($row['raw_text'] ?? ''));
                $row['bet_summary'] = (string) ($display['bet_summary'] ?? '');
                $row['formatted_text'] = (string) ($display['formatted_text'] ?? '');
                if ($row['formatted_text'] === '' && $row['raw_text'] !== '') {
                    $row['formatted_text'] = $row['raw_text'];
                    $row['bet_summary'] = $row['raw_text'];
                }
            }
            $items = array();
            if (is_array($decoded)) {
                if (isset($decoded['items']) && is_array($decoded['items'])) {
                    $items = $decoded['items'];
                } elseif (array_keys($decoded) === range(0, count($decoded) - 1)) {
                    $items = $decoded;
                }
            }
            if ($items) {
                $specialNums = array();
                $specialAmountSum = 0;
                foreach ($items as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    if ((int) ($it['is_special'] ?? 0) === 1) {
                        $n = trim((string) ($it['num'] ?? ''));
                        if ($n !== '') {
                            $specialNums[] = $n;
                        }
                        $specialAmountSum += (int) ($it['amount'] ?? 0);
                    }
                }
                $specialNum = implode(',', $specialNums);
                if (strlen($specialNum) > 800) {
                    $specialNum = substr($specialNum, 0, 800) . '…(共' . count($specialNums) . '项)';
                }
                $specialAmount = $specialAmountSum > 0 ? (string) $specialAmountSum : '';
            }
        }
        // 兜底：按原文重算（仅特码）
        if ($ballScopeRow !== 'pingma' && $specialNum === '' && !empty($row['raw_text'])) {
            try {
                $pno = trim((string) ($row['period_no'] ?? ''));
                $zodiacTs = collect_zodiac_reference_unix_ts($pdo, $pno);
                $parsed = parse_submit_text((string) $row['raw_text'], $zodiacTs);
                $items2 = $parsed['items'] ?? array();
                if (is_array($items2) && !empty($items2)) {
                    $specialNums = array();
                    $specialAmountSum = 0;
                    foreach ($items2 as $it) {
                        if (!is_array($it)) {
                            continue;
                        }
                        if ((int) ($it['is_special'] ?? 0) === 1) {
                            $n = trim((string) ($it['num'] ?? ''));
                            if ($n !== '') {
                                $specialNums[] = $n;
                            }
                            $specialAmountSum += (int) ($it['amount'] ?? 0);
                        }
                    }
                    $specialNum = implode(',', $specialNums);
                    if (strlen($specialNum) > 800) {
                        $specialNum = substr($specialNum, 0, 800) . '…(共' . count($specialNums) . '项)';
                    }
                    $specialAmount = $specialAmountSum > 0 ? (string) $specialAmountSum : '';
                }
            } catch (Throwable $e) {
            }
        }
        if ($submitUserName !== '') {
            $row['key_name'] = $submitUserName;
        }
        $row['special_num'] = $specialNum;
        $row['special_amount'] = $specialAmount;
        $row['ball_scope'] = $ballScopeRow;
        if (!isset($row['bet_summary'])) {
            $row['bet_summary'] = '';
        }
        if (!isset($row['formatted_text'])) {
            $row['formatted_text'] = '';
        }
    }
    unset($row);

    json_response(array('ok' => true, 'total' => $total, 'rows' => $rows));
}

function csv_escape($value): string
{
    $s = (string) $value;
    $s = str_replace(array("\r\n", "\r", "\n"), ' ', $s);
    return '"' . str_replace('"', '""', $s) . '"';
}

function collect_export_html_cell($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 导出缓存：某 pass_id 下全部分销的树路径与 parent_id（不按 status 过滤，便于历史导出）。
 *
 * @return array{idToLabel: array<int,string>, idToParentId: array<int,int>, idToName: array<int,string>}
 */
function collect_export_distributor_index_for_pass(PDO $pdo, int $passId): array
{
    static $cache = array();
    if ($passId <= 0) {
        return array('idToLabel' => array(), 'idToParentId' => array(), 'idToName' => array());
    }
    if (isset($cache[$passId])) {
        return $cache[$passId];
    }
    $empty = array('idToLabel' => array(), 'idToParentId' => array(), 'idToName' => array());
    if (!collect_db_has_table($pdo, 'collect_distributors')) {
        $cache[$passId] = $empty;
        return $empty;
    }
    $hasP = collect_distributors_has_parent_id($pdo);
    $stmt = $pdo->prepare(
        'SELECT id, name' . ($hasP ? ', parent_id' : ', 0 AS parent_id') . ' FROM collect_distributors WHERE pass_id = :p'
    );
    $stmt->execute(array(':p' => $passId));
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($all === array()) {
        $cache[$passId] = $empty;
        return $empty;
    }
    if (!$hasP) {
        $out = array('idToLabel' => array(), 'idToParentId' => array(), 'idToName' => array());
        foreach ($all as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $nm = trim((string) ($r['name'] ?? ''));
            $out['idToLabel'][$id] = $nm;
            $out['idToParentId'][$id] = 0;
            $out['idToName'][$id] = $nm;
        }
        $cache[$passId] = $out;
        return $out;
    }
    $rows = collect_distributors_attach_path_label($all);
    $out = array('idToLabel' => array(), 'idToParentId' => array(), 'idToName' => array());
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out['idToLabel'][$id] = trim((string) ($r['label'] ?? ''));
        $out['idToParentId'][$id] = (int) ($r['parent_id'] ?? 0);
        $out['idToName'][$id] = trim((string) ($r['name'] ?? ''));
    }
    $cache[$passId] = $out;
    return $out;
}

/** 导出「渠道名称」列：有 distributor_id 时优先树路径，否则用表内快照名，再退回秘钥名 */
function collect_export_submission_tier_col(PDO $pdo, array $row): string
{
    $key = trim((string) ($row['key_name'] ?? ''));
    if (!collect_submissions_has_distributor_cols($pdo) || !collect_db_has_table($pdo, 'collect_distributors')) {
        return $key;
    }
    $did = isset($row['distributor_id']) ? (int) $row['distributor_id'] : 0;
    $dname = trim((string) ($row['distributor_name'] ?? ''));
    if ($did <= 0) {
        return $dname !== '' ? $dname : $key;
    }
    $passId = (int) ($row['pass_id'] ?? 0);
    if ($passId <= 0) {
        return $dname !== '' ? $dname : $key;
    }
    $idx = collect_export_distributor_index_for_pass($pdo, $passId);
    $lab = trim((string) ($idx['idToLabel'][$did] ?? ''));
    if ($lab !== '') {
        return $lab;
    }
    return $dname !== '' ? $dname : $key;
}

/**
 * 导出用：当前提交归属分销的「直属上级」展示名。
 * - 若分销在树中有上级（parent_id>0）：上级为上级分销的登记名。
 * - 若分销挂在秘钥下（parent_id=0 或无多级列）：上级为秘钥渠道名（如 asda），不再固定写「一级渠道」。
 * export_pass_key_name 须为秘钥名，且勿与 submit_user_name 覆盖后的 key_name 混用（在 handle_admin_export_list 里先写入）。
 */
function collect_export_parent_channel_name_for_row(PDO $pdo, array $row): string
{
    $passNm = trim((string) ($row['export_pass_key_name'] ?? ''));
    $fallbackTop = $passNm !== '' ? $passNm : '一级渠道';

    if (!collect_submissions_has_distributor_cols($pdo) || !collect_db_has_table($pdo, 'collect_distributors')) {
        return $fallbackTop;
    }
    $did = isset($row['distributor_id']) ? (int) $row['distributor_id'] : 0;
    if ($did <= 0) {
        return '一级渠道';
    }
    $passId = (int) ($row['pass_id'] ?? 0);
    if ($passId <= 0) {
        return $fallbackTop;
    }
    if (!collect_distributors_has_parent_id($pdo)) {
        return $fallbackTop;
    }
    $idx = collect_export_distributor_index_for_pass($pdo, $passId);
    $parId = null;
    if (isset($idx['idToParentId'][$did])) {
        $parId = (int) $idx['idToParentId'][$did];
    } else {
        $stmt = $pdo->prepare('SELECT parent_id FROM collect_distributors WHERE id = :id AND pass_id = :p LIMIT 1');
        $stmt->execute(array(':id' => $did, ':p' => $passId));
        $d = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            return $fallbackTop;
        }
        $parId = (int) ($d['parent_id'] ?? 0);
    }
    if ($parId <= 0) {
        return $fallbackTop;
    }
    if (isset($idx['idToName'][$parId])) {
        $nm = trim((string) $idx['idToName'][$parId]);
        if ($nm !== '') {
            return $nm;
        }
    }
    $pst = $pdo->prepare('SELECT name FROM collect_distributors WHERE id = :id AND pass_id = :p LIMIT 1');
    $pst->execute(array(':id' => $parId, ':p' => $passId));
    $pr = $pst->fetch(PDO::FETCH_ASSOC);
    $nm = trim((string) ($pr['name'] ?? ''));
    return $nm !== '' ? $nm : $fallbackTop;
}

/**
 * 将一条提交记录展开为导出用多行：生肖+号/肖按生肖分行并带对应球号；纯数字按球号分行；否则一行汇总（兼容旧格式）。
 *
 * @return array<int, array<int, string|int>>
 */
function collect_export_rows_for_submission(array $r, ?PDO $pdo = null): array
{
    $id = (string) ($r['id'] ?? '');
    $period = (string) ($r['period_no'] ?? '');
    $tierCol = (string) ($r['export_tier_col'] ?? $r['key_name'] ?? '');
    $parentCh = (string) ($r['parent_channel_name'] ?? '一级渠道');
    $totalAmount = (string) ($r['total_amount'] ?? '0');
    $totalItems = (string) ($r['total_items'] ?? '0');
    $created = (string) ($r['created_at'] ?? '');
    $ip = (string) ($r['client_ip'] ?? '');
    $fullRaw = (string) ($r['raw_text'] ?? '');

    $pno = trim((string) ($r['period_no'] ?? ''));
    if ($pdo instanceof PDO && $pno !== '') {
        $ts = collect_zodiac_reference_unix_ts($pdo, $pno);
    } elseif ($pno !== '') {
        $ts = collect_zodiac_reference_unix_ts_from_period_string_only($pno);
    } else {
        $t2 = strtotime($created);
        $ts = $t2 !== false ? (int) $t2 : time();
    }

    $lineStrings = preg_split('/\R/u', $fullRaw);
    if (!is_array($lineStrings)) {
        $lineStrings = array($fullRaw);
    }

    $blocks = array();
    $anyParsed = false;
    foreach ($lineStrings as $seg) {
        $seg = trim((string) $seg);
        if ($seg === '') {
            continue;
        }
        $parsed = bet_line_parse($seg, $ts);
        if (!empty($parsed['ok']) && (($parsed['type'] ?? '') === 'zodiac_hao' || ($parsed['type'] ?? '') === 'zodiac_xiao')) {
            $anyParsed = true;
            $blocks[] = array('kind' => 'zodiac', 'parsed' => $parsed);
        } elseif (!empty($parsed['ok']) && ($parsed['type'] ?? '') === 'pure_number') {
            $anyParsed = true;
            $blocks[] = array('kind' => 'pure', 'parsed' => $parsed);
        } else {
            $blocks[] = array('kind' => 'fail', 'text' => $seg);
        }
    }

    if (!$anyParsed) {
        $one = array(
            $id,
            '1',
            $period,
            $tierCol,
            $parentCh,
            '其他/混排',
            '',
            '',
            $totalItems,
            '',
            $totalAmount,
            '',
            '',
            $created,
            $ip,
            $fullRaw,
        );
        $one[11] = $totalAmount;
        $one[12] = $totalItems;
        return array($one);
    }

    $out = array();
    $seq = 0;
    $typeLabel = static function (string $t): string {
        if ($t === 'zodiac_hao') {
            return '生肖+号';
        }
        if ($t === 'zodiac_xiao') {
            return '生肖+肖';
        }
        return '纯数字';
    };

    foreach ($blocks as $block) {
        if (($block['kind'] ?? '') === 'fail') {
            $seq++;
            $seg = (string) ($block['text'] ?? '');
            $out[] = array(
                $id,
                (string) $seq,
                $period,
                $tierCol,
                $parentCh,
                '未解析行',
                '',
                $seg,
                '0',
                '',
                '0',
                '',
                '',
                $created,
                $ip,
                $fullRaw,
            );
            continue;
        }
        $parsed = $block['parsed'];
        $kind = $block['kind'];
        if ($kind === 'zodiac') {
            $items = $parsed['items'] ?? array();
            $itemNumbers = $parsed['item_numbers'] ?? array();
            $T = (int) ($parsed['amount'] ?? 0);
            $tname = $typeLabel((string) ($parsed['type'] ?? ''));
            $ptype = (string) ($parsed['type'] ?? '');
            if ($ptype === 'zodiac_xiao') {
                foreach ($items as $idx => $sx) {
                    $nums = $itemNumbers[$idx] ?? array();
                    if (!is_array($nums) || !$nums) {
                        continue;
                    }
                    $seq++;
                    $ballsStr = implode(',', $nums);
                    $nBalls = count($nums);
                    $pool = $T;
                    $ballParts = $nBalls > 0 ? collect_distribute_int_total($pool, $nBalls) : array();
                    $perBallStr = collect_format_per_ball_amounts($ballParts);
                    $subtotal = $pool;
                    $out[] = array(
                        $id,
                        (string) $seq,
                        $period,
                        $tierCol,
                        $parentCh,
                        $tname,
                        (string) $sx,
                        $ballsStr,
                        (string) $nBalls,
                        $perBallStr,
                        (string) $subtotal,
                        '',
                        '',
                        $created,
                        $ip,
                        $fullRaw,
                    );
                }
            } else {
                foreach ($items as $idx => $sx) {
                    $nums = $itemNumbers[$idx] ?? array();
                    if (!is_array($nums) || !$nums) {
                        continue;
                    }
                    $nBalls = count($nums);
                    $subtotal = $T * $nBalls;
                    $perBallStr = (string) $T;
                    $seq++;
                    $ballsStr = implode(',', $nums);
                    $out[] = array(
                        $id,
                        (string) $seq,
                        $period,
                        $tierCol,
                        $parentCh,
                        $tname,
                        (string) $sx,
                        $ballsStr,
                        (string) $nBalls,
                        $perBallStr,
                        (string) $subtotal,
                        '',
                        '',
                        $created,
                        $ip,
                        $fullRaw,
                    );
                }
            }
            continue;
        }
        if ($kind === 'pure') {
            $unitAmt = (int) ($parsed['amount'] ?? 0);
            $balls = $parsed['items'] ?? array();
            if (!is_array($balls)) {
                $balls = array();
            }
            foreach ($balls as $ball) {
                $seq++;
                $b = (string) $ball;
                $out[] = array(
                    $id,
                    (string) $seq,
                    $period,
                    $tierCol,
                    $parentCh,
                    '纯数字',
                    '球号' . $b,
                    $b,
                    '1',
                    (string) $unitAmt,
                    (string) $unitAmt,
                    '',
                    '',
                    $created,
                    $ip,
                    $fullRaw,
                );
            }
        }
    }

    if ($out !== array()) {
        $out[0][11] = $totalAmount;
        $out[0][12] = $totalItems;
        for ($i = 1, $n = count($out); $i < $n; $i++) {
            $out[$i][11] = '';
            $out[$i][12] = '';
        }
    }

    return $out;
}

/**
 * 平码明细导出一行（肖类含对应球号、倍率）。
 *
 * @return array<int, string>
 */
function collect_pingma_export_line_for_row(array $row, PDO $pdo): array
{
    $periodNo = trim((string) ($row['period_no'] ?? ''));
    $createdTs = strtotime((string) ($row['created_at'] ?? ''));
    $zodiacTs = $periodNo !== ''
        ? collect_zodiac_reference_unix_ts($pdo, $periodNo)
        : ($createdTs !== false ? (int) $createdTs : time());

    $sel = json_decode((string) ($row['selection_json'] ?? '[]'), true);
    if (!is_array($sel)) {
        $sel = array();
    }
    $selType = (string) ($row['selection_type'] ?? 'number');
    $disp = collect_pingma_expand_selection_display($selType, $sel, (int) $zodiacTs);

    $rawSeg = (string) ($row['raw_segment'] ?? '');
    $nSel = count($sel);
    $isFushi = pingma_infer_is_fushi(
        (string) ($row['play_type'] ?? ''),
        (string) ($row['play_label'] ?? ''),
        $selType,
        $nSel,
        $rawSeg
    );
    $bet = pingma_recalc_bet_amounts(array(
        'play_label' => (string) ($row['play_label'] ?? ''),
        'selection_type' => $selType,
        'selection' => $sel,
        'group_count' => (int) ($row['group_count'] ?? 0),
        'amount_per_group' => (float) ($row['amount_per_group'] ?? 0),
        'total_amount' => (float) ($row['total_amount'] ?? 0),
        'amount_mode' => (string) ($row['amount_mode'] ?? 'per_group'),
        'is_fushi' => $isFushi,
    ));

    $playType = (string) ($row['play_type'] ?? '');
    $odds = pingma_payout_odds_for_play_type($playType);
    $perGroup = (float) ($bet['amount_per_group'] ?? 0);
    $payoutPerHit = round($perGroup * $odds, 2);

    $zodiacNames = '';
    if ($selType === 'zodiac') {
        $zodiacNames = implode('', array_map('strval', $sel));
    }

    return array(
        (string) ($row['id'] ?? ''),
        (string) ($row['submission_id'] ?? ''),
        $periodNo,
        (string) ($row['key_name'] ?? ''),
        (string) ($row['play_label'] ?? ''),
        $selType === 'zodiac' ? '肖' : '号',
        $selType === 'zodiac' ? $zodiacNames : $disp['selection_display'],
        $disp['selection_display'],
        $disp['numbers_display'],
        $odds > 0 ? (string) $odds : '0',
        (string) ($row['group_count'] ?? 0),
        pingma_format_money($perGroup),
        pingma_format_money((float) ($bet['total_amount'] ?? 0)),
        pingma_format_money($payoutPerHit),
        pingma_format_bet_line($bet),
        $rawSeg,
        (string) ($row['created_at'] ?? ''),
    );
}

/** 平码提交明细导出（肖类含对应球号、倍率；默认仅肖类）。 */
function handle_admin_export_pingma(PDO $pdo): void
{
    require_admin_login();
    $input = array(
        'periodNo' => trim((string) ($_GET['periodNo'] ?? '')),
        'startDate' => trim((string) ($_GET['startDate'] ?? '')),
        'endDate' => trim((string) ($_GET['endDate'] ?? '')),
        'channelName' => trim((string) ($_GET['channelName'] ?? '')),
        'passId' => (int) ($_GET['passId'] ?? 0),
        'ballScope' => 'pingma',
    );
    $selectionType = strtolower(trim((string) ($_GET['selectionType'] ?? 'zodiac')));
    if ($selectionType !== 'all' && $selectionType !== 'number') {
        $selectionType = 'zodiac';
    }

    $format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
    if ($format !== 'csv' && $format !== 'html') {
        $format = 'csv';
    }

    $params = array();
    $where = build_where($input, $params, $pdo);
    $dbRows = collect_pingma_fetch_bet_rows_for_payout($pdo, $where, $params);
    if ($selectionType !== 'all') {
        $dbRows = array_values(array_filter($dbRows, static function (array $row) use ($selectionType) {
            return (string) ($row['selection_type'] ?? '') === $selectionType;
        }));
    }

    $headers = array(
        '明细ID',
        '提交ID',
        '期号',
        '渠道名称',
        '玩法',
        '类型',
        '选肖',
        '选号/选肖（含对应球号）',
        '全部球号',
        '倍率',
        '组数',
        '每组金额',
        '合计金额',
        '中1组派彩',
        '格式化内容',
        '原文片段',
        '提交时间',
    );

    $exportLines = array();
    foreach ($dbRows as $row) {
        $exportLines[] = collect_pingma_export_line_for_row($row, $pdo);
    }

    $scopeTag = $selectionType === 'all' ? 'pingma_all' : ($selectionType === 'number' ? 'pingma_number' : 'pingma_zodiac');

    if ($format === 'html') {
        $filename = 'collect_' . $scopeTag . '_' . date('Ymd_His') . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo "\xEF\xBB\xBF";
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"><title>平码导出</title>';
        echo '<style type="text/css">table{border-collapse:collapse;}td,th{border:1px solid #ccc;padding:6px 8px;font-size:12px;}th{background:#f0f0f0;font-weight:bold;text-align:center;}</style>';
        echo '</head><body><table><thead><tr>';
        foreach ($headers as $h) {
            echo '<th>' . collect_export_html_cell($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($exportLines as $line) {
            echo '<tr>';
            foreach ($line as $cell) {
                echo '<td>' . collect_export_html_cell($cell) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    $filename = 'collect_' . $scopeTag . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map('csv_escape', $headers)) . "\r\n";
    foreach ($exportLines as $line) {
        echo implode(',', array_map('csv_escape', $line)) . "\r\n";
    }
    exit;
}

function handle_admin_export_list(PDO $pdo): void
{
    require_admin_login();
    $input = array(
        'periodNo' => trim((string) ($_GET['periodNo'] ?? '')),
        'startDate' => trim((string) ($_GET['startDate'] ?? '')),
        'endDate' => trim((string) ($_GET['endDate'] ?? '')),
        'channelName' => trim((string) ($_GET['channelName'] ?? '')),
        'passId' => (int) ($_GET['passId'] ?? 0),
        'ballScope' => trim((string) ($_GET['ballScope'] ?? '')),
    );

    $format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
    if ($format !== 'csv' && $format !== 'html') {
        $format = 'csv';
    }

    $params = array();
    $where = build_where($input, $params, $pdo);

    $distSel = collect_submissions_has_distributor_cols($pdo) ? ', s.distributor_id, s.distributor_name' : '';
    $sql = "SELECT s.id, s.pass_id{$distSel}, s.period_no, s.total_amount, s.total_items, s.raw_text, s.parsed_json, s.created_at, s.client_ip,
                   COALESCE(NULLIF(TRIM(p.key_name), ''), CONCAT('渠道#', s.pass_id)) AS key_name
            FROM collect_submissions s
            LEFT JOIN collect_passkeys p ON p.id = s.pass_id
            WHERE {$where}
            ORDER BY s.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['export_pass_key_name'] = trim((string) ($row['key_name'] ?? ''));
        $submitUserName = '';
        if (!empty($row['parsed_json'])) {
            $decoded = json_decode((string) $row['parsed_json'], true);
            if (is_array($decoded) && isset($decoded['submit_user_name'])) {
                $submitUserName = trim((string) $decoded['submit_user_name']);
            }
        }
        if ($submitUserName !== '') {
            $row['key_name'] = $submitUserName;
        }
        $row['export_tier_col'] = collect_export_submission_tier_col($pdo, $row);
        $row['parent_channel_name'] = collect_export_parent_channel_name_for_row($pdo, $row);
    }
    unset($row);

    $headers = array(
        '提交ID',
        '行号',
        '期号',
        '渠道名称',
        '上级渠道名称',
        '类型',
        '生肖',
        '对应号码',
        '球数',
        '单注金额',
        '明细小计',
        '提交总金额',
        '提交总条目',
        '提交时间',
        'IP',
        '原文全文',
    );

    if ($format === 'html') {
        $filename = 'collect_submissions_' . date('Ymd_His') . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo "\xEF\xBB\xBF";
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"><title>导出</title>';
        echo '<style type="text/css">table{border-collapse:collapse;}td,th{border:1px solid #ccc;padding:6px 8px;font-size:12px;}th{background:#f0f0f0;font-weight:bold;text-align:center;}</style>';
        echo '</head><body><table>';
        echo '<thead><tr>';
        foreach ($headers as $h) {
            echo '<th>' . collect_export_html_cell($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $lines = collect_export_rows_for_submission($r, $pdo);
            $n = count($lines);
            if ($n < 1) {
                continue;
            }
            foreach ($lines as $i => $line) {
                echo '<tr>';
                for ($c = 0; $c < 16; $c++) {
                    if ($c === 11 || $c === 12) {
                        if ($n > 1) {
                            if ($i === 0) {
                                echo '<td rowspan="' . (int) $n . '" style="vertical-align:middle;text-align:center">' . collect_export_html_cell($line[$c] ?? '') . '</td>';
                            }
                        } else {
                            echo '<td>' . collect_export_html_cell($line[$c] ?? '') . '</td>';
                        }
                        continue;
                    }
                    echo '<td>' . collect_export_html_cell($line[$c] ?? '') . '</td>';
                }
                echo '</tr>';
            }
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    $filename = 'collect_submissions_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo "\xEF\xBB\xBF";

    echo implode(',', array_map('csv_escape', $headers)) . "\r\n";
    foreach ($rows as $r) {
        foreach (collect_export_rows_for_submission($r, $pdo) as $line) {
            echo implode(',', array_map('csv_escape', $line)) . "\r\n";
        }
    }
    exit;
}

/**
 * 计算导出：按当前筛选条件，将明细汇总为「期号 × 01–49」表（每球一行：购买笔数、下注金额）。
 * 多期号时按期号分组连续输出，每期 49 行。
 */
function handle_admin_export_calc(PDO $pdo): void
{
    require_admin_login();
    $input = array(
        'periodNo' => trim((string) ($_GET['periodNo'] ?? '')),
        'startDate' => trim((string) ($_GET['startDate'] ?? '')),
        'endDate' => trim((string) ($_GET['endDate'] ?? '')),
        'channelName' => trim((string) ($_GET['channelName'] ?? '')),
        'passId' => (int) ($_GET['passId'] ?? 0),
        'ballScope' => trim((string) ($_GET['ballScope'] ?? '')),
    );

    $params = array();
    $where = build_where($input, $params, $pdo);

    $stmt = $pdo->prepare(
        "SELECT s.period_no, i.num, SUM(i.amount) AS total_amount, COUNT(*) AS item_count
         FROM collect_submission_items i
         INNER JOIN collect_submissions s ON s.id = i.submission_id
         WHERE {$where}
         GROUP BY s.period_no, i.num"
    );
    $stmt->execute($params);
    $aggRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byPeriod = array();
    foreach ($aggRows as $row) {
        $ball = collect_normalize_lhc_ball_num((string) ($row['num'] ?? ''));
        if ($ball === null) {
            continue;
        }
        $p = (string) ($row['period_no'] ?? '');
        if ($p === '') {
            $p = '—';
        }
        if (!isset($byPeriod[$p])) {
            $byPeriod[$p] = array();
        }
        if (!isset($byPeriod[$p][$ball])) {
            $byPeriod[$p][$ball] = array('amt' => 0, 'cnt' => 0);
        }
        $byPeriod[$p][$ball]['amt'] += (int) ($row['total_amount'] ?? 0);
        $byPeriod[$p][$ball]['cnt'] += (int) ($row['item_count'] ?? 0);
    }

    if (!$byPeriod && $input['periodNo'] !== '') {
        $resolved = collect_resolve_period_to_kj_number($pdo, $input['periodNo']);
        $lbl = trim((string) ($resolved['resolved'] ?? ''));
        if ($lbl === '') {
            $lbl = trim((string) ($resolved['original'] ?? ''));
        }
        if ($lbl !== '') {
            $byPeriod[$lbl] = array();
        }
    }
    if (!$byPeriod) {
        $byPeriod['—'] = array();
    }

    ksort($byPeriod, SORT_STRING);

    $filename = 'collect_calc_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo "\xEF\xBB\xBF";

    $headers = array('期号', '特号', '购买笔数', '下注金额');
    echo implode(',', array_map('csv_escape', $headers)) . "\r\n";

    foreach ($byPeriod as $periodLabel => $balls) {
        for ($n = 1; $n <= 49; $n++) {
            $key = $n < 10 ? ('0' . $n) : (string) $n;
            $cnt = isset($balls[$key]) ? (int) $balls[$key]['cnt'] : 0;
            $amt = isset($balls[$key]) ? (int) $balls[$key]['amt'] : 0;
            $line = array($periodLabel, $key, (string) $cnt, (string) $amt);
            echo implode(',', array_map('csv_escape', $line)) . "\r\n";
        }
    }
    exit;
}

function handle_admin_submission_delete(PDO $pdo): void
{
    require_admin_login();
    // 封盘时段内：禁止删除（与提交一致）
    $input = read_json_input();
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        json_response(array('ok' => false, 'message' => '参数 id 错误'), 400);
    }

    $stmt = $pdo->prepare('SELECT id, period_no FROM collect_submissions WHERE id = :id LIMIT 1');
    $stmt->execute(array(':id' => $id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(array('ok' => false, 'message' => '记录不存在或已删除'), 404);
    }
    $periodNo = trim((string) ($row['period_no'] ?? ''));
    collect_assert_can_delete_period($pdo, $periodNo);

    $pdo->beginTransaction();
    try {
        $delItems = $pdo->prepare('DELETE FROM collect_submission_items WHERE submission_id = :id');
        $delItems->execute(array(':id' => $id));
        $delMain = $pdo->prepare('DELETE FROM collect_submissions WHERE id = :id');
        $delMain->execute(array(':id' => $id));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(array('ok' => false, 'message' => '删除失败：' . $e->getMessage()), 500);
    }

    json_response(array('ok' => true, 'message' => '提交记录已删除'));
}

function handle_logout(string $type): void
{
    if ($type === 'admin') {
        unset($_SESSION['collect_admin']);
    } else {
        unset($_SESSION['collect_user']);
    }
    json_response(array('ok' => true, 'message' => '已退出'));
}

if (!defined('COLLECT_API_SKIP_DISPATCH')) {
try {
    $action = trim((string) ($_GET['action'] ?? ''));
    $pdo = null;
    if (!in_array($action, array('admin_status', 'admin_logout', 'user_logout'), true)) {
        $pdo = get_pdo();
    }

    switch ($action) {
        case 'user_login':
            handle_user_login($pdo);
            break;
        case 'user_status':
            handle_user_status($pdo);
            break;
        case 'lottery_context':
            handle_lottery_context($pdo);
            break;
        case 'user_submit':
            handle_user_submit($pdo);
            break;
        case 'user_pingma_preview':
            handle_user_pingma_preview($pdo);
            break;
        case 'user_distributors_list':
            handle_user_distributors_list($pdo);
            break;
        case 'user_distributor_save':
            handle_user_distributor_save($pdo);
            break;
        case 'user_distributor_delete':
            handle_user_distributor_delete($pdo);
            break;
        case 'user_distributor_update':
            handle_user_distributor_update($pdo);
            break;
        case 'user_today_dashboard':
            handle_user_today_dashboard($pdo);
            break;
        case 'user_agent_submission_stats':
            handle_user_agent_submission_stats($pdo);
            break;
        case 'cron_purge_yesterday':
            handle_cron_purge_yesterday($pdo, $collectConfig);
            break;
        case 'user_my_submissions':
            handle_user_my_submissions($pdo);
            break;
        case 'user_submission_delete':
            handle_user_submission_delete($pdo);
            break;
        case 'user_change_password':
            handle_user_change_password($pdo);
            break;
        case 'user_logout':
            handle_logout('user');
            break;
        case 'admin_login':
            handle_admin_login($collectConfig);
            break;
        case 'admin_status':
            handle_admin_status();
            break;
        case 'admin_logout':
            handle_logout('admin');
            break;
        case 'admin_get_settings':
            handle_admin_get_settings($pdo);
            break;
        case 'admin_save_settings':
            handle_admin_save_settings($pdo);
            break;
        case 'admin_stats':
            handle_admin_stats($pdo);
            break;
        case 'admin_pingma_recommend':
            handle_admin_pingma_recommend($pdo);
            break;
        case 'admin_list':
            handle_admin_list($pdo);
            break;
        case 'admin_export_list':
            handle_admin_export_list($pdo);
            break;
        case 'admin_export_pingma':
            handle_admin_export_pingma($pdo);
            break;
        case 'admin_export_calc':
            handle_admin_export_calc($pdo);
            break;
        case 'admin_submission_delete':
            handle_admin_submission_delete($pdo);
            break;
        case 'admin_passkey_list':
            handle_admin_passkey_list($pdo);
            break;
        case 'admin_passkey_add':
            handle_admin_passkey_add($pdo);
            break;
        case 'admin_passkey_update':
            handle_admin_passkey_update($pdo);
            break;
        case 'admin_passkey_delete':
            handle_admin_passkey_delete($pdo);
            break;
        case 'admin_distributor_list':
            handle_admin_distributor_list($pdo);
            break;
        case 'admin_distributor_add':
            handle_admin_distributor_add($pdo);
            break;
        case 'admin_distributor_delete':
            handle_admin_distributor_delete($pdo);
            break;
        default:
            json_response(array('ok' => false, 'message' => '无效 action'), 404);
    }
} catch (Throwable $e) {
    json_response(array('ok' => false, 'message' => '系统错误：' . $e->getMessage()), 500);
}
}

