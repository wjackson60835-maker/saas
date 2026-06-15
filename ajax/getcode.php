<?php

date_default_timezone_set('Asia/Shanghai'); // 设置时区为北京时间
if (!headers_sent()) {
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
}

$config = require '../config/database.php';

$host = $config["database"]['host'];

$dbname = $config["database"]['dbname'];

$user = $config["database"]['user'];

$pass = $config["database"]['passwd'];

$pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);

require_once __DIR__ . '/lhc_lookup.php';

require_once __DIR__ . '/lhc_predraw.php';

if (!function_exists('collect_has_column')) {
	function collect_has_column(PDO $pdo, string $table, string $column): bool
	{
		try {
			$st = $pdo->prepare(
				"SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE()
				   AND TABLE_NAME = :t
				   AND COLUMN_NAME = :c
				 LIMIT 1"
			);
			$st->execute([':t' => $table, ':c' => $column]);
			return (bool)$st->fetch(PDO::FETCH_ASSOC);
		} catch (Throwable $e) {
			return false;
		}
	}
}
if (!function_exists('collect_latest_schedule_ts_le_now')) {
	function collect_latest_schedule_ts_le_now(PDO $pdo, string $table, int $type, int $nowtime): ?int
	{
		try {
			$sql = 'SELECT * FROM `' . $table . '` WHERE type = :typ LIMIT 2000';
			$st = $pdo->prepare($sql);
			$st->execute([':typ' => (int)$type]);
			$best = null;
			while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
				$timeVal = null;
				foreach (['lhcTime', 'time', 'openTime', 'drawTime', 'open_time', 'draw_time', 'kj_time', 'kaijiang_time'] as $k) {
					if (isset($row[$k]) && $row[$k] !== '') {
						$timeVal = $row[$k];
						break;
					}
				}
				$ts = lhc_time_to_unix($timeVal);
				if ($ts === null || $ts > $nowtime) continue;
				if ($best === null || $ts > $best) $best = (int)$ts;
			}
			return $best;
		} catch (Throwable $e) {
			return null;
		}
	}
}
if (!function_exists('collect_latest_schedule_row_le_now')) {
	function collect_latest_schedule_row_le_now(PDO $pdo, string $table, int $type, int $nowtime): ?array
	{
		try {
			$sql = 'SELECT * FROM `' . $table . '` WHERE type = :typ LIMIT 2000';
			$st = $pdo->prepare($sql);
			$st->execute([':typ' => (int)$type]);
			$best = null;
			while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
				$timeVal = null;
				foreach (['lhcTime', 'time', 'openTime', 'drawTime', 'open_time', 'draw_time', 'kj_time', 'kaijiang_time'] as $k) {
					if (isset($row[$k]) && $row[$k] !== '') {
						$timeVal = $row[$k];
						break;
					}
				}
				$issueVal = '';
				foreach (['actionNo', 'number', 'qishu', 'issue', 'expect', 'period', 'periods'] as $k) {
					if (isset($row[$k]) && $row[$k] !== '') {
						$issueVal = trim((string)$row[$k]);
						break;
					}
				}
				$ts = lhc_time_to_unix($timeVal);
				if ($ts === null || $ts > $nowtime) continue;
				if ($best === null || $ts > $best['ts']) {
					$best = ['ts' => (int)$ts, 'issue' => $issueVal];
				}
			}
			return $best;
		} catch (Throwable $e) {
			return null;
		}
	}
}

$nowtime = time();
$currentType = 1;
$stType = $pdo->prepare("SELECT type FROM ay_kjdata WHERE time < :now ORDER BY time DESC LIMIT 1");
$stType->execute([':now' => (int)$nowtime]);
$typeRow = $stType->fetch(PDO::FETCH_ASSOC);
if ($typeRow && isset($typeRow['type']) && is_numeric($typeRow['type'])) {
	$currentType = (int)$typeRow['type'];
}

$lhcTime = lhc_fetch_next_draw_row($pdo, 'ay_kjdata_time', $currentType, $nowtime);

$xalhcTime = lhc_fetch_next_draw_row($pdo, 'ay_xakjdata_time', 1, $nowtime);

$lalhcTime = lhc_fetch_next_draw_row($pdo, 'ay_lakjdata_time', 1, $nowtime);

$xgllhcTime = lhc_fetch_next_draw_row($pdo, 'ay_xgkjdata_time', 1, $nowtime);

