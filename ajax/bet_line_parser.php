<?php
/**
 * 六合投注行解析：生肖+号 / 生肖+肖 / 纯数字 × 分隔符 × 单位（行尾可写「元」「园」（同元）、「米」或省略，如：…各肖 100 米、…各号100元、…各数100米（各数同各号））。
 * 生肖+肖：行尾金额为「每个生肖」的总注金，在该生肖对应球号上整数均分。
 * 生肖+号：行尾金额为「每个球号」各下多少。具体摊分由 collect_items_from_bet_line_result() 完成。
 * 依赖 lhc_lookup.php 的生肖→球号对照。
 */
require_once __DIR__ . '/lhc_lookup.php';

if (!function_exists('bet_line_separators')) {
	/** @return string[] */
	function bet_line_separators() {
		// 「，」与半角「,」均支持（收集端 normalize 会把中文逗号换成英文逗号）
		return array('，', '、', ',', '/', '～', '。', '.', '-');
	}
}

if (!function_exists('bet_line_zodiac_chars')) {
	/** @return string[] */
	function bet_line_zodiac_chars() {
		static $z = null;
		if ($z === null) {
			$z = array_keys(lhc_shengxiao_year_spec(time()));
		}
		return $z;
	}
}

if (!function_exists('bet_line_is_single_zodiac')) {
	function bet_line_is_single_zodiac($s) {
		$s = trim((string) $s);
		if ($s === '' || !function_exists('mb_strlen')) {
			return false;
		}
		if (mb_strlen($s, 'UTF-8') !== 1) {
			return false;
		}
		return in_array($s, bet_line_zodiac_chars(), true);
	}
}

if (!function_exists('bet_money_unit_suffix_pattern')) {
	/** 行尾金额单位：元/园/圆 等价，米 同无单位 */
	function bet_money_unit_suffix_pattern(): string
	{
		return '元|园|圆|米';
	}
}

if (!function_exists('bet_line_parse_amount_tail')) {
	/**
	 * 从行尾解析金额与单位。
	 * @return array{0:string,1:string,2:string}|null [left, amount, unit_code] unit_code: yuan|mi|none
	 */
	function bet_line_parse_amount_tail($line) {
		$line = trim((string) $line);
		if ($line === '') {
			return null;
		}
		// NBSP、全角空格、零宽字符等统一成普通空格，避免「100 米」无法匹配
		$line = preg_replace('/[\x{00A0}\x{3000}\x{FEFF}\x{200B}-\x{200D}]/u', ' ', $line);
		$line = trim(preg_replace('/\s+/u', ' ', $line));
		if ($line === '') {
			return null;
		}
		// 行尾：数字 + 可选单位「元」「园」「圆」（同元）、「米」（与无单位），单位前后可有空白
		$unitPat = bet_money_unit_suffix_pattern();
		if (!preg_match('/\s*(\d+(?:\.\d+)?)\s*(' . $unitPat . ')?\s*$/u', $line, $m, PREG_OFFSET_CAPTURE)) {
			return null;
		}
		$amount = $m[1][0];
		$u = isset($m[2][0]) ? $m[2][0] : '';
		$unit = ($u === '元' || $u === '园' || $u === '圆') ? 'yuan' : ($u === '米' ? 'mi' : 'none');
		$startByte = (int) $m[0][1];
		$left = $startByte > 0 ? rtrim(substr($line, 0, $startByte)) : '';
		return array($left, $amount, $unit);
	}
}

