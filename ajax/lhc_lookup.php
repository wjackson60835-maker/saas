<?php
/**
 * 红/蓝/绿波内联样式（五行在 lhc_wuxing.php）。
 *
 * 站点 2026 马年标准（生肖/五行/波色/合数单双与运营表一致）：
 * - 十二生肖号码：马01,13,25,37,49 … 蛇02,14,26,38（见 lhc_shengxiao_spec_map_2026）。
 * - 波色（红/蓝/绿）：与 GetBoseName2 / lhc_bose_ball_groups 内列表一致。
 * - 五行：见 lhc_wuxing.php GetshuxingName。
 *
 * 「波色+单双」（红单/蓝双等）：指号码个位奇偶，且在对应波色内筛选：
 * 红单 01 07 13 19 23 29 35 45 | 红双 02 08 12 18 24 30 34 40 46 …（lhc_numbers_for_wave_odd_even）
 *
 * 「合单/合双」：指十位+个位数字之和的奇偶（与马年参考表一致），见 lhc_numbers_for_hesu_odd_even；
 * 写法示例：合单各、合双各（与「红单各」含义不同）。
 *
 * 资料备忘：红生肖马兔鼠鸡；绿生肖羊龙牛狗；蓝生肖蛇虎猪猴。单笔生肖：鼠龙马蛇鸡猪；双笔：虎猴狗兔羊牛。
 */
require_once __DIR__ . '/lhc_wuxing.php';

if (!function_exists('GetBoseName2')) {
	function GetBoseName2($number, $type) {
		static $bose = null;
		if ($bose === null) {
			$bose = [
				'background-color: rgb(255, 0, 0); color: rgb(255, 255, 255);' => ['01', '02', '07', '08', '12', '13', '18', '19', '23', '24', '29', '30', '34', '35', '40', '45', '46'],
				'background-color: rgb(0, 0, 255); color: rgb(255, 255, 255);' => ['03', '04', '09', '10', '14', '15', '20', '25', '26', '31', '36', '37', '41', '42', '47', '48'],
				'background-color: rgb(0, 153, 0); color: rgb(255, 255, 255);' => ['05', '06', '11', '16', '17', '21', '22', '27', '28', '32', '33', '38', '39', '43', '44', '49'],
			];
		}
		$num = lhc_normalize_ball($number);
		if ($num === null || !$type) {
			return '';
		}
		foreach ($bose as $style => $list) {
			if (in_array($num, $list, true)) {
				return $style;
			}
		}
		return '';
	}
}

if (!function_exists('lhc_shengxiao_spec_map_2026')) {
	/**
	 * 2026 马年十二生肖号码对照（运营标准；括号如马[马鼠]仅为备注不参与计算）。
	 *
	 * @return array<string,string> 生肖 => "01,13,..."
	 */
	function lhc_shengxiao_spec_map_2026() {
		static $spec = null;
		if ($spec === null) {
			$spec = array(
				'马' => '01,13,25,37,49',
				'羊' => '12,24,36,48',
				'猴' => '11,23,35,47',
				'鸡' => '10,22,34,46',
				'狗' => '09,21,33,45',
				'猪' => '08,20,32,44',
				'鼠' => '07,19,31,43',
				'牛' => '06,18,30,42',
				'虎' => '05,17,29,41',
				'兔' => '04,16,28,40',
				'龙' => '03,15,27,39',
				'蛇' => '02,14,26,38',
			);
		}
		return $spec;
	}
}

if (!function_exists('lhc_shengxiao_for_number')) {
	/**
	 * 按开奖日期的公历年份取 01–49 对应生肖（站点历年表）。
	 * 2026 年起使用 lhc_shengxiao_spec_map_2026；更早记录沿用原内置表。
	 */
	function lhc_shengxiao_for_number($number, $drawUnixTs = null) {
		$num = lhc_normalize_ball($number);
		if ($num === null) {
			return '';
		}
		$ts = $drawUnixTs !== null ? (int) $drawUnixTs : time();
		$year = (int) date('Y', $ts);
		if ($year >= 2026) {
			static $map2026 = null;
			if ($map2026 === null) {
				$map2026 = array();
				foreach (lhc_shengxiao_spec_map_2026() as $sx => $csv) {
					foreach (explode(',', $csv) as $x) {
						$x = trim($x);
						if ($x !== '') {
							$map2026[$x] = $sx;
						}
					}
				}
			}
			return $map2026[$num] ?? '';
		}
		static $legacy = null;
		if ($legacy === null) {
			$legacy = [];
			$spec = [
				'虎' => '03,15,27,39',
				'兔' => '02,14,26,38',
				'龙' => '01,13,25,37,49',
				'蛇' => '12,24,36,48',
				'马' => '11,23,35,47',
				'羊' => '10,22,34,46',
				'猴' => '09,21,33,45',
				'鸡' => '08,20,32,44',
				'狗' => '07,19,31,43',
				'猪' => '06,18,30,42',
				'鼠' => '05,17,29,41',
				'牛' => '04,16,28,40',
			];
			foreach ($spec as $sx => $csv) {
				foreach (explode(',', $csv) as $x) {
					$legacy[$x] = $sx;
				}
			}
		}
		return $legacy[$num] ?? '';
	}
}