// 兜底：若 ay_kjdata_time 缺下一期、期号为空或时间已过，则从 ay_kjdata 里找最近未来一期开奖时间。
$lhcTs = $lhcTime && isset($lhcTime['lhcTime']) ? lhc_time_to_unix($lhcTime['lhcTime']) : null;
$needFallback = (!$lhcTime || empty($lhcTime['actionNo']) || $lhcTs === null || $lhcTs <= (int)$nowtime);
if ($needFallback) {
	$stNext = $pdo->prepare("SELECT number, time FROM ay_kjdata WHERE type = :type AND time > :now ORDER BY time ASC LIMIT 1");
	$stNext->execute([
		':type' => (int)$currentType,
		':now' => (int)$nowtime,
	]);
	$nextFromKj = $stNext->fetch(PDO::FETCH_ASSOC);
	if ($nextFromKj && isset($nextFromKj['time'])) {
		$lhcTime = [
			'actionNo' => isset($nextFromKj['number']) ? (string)$nextFromKj['number'] : '',
			'lhcTime' => (int)$nextFromKj['time'],
		];
	}
}

$predrawLock = lhc_predraw_is_locked($lhcTime, $nowtime);

$lhcTimeshijian = $predrawLock ? date('m月d日', $nowtime) : lhc_format_md($lhcTime['lhcTime'] ?? null, $nowtime);

$xalhcTimeshijian = lhc_format_md($xalhcTime['lhcTime'] ?? null, $nowtime);

$lalhcTimeshijian = lhc_format_md($lalhcTime['lhcTime'] ?? null, $nowtime);

$xgllhcTimeshijian = lhc_format_md($xgllhcTime['lhcTime'] ?? null, $nowtime);



$xgqishu = !empty($lhcTime['actionNo']) ? $lhcTime['actionNo'] : '';

$sUntil = lhc_seconds_until_draw($lhcTime, $nowtime);
$seconds = $sUntil !== null ? $sUntil : 0;
$nextDrawTs = null;
if (!empty($lhcTime['lhcTime'])) {
	$nextDrawTs = lhc_time_to_unix($lhcTime['lhcTime']);
}

$hours = floor($seconds / 3600);

$hours = sprintf("%02d", $hours);

$minutes = floor(($seconds % 3600) / 60);

$minutes = sprintf("%02d", $minutes);

$seconds = $seconds % 60;

$seconds = sprintf("%02d", $seconds);

$kaijiangshijian = $hours . ":" . $minutes . ":" . $seconds;
$footerTime = '21点30分';
if ($nextDrawTs !== null) {
	$footerTime = date('H点i分', $nextDrawTs);
}



$gray = lhc_predraw_gray_style();
$forcePostDrawWait = false;

$revealStartTs = null;



