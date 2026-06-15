<?php
/**
 * 开奖时间字段可能是 Unix 秒、毫秒或 MySQL DATETIME 字符串，统一为 Unix 秒。
 *
 * 锁盘逻辑：从各彩种 `*_kjdata_time` 表读取「下一期开奖」记录（lhcTime > 当前时间，取 ORDER BY lhcTime ASC 最早一条），
 * 若 距该时间 ≤ LHC_PREDRAW_WINDOW_SEC 秒且尚未开奖，则不在前端展示上一期号码。
 */
if (!defined('LHC_PREDRAW_WINDOW_SEC')) {
	define('LHC_PREDRAW_WINDOW_SEC', 180);
}
if (!function_exists('lhc_time_to_unix')) {
	function lhc_time_to_unix($lhcTime) {
		if ($lhcTime === null || $lhcTime === '') {
			return null;
		}
		if (is_numeric($lhcTime)) {
			$ts = (int) $lhcTime;
			// 毫秒时间戳（约 > 2001-09-09 秒级上限的 10 倍）
			if ($ts > 100000000000) {
				$ts = (int) floor($ts / 1000);
			}
			return $ts;
		}
		if (is_string($lhcTime)) {
			$ts = strtotime($lhcTime);
			return $ts !== false ? $ts : null;
		}
		return null;
	}
}

/**
 * 距离开奖剩余秒数；无法解析时返回 null。
 */
if (!function_exists('lhc_seconds_until_draw')) {
	function lhc_seconds_until_draw($lhcRow, $nowtime) {
		if (!$lhcRow || !isset($lhcRow['lhcTime'])) {
			return null;
		}
		$ts = lhc_time_to_unix($lhcRow['lhcTime']);
		if ($ts === null) {
			return null;
		}
		return $ts - (int) $nowtime;
	}
}

/**
 * 距离开奖 ≤3 分钟且尚未到点：锁盘（不展示上一期号码）。
 */
if (!function_exists('lhc_predraw_is_locked')) {
	function lhc_predraw_is_locked($lhcRow, $nowtime) {
		$sec = lhc_seconds_until_draw($lhcRow, $nowtime);
		if ($sec === null) {
			return false;
		}
		return $sec > 0 && $sec <= LHC_PREDRAW_WINDOW_SEC;
	}
}

if (!function_exists('lhc_predraw_gray_style')) {
	function lhc_predraw_gray_style() {
		return 'background-color: #999999; color: #ffffff;';
	}
}

/** 下期开奖日期的「m月d日」，解析失败则用 $fallbackNow */
if (!function_exists('lhc_format_md')) {
	function lhc_format_md($lhcTimeVal, $fallbackNow) {
		$t = lhc_time_to_unix($lhcTimeVal);
		return $t !== null ? date("m月d日", $t) : date('m月d日', (int) $fallbackNow);
	}
}

/**
 * 取「下一期」开奖时间行。不在 SQL 里用 lhcTime 与 Unix 秒直接比较（DATETIME/字符串 会与数字比较失真），
 * 改为按时间升序取一批后在 PHP 里用 lhc_time_to_unix 找第一条尚未到点的记录。
 */
if (!function_exists('lhc_fetch_next_draw_row')) {
	function lhc_fetch_next_draw_row($pdo, $table, $type, $nowtime) {
		static $allowed = [
			'ay_kjdata_time',
			'ay_xakjdata_time',
			'ay_lakjdata_time',
			'ay_xgkjdata_time',
		];
		if (!in_array($table, $allowed, true)) {
			return null;
		}
		$now = (int) $nowtime;
		$sql = 'SELECT * FROM `' . $table . '` WHERE type = :typ LIMIT 2000';
		$st = $pdo->prepare($sql);
		$st->execute([':typ' => (int) $type]);
		$candidates = [];
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
					$issueVal = (string)$row[$k];
					break;
				}
			}
			$ts = lhc_time_to_unix($timeVal);
			if ($ts !== null) {
				$candidates[] = [
					'row' => ['actionNo' => $issueVal, 'lhcTime' => $timeVal],
					'ts' => $ts
				];
			}
		}
		if (!$candidates) {
			return null;
		}
		usort($candidates, function ($a, $b) {
			return $a['ts'] - $b['ts'];
		});
		foreach ($candidates as $c) {
			if ($c['ts'] > $now) {
				return $c['row'];
			}
		}
		return null;
	}
}
