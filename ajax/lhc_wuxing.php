<?php
/**
 * 1～49 五行（站点 2026 马年对照：金 04,05,12,13,26,27,34,35,42,43；木 08,09,16,17,24,25,38,39,46,47；
 * 水 01,14,15,22,23,30,31,44,45；火 02,03,10,11,18,19,32,33,40,41,48,49；土 06,07,20,21,28,29,36,37）。
 *
 * 波色与「红单/蓝双」个位单双、合数单双见 lhc_lookup.php。
 */
if (!function_exists('lhc_normalize_ball')) {
	function lhc_normalize_ball($number) {
		$n = (int) preg_replace('/\D/', '', trim((string) $number));
		if ($n < 1 || $n > 49) {
			return null;
		}
		return sprintf('%02d', $n);
	}
}

if (!function_exists('GetshuxingName')) {
	function GetshuxingName($number, $type) {
		static $wuxing = null;
		if ($wuxing === null) {
			$wuxing = [
				'金' => ['04', '05', '12', '13', '26', '27', '34', '35', '42', '43'],
				'木' => ['08', '09', '16', '17', '24', '25', '38', '39', '46', '47'],
				'水' => ['01', '14', '15', '22', '23', '30', '31', '44', '45'],
				'火' => ['02', '03', '10', '11', '18', '19', '32', '33', '40', '41', '48', '49'],
				'土' => ['06', '07', '20', '21', '28', '29', '36', '37'],
			];
		}
		$num = lhc_normalize_ball($number);
		if ($num === null || !$type) {
			return '';
		}
		foreach ($wuxing as $el => $list) {
			if (in_array($num, $list, true)) {
				return $el;
			}
		}
		return '';
	}
}