if ($predrawLock) {

	$haoma1 = $haoma2 = $haoma3 = $haoma4 = $haoma5 = $haoma6 = $haoma7 = '';

	$shengxiao1 = $shengxiao2 = $shengxiao3 = $shengxiao4 = $shengxiao5 = $shengxiao6 = $shengxiao7 = '';

	$ys1 = $ys2 = $ys3 = $ys4 = $ys5 = $ys6 = $ys7 = $gray;

	$nextNo = !empty($lhcTime['actionNo']) ? $lhcTime['actionNo'] : '';

	$kjdatas = ['number' => $nextNo, 'time' => $nowtime];

	$xgqishu = $nextNo;

	$dangqi = '';

} else {

	$kaijiangshuju = "select * from ay_kjdata where type = '$currentType' and time < '$nowtime' order by time desc, id desc limit 1";

	$kjdata = $pdo->query($kaijiangshuju);

	$kjdatas = $kjdata->fetch(PDO::FETCH_ASSOC);

	if (!$kjdatas || empty($kjdatas['data'])) {

		$haoma1 = $haoma2 = $haoma3 = $haoma4 = $haoma5 = $haoma6 = $haoma7 = '';

		$shengxiao1 = $shengxiao2 = $shengxiao3 = $shengxiao4 = $shengxiao5 = $shengxiao6 = $shengxiao7 = '';

		$ys1 = $ys2 = $ys3 = $ys4 = $ys5 = $ys6 = $ys7 = $gray;

		$kjdatas = ['number' => '', 'time' => $nowtime];

		$dangqi = '';

	} else {

		$haoma = $kjdatas['data'];

		$haoma = explode(',', $haoma);



		$haoma1 = $haoma[0];

		$haoma2 = $haoma[1];

		$haoma3 = $haoma[2];

		$haoma4 = $haoma[3];

		$haoma5 = $haoma[4];

		$haoma6 = $haoma[5];

		$haoma7 = $haoma[6];



		$date = date('Y-m-d', $kjdatas['time']);

		$xingqi = date('D', strtotime($date));

		if ($xingqi == "Mon") {

			$xingqi = "星期一";

		}

		if ($xingqi == "Tue") {

			$xingqi = "星期二";

		}

		if ($xingqi == "Wed") {

			$xingqi = "星期三";

		}

		if ($xingqi == "Thu") {

			$xingqi = "星期四";

		}

		if ($xingqi == "Fri") {

			$xingqi = "星期五";

		}

		if ($xingqi == "Sat") {

			$xingqi = "星期六";

		}



		$__sx_ts = (int) $kjdatas['time'];

		$shengxiao1 = lhc_shengxiao_for_number($haoma1, $__sx_ts) . "/" . GetshuxingName($haoma1, 1);

		$shengxiao2 = lhc_shengxiao_for_number($haoma2, $__sx_ts) . "/" . GetshuxingName($haoma2, 1);

		$shengxiao3 = lhc_shengxiao_for_number($haoma3, $__sx_ts) . "/" . GetshuxingName($haoma3, 1);

		$shengxiao4 = lhc_shengxiao_for_number($haoma4, $__sx_ts) . "/" . GetshuxingName($haoma4, 1);

		$shengxiao5 = lhc_shengxiao_for_number($haoma5, $__sx_ts) . "/" . GetshuxingName($haoma5, 1);

		$shengxiao6 = lhc_shengxiao_for_number($haoma6, $__sx_ts) . "/" . GetshuxingName($haoma6, 1);

		$shengxiao7 = lhc_shengxiao_for_number($haoma7, $__sx_ts) . "/" . GetshuxingName($haoma7, 1);

		$dangqi = "第" . $kjdatas['number'] . "期" . "  " . date('Y-m-d', $kjdatas['time']) . " 21:30   " . $xingqi;

		$ys1 = GetBoseName2($haoma1, 1);

		$ys2 = GetBoseName2($haoma2, 1);

		$ys3 = GetBoseName2($haoma3, 1);

		$ys4 = GetBoseName2($haoma4, 1);

		$ys5 = GetBoseName2($haoma5, 1);

		$ys6 = GetBoseName2($haoma6, 1);

		$ys7 = GetBoseName2($haoma7, 1);

	}

}
$currentIssueNo = isset($kjdatas['number']) ? trim((string)$kjdatas['number']) : '';
$revealDone = 0;
$revealCount = 1;
$revealStartTs = null;