if (!function_exists('bet_line_split_by_separator')) {
	/**
	 * @param callable(string):bool $partOk
	 * @return array{separator:string,parts:string[]}|null
	 */
	function bet_line_split_by_separator($itemsPart, array $seps, $partOk) {
		$itemsPart = trim((string) $itemsPart);
		if ($itemsPart === '') {
			return null;
		}
		foreach ($seps as $sep) {
			if ($sep !== '' && mb_strpos($itemsPart, $sep, 0, 'UTF-8') === false) {
				continue;
			}
			if ($sep === '') {
				continue;
			}
			$parts = explode($sep, $itemsPart);
			if (count($parts) < 2) {
				continue;
			}
			$trimmed = array();
			$ok = true;
			foreach ($parts as $p) {
				$p = trim($p);
				$trimmed[] = $p;
				if (!$partOk($p)) {
					$ok = false;
					break;
				}
			}
			if ($ok) {
				return array('separator' => $sep, 'parts' => $trimmed);
			}
		}
		if ($partOk($itemsPart)) {
			return array('separator' => '', 'parts' => array(trim($itemsPart)));
		}
		return null;
	}
}

if (!function_exists('bet_line_split_zodiac_chars_concat')) {
	/**
	 * 连写生肖：鼠马狗虎、龙鸡狗（无逗号/顿号等分隔符），按单字拆成多项。
	 * 若出现非十二生肖单字则返回 null。
	 *
	 * @return string[]|null
	 */
	function bet_line_split_zodiac_chars_concat($itemsPart) {
		$itemsPart = trim((string) $itemsPart);
		if ($itemsPart === '' || !function_exists('mb_strlen')) {
			return null;
		}
		$zset = array_flip(bet_line_zodiac_chars());
		$len = mb_strlen($itemsPart, 'UTF-8');
		$parts = array();
		for ($i = 0; $i < $len; $i++) {
			$ch = mb_substr($itemsPart, $i, 1, 'UTF-8');
			if (!isset($zset[$ch])) {
				return null;
			}
			$parts[] = $ch;
		}
		return $parts;
	}
}

if (!function_exists('bet_line_resolve_zodiac_parts_hao')) {
	/**
	 * 生肖+号：先按分隔符，再尝试连写单字。
	 *
	 * @return array{separator:string,parts:string[]}|null
	 */
	function bet_line_resolve_zodiac_parts_hao($itemsPart, array $seps) {
		$split = bet_line_split_by_separator($itemsPart, $seps, 'bet_line_is_single_zodiac');
		if ($split !== null) {
			return $split;
		}
		$concat = bet_line_split_zodiac_chars_concat($itemsPart);
		if ($concat !== null && count($concat) >= 1) {
			return array('separator' => '', 'parts' => $concat);
		}
		return null;
	}
}

if (!function_exists('bet_line_resolve_zodiac_parts_xiao')) {
	/**
	 * 生肖+肖：先按分隔符（属牛、马），再尝试连写单字。
	 *
	 * @return array{separator:string,parts:string[]}|null
	 */
	function bet_line_resolve_zodiac_parts_xiao($itemsPart, array $seps) {
		$zOk = function ($p) {
			$sx = lhc_normalize_shengxiao_name($p);
			return $sx !== '' && in_array($sx, bet_line_zodiac_chars(), true);
		};
		$split = bet_line_split_by_separator($itemsPart, $seps, $zOk);
		if ($split !== null) {
			return $split;
		}
		$concat = bet_line_split_zodiac_chars_concat($itemsPart);
		if ($concat !== null && count($concat) >= 1) {
			return array('separator' => '', 'parts' => $concat);
		}
		return null;
	}
}

if (!function_exists('bet_line_normalize_ball')) {
	function bet_line_normalize_ball($s) {
		$s = trim((string) $s);
		if ($s === '' || !ctype_digit($s)) {
			return null;
		}
		$n = (int) $s;
		if ($n < 1 || $n > 49) {
			return null;
		}
		return $n < 10 ? '0' . $n : (string) $n;
	}
}