if (!function_exists('lhc_shengxiao_year_spec')) {
	/**
	 * 与 lhc_shengxiao_for_number 一致的年度生肖→球号对照（CSV 为 01–49）。
	 * @return array<string,string> 单字生肖 => "01,13,25,..."
	 */
	function lhc_shengxiao_year_spec($drawUnixTs = null) {
		$ts = $drawUnixTs !== null ? (int) $drawUnixTs : time();
		$year = (int) date('Y', $ts);
		if ($year >= 2026) {
			return lhc_shengxiao_spec_map_2026();
		}
		return array(
			'虎' => '03,15,27,39',
			'兔' => '02,14,26,38',
			'龙' => '01,13,25,37,49',
			'蛇' => '12,24,36,48',
			'马' => '11,23,35,47',
			'羊' => '10,22,34,46',
			'猴' => '09,21,33,45',
			'鸡' => '08,20,32,44',
			'狗' => '07,19,31,43',
			'猪' => '06,18,30,42',
			'鼠' => '05,17,29,41',
			'牛' => '04,16,28,40',
		);
	}
}

if (!function_exists('lhc_normalize_shengxiao_name')) {
	/**
	 * 属牛、肖牛、牛 → 统一为单字生肖（鼠牛虎兔龙蛇马羊猴鸡狗猪）。
	 */
	function lhc_normalize_shengxiao_name($name) {
		$name = trim((string) $name);
		if ($name === '') {
			return '';
		}
		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			$len = mb_strlen($name, 'UTF-8');
			if ($len >= 2 && mb_substr($name, 0, 1, 'UTF-8') === '属') {
				$name = mb_substr($name, 1, null, 'UTF-8');
				$len = mb_strlen($name, 'UTF-8');
			}
			if ($len >= 2 && mb_substr($name, 0, 1, 'UTF-8') === '肖') {
				$name = mb_substr($name, 1, null, 'UTF-8');
			}
		}
		return trim($name);
	}
}

if (!function_exists('lhc_numbers_for_shengxiao')) {
	/**
	 * 生肖 → 对应球号列表（与 lhc_shengxiao_for_number 年度表一致）。
	 *
	 * @param string $shengxiao 如 鼠、属牛、马
	 * @param int|null $drawUnixTs 开奖日时间戳，用于选哪一年的对照表；默认当前时间
	 * @return string[] 两位字符串 01–49，未识别返回空数组
	 */
	function lhc_numbers_for_shengxiao($shengxiao, $drawUnixTs = null) {
		$sx = lhc_normalize_shengxiao_name($shengxiao);
		if ($sx === '') {
			return array();
		}
		$spec = lhc_shengxiao_year_spec($drawUnixTs);
		if (! isset($spec[$sx])) {
			return array();
		}
		$out = array();
		foreach (explode(',', $spec[$sx]) as $x) {
			$x = trim($x);
			if ($x === '') {
				continue;
			}
			if (strlen($x) === 1) {
				$x = '0' . $x;
			}
			$out[] = $x;
		}
		return $out;
	}
}

if (!function_exists('lhc_int_numbers_for_shengxiao')) {
	/**
	 * 同 lhc_numbers_for_shengxiao，返回整数数组。
	 * @return int[]
	 */
	function lhc_int_numbers_for_shengxiao($shengxiao, $drawUnixTs = null) {
		$balls = lhc_numbers_for_shengxiao($shengxiao, $drawUnixTs);
		$out = array();
		foreach ($balls as $b) {
			$out[] = (int) $b;
		}
		return $out;
	}
}

if (!function_exists('lhc_bose_ball_groups')) {
	/**
	 * 01–49 波色分组（与 GetBoseName2 内联表一致，用于波色+单双）。
	 *
	 * @return array<string, string[]> 键：红、蓝、绿；值：两位球号
	 */
	function lhc_bose_ball_groups() {
		static $g = null;
		if ($g === null) {
			$g = array(
				'红' => array('01', '02', '07', '08', '12', '13', '18', '19', '23', '24', '29', '30', '34', '35', '40', '45', '46'),
				'蓝' => array('03', '04', '09', '10', '14', '15', '20', '25', '26', '31', '36', '37', '41', '42', '47', '48'),
				'绿' => array('05', '06', '11', '16', '17', '21', '22', '27', '28', '32', '33', '38', '39', '43', '44', '49'),
			);
		}
		return $g;
	}
}