if (collect_has_column($pdo, 'ay_kjdata', 'reveal_state') === false) {
	try { $pdo->exec("ALTER TABLE ay_kjdata ADD COLUMN reveal_state TINYINT(1) UNSIGNED NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
}
if (collect_has_column($pdo, 'ay_kjdata', 'reveal_count') === false) {
	try { $pdo->exec("ALTER TABLE ay_kjdata ADD COLUMN reveal_count TINYINT(2) UNSIGNED NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
}
if (collect_has_column($pdo, 'ay_kjdata', 'reveal_last_step_ts') === false) {
	try { $pdo->exec("ALTER TABLE ay_kjdata ADD COLUMN reveal_last_step_ts INT(10) UNSIGNED NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
}
if (collect_has_column($pdo, 'ay_kjdata', 'reveal_issue') === false) {
	try { $pdo->exec("ALTER TABLE ay_kjdata ADD COLUMN reveal_issue VARCHAR(32) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
}

if (!$forcePostDrawWait && $currentIssueNo !== '' && !$predrawLock && !empty($haoma1) && !empty($haoma7)) {
	$rowId = (int)($kjdatas['id'] ?? 0);
	$actualIssue = trim((string)($kjdatas['number'] ?? ''));
	$actualTimeRaw = $kjdatas['time'] ?? null;
	$drawTimeTs = lhc_time_to_unix($kjdatas['time'] ?? null);
	$drawTime = $drawTimeTs !== null ? (int)$drawTimeTs : 0;
	$updateRevealState = function (string $sqlById, string $sqlByIssue, array $params) use ($pdo, $rowId, $actualIssue, $actualTimeRaw, $currentType) {
		try {
			if ($rowId > 0) {
				$p = $params;
				$p[':id'] = $rowId;
				$pdo->prepare($sqlById)->execute($p);
			} else if ($actualIssue !== '') {
				$p = $params;
				$p[':num'] = $actualIssue;
				$p[':typ'] = (int)$currentType;
				$p[':tm'] = $actualTimeRaw;
				$pdo->prepare($sqlByIssue)->execute($p);
			}
		} catch (Throwable $e) {
		}
	};
	$openedSched = collect_latest_schedule_row_le_now($pdo, 'ay_kjdata_time', (int)$currentType, (int)$nowtime);
	$expectedOpenedIssue = $openedSched && isset($openedSched['issue']) ? trim((string)$openedSched['issue']) : '';
	$expectedOpenedTs = $openedSched && isset($openedSched['ts']) ? (int)$openedSched['ts'] : 0;

	// 切期硬门：刚过开奖点时若仍是旧期，强制灰块等待，绝不返回旧期全量。
	if ($expectedOpenedIssue !== '' && $actualIssue !== '' && $expectedOpenedIssue !== $actualIssue && $expectedOpenedTs > 0) {
		$afterOpenedTs = (int)$nowtime - $expectedOpenedTs;
		if ($afterOpenedTs >= 0 && $afterOpenedTs < 120) {
			$revealDone = 0;
			$revealCount = 1;
			$haoma1 = $haoma2 = $haoma3 = $haoma4 = $haoma5 = $haoma6 = $haoma7 = '';
			$shengxiao1 = $shengxiao2 = $shengxiao3 = $shengxiao4 = $shengxiao5 = $shengxiao6 = $shengxiao7 = '';
			$ys1 = $ys2 = $ys3 = $ys4 = $ys5 = $ys6 = $ys7 = $gray;
		}
	} else if (collect_has_column($pdo, 'ay_kjdata', 'reveal_state') && collect_has_column($pdo, 'ay_kjdata', 'reveal_count') && collect_has_column($pdo, 'ay_kjdata', 'reveal_last_step_ts') && collect_has_column($pdo, 'ay_kjdata', 'reveal_issue')) {
		$state = isset($kjdatas['reveal_state']) && is_numeric($kjdatas['reveal_state']) ? (int)$kjdatas['reveal_state'] : 0; // 0未开始 1进行中 2完成
		$count = isset($kjdatas['reveal_count']) && is_numeric($kjdatas['reveal_count']) ? (int)$kjdatas['reveal_count'] : 0;
		$lastTs = isset($kjdatas['reveal_last_step_ts']) && is_numeric($kjdatas['reveal_last_step_ts']) ? (int)$kjdatas['reveal_last_step_ts'] : 0;
		$stateIssue = trim((string)($kjdatas['reveal_issue'] ?? ''));

		// 期号变更重置状态，避免串期导致首屏全量。
		if ($stateIssue !== $actualIssue) {
			$state = 0; $count = 0; $lastTs = 0; $stateIssue = $actualIssue;
			$updateRevealState(
				"UPDATE ay_kjdata SET reveal_state = 0, reveal_count = 0, reveal_last_step_ts = 0, reveal_issue = :iss WHERE id = :id LIMIT 1",
				"UPDATE ay_kjdata SET reveal_state = 0, reveal_count = 0, reveal_last_step_ts = 0, reveal_issue = :iss WHERE number = :num AND type = :typ AND time = :tm LIMIT 1",
				[':iss' => $actualIssue]
			);
		}

		if ($drawTime > 0 && (int)$nowtime < $drawTime) {
			// 未到开奖，保持未开始。
			$state = 0; $count = 0; $lastTs = 0;
			$updateRevealState(
				"UPDATE ay_kjdata SET reveal_state = 0, reveal_count = 0, reveal_last_step_ts = 0, reveal_issue = :iss WHERE id = :id LIMIT 1",
				"UPDATE ay_kjdata SET reveal_state = 0, reveal_count = 0, reveal_last_step_ts = 0, reveal_issue = :iss WHERE number = :num AND type = :typ AND time = :tm LIMIT 1",
				[':iss' => $actualIssue]
			);
			$revealDone = 0; $revealCount = 1;
		} else {
			// 开奖后状态机：每10秒+1，直到7个立即完成。
			if ($state === 2) {
				$revealDone = 1; $revealCount = 7;
			} else {
				if ($state === 0) {
					$state = 1; $count = 1; $lastTs = (int)$nowtime;
				} else {
					if ($count < 1) $count = 1;
					if ($lastTs <= 0 || $lastTs > (int)$nowtime) $lastTs = (int)$nowtime;
					$steps = (int)floor(((int)$nowtime - $lastTs) / 10);
					if ($steps > 0) {
						$count += $steps;
						if ($count > 7) $count = 7;
						$lastTs += $steps * 10;
					}
				}
				if ($count >= 7) {
					$state = 2; $count = 7;
					$revealDone = 1; $revealCount = 7;
				} else {
					$state = 1;
					$revealDone = 0; $revealCount = $count;
				}
				$updateRevealState(
					"UPDATE ay_kjdata SET reveal_state = :st, reveal_count = :cnt, reveal_last_step_ts = :lts, reveal_issue = :iss WHERE id = :id LIMIT 1",
					"UPDATE ay_kjdata SET reveal_state = :st, reveal_count = :cnt, reveal_last_step_ts = :lts, reveal_issue = :iss WHERE number = :num AND type = :typ AND time = :tm LIMIT 1",
					[
						':st' => (int)$state,
						':cnt' => (int)$count,
						':lts' => (int)$lastTs,
						':iss' => $actualIssue
					]
				);
			}
		}
	}
}

// 稳定兜底：按开奖时间直接计算当前应显示个数，不依赖状态写库是否成功。
if (!$predrawLock && !empty($haoma1) && !empty($haoma7)) {
	$drawTsDet = isset($kjdatas['time']) ? lhc_time_to_unix($kjdatas['time']) : null;
	if ($drawTsDet !== null) {
		$elapsedDet = (int)$nowtime - (int)$drawTsDet;
		if ($elapsedDet < 0) $elapsedDet = 0;
		$countDet = (int)floor($elapsedDet / 10) + 1;
		if ($countDet < 1) $countDet = 1;
		if ($countDet > 7) $countDet = 7;
		$revealCount = $countDet;
		$revealDone = $countDet >= 7 ? 1 : 0;
		$revealStartTs = null;
		// 完成后顺带落库标记，便于后续直出全量。
		if ($revealDone === 1 && isset($kjdatas['id']) && is_numeric($kjdatas['id']) && collect_has_column($pdo, 'ay_kjdata', 'reveal_state')) {
			try {
				$pdo->prepare("UPDATE ay_kjdata SET reveal_state = 2, reveal_count = 7 WHERE id = :id LIMIT 1")
					->execute([':id' => (int)$kjdatas['id']]);
			} catch (Throwable $e) {
			}
		}
	}
}

// 统一按 revealCount 仅放出前 N 个号码（后端兜底），防止分支漏判导致首次全量显示。
if (!$predrawLock && !empty($haoma1) && !empty($haoma7) && $revealDone !== 1) {
	$grayNum = '--';
	$graySx = '--';
	for ($i = $revealCount + 1; $i <= 7; $i++) {
		${'haoma' . $i} = $grayNum;
		${'shengxiao' . $i} = $graySx;
		${'ys' . $i} = $gray;
	}
}

$data = [

	'info' => "创亿网络@cykeji",

	'qishu' => $xgqishu,

	'nianyueri' => $lhcTimeshijian,

	'xinao' => $xalhcTimeshijian,

	'laoao' => $lalhcTimeshijian,

	'xianggang' => $xgllhcTimeshijian,

	'daojishi' => $kaijiangshijian,

	'number' => $kjdatas['number'],

	'haoma1' => $haoma1,

	'haoma2' => $haoma2,

	'haoma3' => $haoma3,

	'haoma4' => $haoma4,

	'haoma5' => $haoma5,

	'haoma6' => $haoma6,

	'haoma7' => $haoma7,

	'dangqi' => $dangqi,

	'shengxiao1' => $shengxiao1,

	'shengxiao2' => $shengxiao2,

	'shengxiao3' => $shengxiao3,

	'shengxiao4' => $shengxiao4,

	'shengxiao5' => $shengxiao5,

	'shengxiao6' => $shengxiao6,

	'shengxiao7' => $shengxiao7,

	'ys1' => $ys1,

	'ys2' => $ys2,

	'ys3' => $ys3,

	'ys4' => $ys4,

	'ys5' => $ys5,

	'ys6' => $ys6,

	'ys7' => $ys7,

	'pre_draw_lock' => $predrawLock ? 1 : 0,

	'seconds_to_next_draw' => $sUntil !== null ? (int) $sUntil : null,

	'footer_time' => $footerTime,

	'next_draw_ts' => $nextDrawTs,

	'draw_ts' => isset($kjdatas['time']) ? lhc_time_to_unix($kjdatas['time']) : null,

	'reveal_start_ts' => $revealStartTs,

	'reveal_done' => $revealDone,

	'reveal_count' => $revealCount,

];



echo json_encode($data);

