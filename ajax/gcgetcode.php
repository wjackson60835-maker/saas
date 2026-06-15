<?php
date_default_timezone_set('Asia/Shanghai');
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
$nowtime = time();
$lhcTime = lhc_fetch_next_draw_row($pdo, 'ay_xgkjdata_time', 1, $nowtime);
$xalhcTime = lhc_fetch_next_draw_row($pdo, 'ay_xakjdata_time', 1, $nowtime);
$lalhcTime = lhc_fetch_next_draw_row($pdo, 'ay_lakjdata_time', 1, $nowtime);
$xgllhcTime = lhc_fetch_next_draw_row($pdo, 'ay_xgkjdata_time', 1, $nowtime);
$predrawLock = lhc_predraw_is_locked($lhcTime, $nowtime);
$lhcTimeshijian = $predrawLock ? date('m月d日', $nowtime) : lhc_format_md($lhcTime['lhcTime'] ?? null, $nowtime);
$xalhcTimeshijian = lhc_format_md($xalhcTime['lhcTime'] ?? null, $nowtime);
$lalhcTimeshijian = lhc_format_md($lalhcTime['lhcTime'] ?? null, $nowtime);
$xgllhcTimeshijian = lhc_format_md($xgllhcTime['lhcTime'] ?? null, $nowtime);

$xgqishu = !empty($lhcTime['actionNo']) ? $lhcTime['actionNo'] : '';
$sUntil = lhc_seconds_until_draw($lhcTime, $nowtime);
$seconds = $sUntil !== null ? $sUntil : 0;
$hours = floor($seconds / 3600);
$hours = sprintf("%02d", $hours);
$minutes = floor(($seconds % 3600) / 60);
$minutes = sprintf("%02d", $minutes);
$seconds = $seconds % 60;
$seconds = sprintf("%02d", $seconds);
$kaijiangshijian = $hours . ":" . $minutes . ":" . $seconds;

$gray = lhc_predraw_gray_style();

if ($predrawLock) {
	$haoma1 = $haoma2 = $haoma3 = $haoma4 = $haoma5 = $haoma6 = $haoma7 = '';
	$shengxiao1 = $shengxiao2 = $shengxiao3 = $shengxiao4 = $shengxiao5 = $shengxiao6 = $shengxiao7 = '';
	$ys1 = $ys2 = $ys3 = $ys4 = $ys5 = $ys6 = $ys7 = $gray;
	$nextNo = !empty($lhcTime['actionNo']) ? $lhcTime['actionNo'] : '';
	$kjdatas = ['number' => $nextNo, 'time' => $nowtime];
	$xgqishu = $nextNo;
	$dangqi = '';
} else {
	$kaijiangshuju = "select * from ay_xgkjdata where type = 1 and time < '$nowtime' order by number desc limit 1";
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
		$dangqi = "第" . $kjdatas['number'] . "期" . "  " . date('Y-m-d', $kjdatas['time']) . " 21:30  " . $xingqi;
		$ys1 = GetBoseName2($haoma1, 1);
		$ys2 = GetBoseName2($haoma2, 1);
		$ys3 = GetBoseName2($haoma3, 1);
		$ys4 = GetBoseName2($haoma4, 1);
		$ys5 = GetBoseName2($haoma5, 1);
		$ys6 = GetBoseName2($haoma6, 1);
		$ys7 = GetBoseName2($haoma7, 1);
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
	'footer_time' => '21点30分',
];

echo json_encode($data);