if (!function_exists('lhc_bose_name_for_number')) {
	/**
	 * 球号对应波色名（红/蓝/绿）。
	 *
	 * @param string|int $number
	 * @return string
	 */
	function lhc_bose_name_for_number($number) {
		$num = lhc_normalize_ball($number);
		if ($num === null) {
			return '';
		}
		foreach (lhc_bose_ball_groups() as $wave => $list) {
			if (in_array($num, $list, true)) {
				return $wave;
			}
		}
		return '';
	}
}

if (!function_exists('lhc_numbers_for_wave_odd_even')) {
	/**
	 * 波色 + 单双 → 球号列表（与文件头注释：红单/红双/蓝单/蓝双/绿单/绿双 一致）。
	 *
	 * @param string $wave 红|蓝|绿
	 * @param string $parity 单|双
	 * @return string[]
	 */
	function lhc_numbers_for_wave_odd_even($wave, $parity) {
		$g = lhc_bose_ball_groups();
		if (!isset($g[$wave])) {
			return array();
		}
		$wantOdd = ($parity === '单');
		$out = array();
		foreach ($g[$wave] as $b) {
			$n = (int) $b;
			if ($wantOdd === ($n % 2 === 1)) {
				$out[] = $b;
			}
		}
		sort($out, SORT_STRING);
		return $out;
	}
}

if (!function_exists('lhc_numbers_small_01_24')) {
	/** @return string[] */
	function lhc_numbers_small_01_24() {
		$out = array();
		for ($i = 1; $i <= 24; $i++) {
			$out[] = $i < 10 ? ('0' . $i) : (string) $i;
		}
		return $out;
	}
}

if (!function_exists('lhc_numbers_big_25_49')) {
	/** @return string[] */
	function lhc_numbers_big_25_49() {
		$out = array();
		for ($i = 25; $i <= 49; $i++) {
			$out[] = (string) $i;
		}
		return $out;
	}
}

if (!function_exists('lhc_numbers_for_hesu_odd_even')) {
	/**
	 * 合数单双：十位数字 + 个位数字之和为奇 → 合单，为偶 → 合双（与站点 2026 马年参考表一致）。
	 *
	 * @param string $parity 合单|合双
	 * @return string[] 两位球号 01–49
	 */
	function lhc_numbers_for_hesu_odd_even($parity) {
		$parity = trim((string) $parity);
		if ($parity === '合单') {
			$wantOdd = true;
		} elseif ($parity === '合双') {
			$wantOdd = false;
		} else {
			return array();
		}
		$out = array();
		for ($n = 1; $n <= 49; $n++) {
			$sum = (int) (floor($n / 10) + ($n % 10));
			if (($sum % 2 === 1) === $wantOdd) {
				$out[] = $n < 10 ? ('0' . $n) : (string) $n;
			}
		}
		sort($out, SORT_STRING);
		return $out;
	}
}

if (!function_exists('lhc_numbers_for_collect_keyword')) {
	/**
	 * 收集/投注行关键词 → 展开为 01–49 球号（用于「蓝双各100」「合单各50」「小数(01~24)各50」等）。
	 * 不识别的标签返回 null。
	 *
	 * @return string[]|null
	 */
	function lhc_numbers_for_collect_keyword($raw) {
		$raw = trim((string) $raw);
		if ($raw === '') {
			return null;
		}
		$norm = str_replace(array('（', '）', '～'), array('(', ')', '~'), $raw);
		$norm = preg_replace('/\s+/u', '', $norm);

		if (preg_match('/^(红|蓝|绿)(单|双)$/u', $norm, $m)) {
			$list = lhc_numbers_for_wave_odd_even($m[1], $m[2]);
			return $list !== array() ? $list : null;
		}

		if ($norm === '合单' || $norm === '合双') {
			$list = lhc_numbers_for_hesu_odd_even($norm);
			return $list !== array() ? $list : null;
		}

		if (mb_strpos($norm, '小数', 0, 'UTF-8') === 0) {
			$rest = mb_substr($norm, 2, null, 'UTF-8');
			if ($rest === '' || preg_match('/^\(\s*01\s*[~\-]\s*24\s*\)$/u', $rest)) {
				return lhc_numbers_small_01_24();
			}
			return null;
		}
		if (mb_strpos($norm, '大数', 0, 'UTF-8') === 0) {
			$rest = mb_substr($norm, 2, null, 'UTF-8');
			if ($rest === '' || preg_match('/^\(\s*25\s*[~\-]\s*49\s*\)$/u', $rest)) {
				return lhc_numbers_big_25_49();
			}
			return null;
		}

		return null;
	}
}