if (!function_exists('bet_line_parse')) {
	/**
	 * 解析单行投注描述。
	 *
	 * 生肖+号（各号/号各/各数/数各）：金额为「每个球号」各下多少（虎马各数100米 ⇒ 与虎马各号100米相同）。
	 * 生肖+肖（各肖/肖各）：金额为「每个生肖」的总注金，在该生肖内按球号个数整数均分（虎猴龙各肖100 ⇒ 三肖各 100，本行合计 300）。
	 *
	 * @param int|null $drawUnixTs 传给生肖→球号（年度表）
	 * @return array ok, error?, type?, separator?, items?, item_numbers?, balls_flat?, amount?, unit?, raw
	 */
	function bet_line_parse($line, $drawUnixTs = null) {
		$raw = (string) $line;
		$line = trim($raw);
		$seps = bet_line_separators();
		$tail = bet_line_parse_amount_tail($line);
		if ($tail === null) {
			return array(
				'ok' => false,
				'error' => 'missing_amount',
				'message' => '行尾需为「金额」或「金额+元/园/米」，例如：…各号100、…各数100米、…各肖 100 米、…各 100元',
				'raw' => $raw,
			);
		}
		list($left, $amount, $unit) = $tail;
		$haoRes = array(
			'/^(.+)\s*各号\s*$/u',
			'/^(.+)\s*号\s*各\s*$/u',
			'/^(.+)\s*各数\s*$/u',
			'/^(.+)\s*数\s*各\s*$/u',
		);
		foreach ($haoRes as $haoRe) {
			if (!preg_match($haoRe, $left, $mHao)) {
				continue;
			}
			$itemsPart = trim($mHao[1]);
			$split = bet_line_resolve_zodiac_parts_hao($itemsPart, $seps);
			if ($split === null) {
				return array(
					'ok' => false,
					'error' => 'invalid_zodiac_hao_items',
					'message' => '生肖+号：每项为单字生肖；可用「各号」「号各」「各数」「数各」，如「虎马各数100米」「龙鸡狗各号100」「鼠号各 100」',
					'raw' => $raw,
				);
			}
			$items = $split['parts'];
			$itemNumbers = array();
			$flat = array();
			foreach ($items as $sx) {
				$nums = lhc_numbers_for_shengxiao($sx, $drawUnixTs);
				$itemNumbers[] = $nums;
				foreach ($nums as $b) {
					$flat[$b] = true;
				}
			}
			ksort($flat, SORT_STRING);
			return array(
				'ok' => true,
				'type' => 'zodiac_hao',
				'separator' => $split['separator'],
				'items' => $items,
				'item_numbers' => $itemNumbers,
				'balls_flat' => array_keys($flat),
				'amount' => $amount,
				'unit' => $unit,
				'raw' => $raw,
			);
		}
		$xiaoRes = array(
			'/^(.+)\s*各肖\s*$/u',
			'/^(.+)\s*肖\s*各\s*$/u',
		);
		foreach ($xiaoRes as $xiaoRe) {
			if (!preg_match($xiaoRe, $left, $mXiao)) {
				continue;
			}
			$itemsPart = trim($mXiao[1]);
			$split = bet_line_resolve_zodiac_parts_xiao($itemsPart, $seps);
			if ($split === null) {
				return array(
					'ok' => false,
					'error' => 'invalid_zodiac_xiao_items',
					'message' => '生肖+肖：可用「各肖」或「肖各」；金额为该生肖总注金，按该肖球号个数均分（如虎肖各100 ⇒ 四球共分 100）',
					'raw' => $raw,
				);
			}
			$items = array();
			foreach ($split['parts'] as $p) {
				$items[] = lhc_normalize_shengxiao_name($p);
			}
			$itemNumbers = array();
			$flat = array();
			foreach ($items as $sx) {
				$nums = lhc_numbers_for_shengxiao($sx, $drawUnixTs);
				$itemNumbers[] = $nums;
				foreach ($nums as $b) {
					$flat[$b] = true;
				}
			}
			ksort($flat, SORT_STRING);
			return array(
				'ok' => true,
				'type' => 'zodiac_xiao',
				'separator' => $split['separator'],
				'items' => $items,
				'item_numbers' => $itemNumbers,
				'balls_flat' => array_keys($flat),
				'amount' => $amount,
				'unit' => $unit,
				'raw' => $raw,
			);
		}
		if (preg_match('/各$/u', $left)) {
			$itemsPart = trim(mb_substr($left, 0, mb_strlen($left, 'UTF-8') - 1, 'UTF-8'));
			$presetBalls = function_exists('lhc_numbers_for_collect_keyword') ? lhc_numbers_for_collect_keyword($itemsPart) : null;
			if ($presetBalls !== null && $presetBalls !== array()) {
				$balls = array();
				foreach ($presetBalls as $pb) {
					$nb = bet_line_normalize_ball($pb);
					if ($nb !== null) {
						$balls[] = $nb;
					}
				}
				if ($balls === array()) {
					return array(
						'ok' => false,
						'error' => 'invalid_pure_number_items',
						'message' => '关键词展开后无有效球号',
						'raw' => $raw,
					);
				}
				$flat = array();
				foreach ($balls as $b) {
					$flat[$b] = true;
				}
				ksort($flat, SORT_STRING);
				$ballKeys = array_keys($flat);
				return array(
					'ok' => true,
					'type' => 'pure_number',
					'separator' => '',
					'items' => $ballKeys,
					'item_numbers' => array_map(function ($b) {
						return array($b);
					}, $ballKeys),
					'balls_flat' => $ballKeys,
					'amount' => $amount,
					'unit' => $unit,
					'raw' => $raw,
					'group_keyword' => $itemsPart,
				);
			}
			$numOk = function ($p) {
				return bet_line_normalize_ball($p) !== null;
			};
			$split = bet_line_split_by_separator($itemsPart, $seps, $numOk);
			if ($split === null) {
				return array(
					'ok' => false,
					'error' => 'invalid_pure_number_items',
					'message' => '纯数字：每项为 1–49 的号码，用分隔符连接；或写波色个位单双/合数单双/大小：蓝双各、合单各、小数(01~24)各、大数(25~49)各',
					'raw' => $raw,
				);
			}
			$balls = array();
			foreach ($split['parts'] as $p) {
				$balls[] = bet_line_normalize_ball($p);
			}
			$flat = array();
			foreach ($balls as $b) {
				$flat[$b] = true;
			}
			ksort($flat, SORT_STRING);
			return array(
				'ok' => true,
				'type' => 'pure_number',
				'separator' => $split['separator'],
				'items' => $balls,
				'item_numbers' => array_map(function ($b) {
					return array($b);
				}, $balls),
				'balls_flat' => array_keys($flat),
				'amount' => $amount,
				'unit' => $unit,
				'raw' => $raw,
			);
		}
		return array(
			'ok' => false,
			'error' => 'unknown_format',
			'message' => '需以「各号/号各/各数/数各」「各肖/肖各」、波色个位单双/合单合双/大小数「各」或号码「各」结尾（金额在末尾，可加元/园/米），例如：蓝双各100、合单各50、小数(01~24)各50、大数各80、虎马各数100米、鼠号各100元',
			'raw' => $raw,
		);
	}
}

if (!function_exists('bet_lines_parse')) {
	/**
	 * 多行解析（\n / \r\n）。
	 * @return array{lines:array,ok_all:bool}
	 */
	function bet_lines_parse($text, $drawUnixTs = null) {
		$text = str_replace("\r\n", "\n", (string) $text);
		$text = str_replace("\r", "\n", $text);
		$chunks = explode("\n", $text);
		$lines = array();
		$okAll = true;
		foreach ($chunks as $chunk) {
			$chunk = trim($chunk);
			if ($chunk === '') {
				continue;
			}
			$r = bet_line_parse($chunk, $drawUnixTs);
			$lines[] = $r;
			if (empty($r['ok'])) {
				$okAll = false;
			}
		}
		return array('lines' => $lines, 'ok_all' => $okAll);
	}
}
