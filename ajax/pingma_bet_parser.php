<?php
/**
 * 平码玩法解析：二中二、三中三、一肖～五肖（连肖）。
 * 提交上限：号码类单条最多 7 个号；肖类单条最多 5 个肖（六肖/七肖及六/七连肖不可提交）。
 * 按组计费：号码玩法 C(n,k)；肖类「N肖」为 C(n,N)；「N连肖」为 C(n,N)（一肖/平特一肖 n=1 特例 1 组）。
 */
require_once __DIR__ . '/bet_line_parser.php';

if (!defined('PINGMA_MAX_NUMBER_SELECTION')) {
    define('PINGMA_MAX_NUMBER_SELECTION', 7);
}
if (!defined('PINGMA_MAX_ZODIAC_SELECTION')) {
    define('PINGMA_MAX_ZODIAC_SELECTION', 5);
}

if (!function_exists('pingma_play_keyword_pattern')) {
    function pingma_play_keyword_pattern(): string
    {
        return '(?:复式)?(?:二中二|三中三|平特一肖|[一二三四五六七1-7](?:连)?肖)';
    }
}

if (!function_exists('pingma_nCr')) {
    function pingma_nCr(int $n, int $r): int
    {
        if ($r < 0 || $n < $r) {
            return 0;
        }
        if ($r === 0 || $n === $r) {
            return 1;
        }
        $r = min($r, $n - $r);
        $c = 1;
        for ($i = 0; $i < $r; $i++) {
            $c = (int) ($c * ($n - $i) / ($i + 1));
        }
        return $c;
    }
}

if (!function_exists('pingma_combinations')) {
    /**
     * @param string[] $items
     * @return array<int, string[]>
     */
    function pingma_combinations(array $items, int $k): array
    {
        $n = count($items);
        if ($k <= 0 || $k > $n) {
            return array();
        }
        if ($k === 1) {
            $out = array();
            foreach ($items as $it) {
                $out[] = array($it);
            }
            return $out;
        }
        $out = array();
        $stack = array(array('combo' => array(), 'start' => 0));
        while ($stack) {
            $cur = array_pop($stack);
            $combo = $cur['combo'];
            $start = $cur['start'];
            if (count($combo) === $k) {
                $out[] = $combo;
                continue;
            }
            $need = $k - count($combo);
            for ($i = $start; $i <= $n - $need; $i++) {
                $next = $combo;
                $next[] = $items[$i];
                $stack[] = array('combo' => $next, 'start' => $i + 1);
            }
        }
        return $out;
    }
}

if (!function_exists('pingma_cn_digit')) {
    function pingma_cn_digit(string $ch): int
    {
        static $map = array('一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7);
        if (isset($map[$ch])) {
            return $map[$ch];
        }
        if (ctype_digit($ch)) {
            return (int) $ch;
        }
        return 0;
    }
}

if (!function_exists('pingma_zodiac_play_type')) {
    function pingma_zodiac_play_type(int $n): string
    {
        static $map = array(1 => 'yixiao', 2 => 'erxiao', 3 => 'sanxiao', 4 => 'sixiao', 5 => 'wuxiao', 6 => 'liuxiao', 7 => 'qixiao');
        return $map[$n] ?? ('zodiac_' . $n);
    }
}

if (!function_exists('pingma_zodiac_play_label')) {
    function pingma_zodiac_play_label(int $n): string
    {
        static $cn = array(1 => '平特一肖', 2 => '二', 3 => '三', 4 => '四', 5 => '五', 6 => '六', 7 => '七');
        if ($n === 1) {
            return '平特一肖';
        }
        return ($cn[$n] ?? (string) $n) . '肖';
    }
}

if (!function_exists('pingma_zodiac_lian_k_from_label')) {
    /** 连肖玩法名中的 N：五连肖→5；非连肖（三肖）→0 */
    function pingma_zodiac_lian_k_from_label(string $playLabelPlain): int
    {
        if (!preg_match('/^([一二三四五六七1-7])连肖$/u', $playLabelPlain, $m)) {
            return 0;
        }
        return pingma_cn_digit($m[1]);
    }
}

if (!function_exists('pingma_zodiac_n_from_label')) {
    /** 肖类玩法名中的 N：三肖→3、五连肖→5；无法识别→0 */
    function pingma_zodiac_n_from_label(string $playLabelPlain): int
    {
        if (preg_match('/^([一二三四五六七1-7])(?:连)?肖$/u', $playLabelPlain, $m)) {
            return pingma_cn_digit($m[1]);
        }
        return 0;
    }
}

if (!function_exists('pingma_is_yixiao_play')) {
    /** 平特一肖（录入可写「一肖」或「平特一肖」）：无复式，单条仅能选 1 个生肖。 */
    function pingma_is_yixiao_play(string $playLabelPlain): bool
    {
        $plain = preg_replace('/^复式/u', '', trim($playLabelPlain));
        return in_array($plain, array('一肖', '1肖', '平特一肖'), true);
    }
}

if (!function_exists('pingma_normalize_yixiao_play_label')) {
    function pingma_normalize_yixiao_play_label(string $playLabelPlain): string
    {
        return pingma_is_yixiao_play($playLabelPlain) ? '平特一肖' : $playLabelPlain;
    }
}

if (!function_exists('pingma_infer_is_fushi')) {
    /** 推断是否复式展示；一肖恒为 false。 */
    function pingma_infer_is_fushi(
        string $playType,
        string $playLabel,
        string $selectionType,
        int $nSel,
        string $rawSegment = ''
    ): bool {
        $playLabelPlain = preg_replace('/^复式/u', '', trim($playLabel));
        if (pingma_is_yixiao_play($playLabelPlain) || $playType === 'yixiao') {
            return false;
        }
        $isFushi = (strpos($rawSegment, '复式') === 0);
        if (!$isFushi && $selectionType === 'number') {
            $k = (strpos($playType, 'san') !== false) ? 3 : 2;
            return $nSel > $k;
        }
        if (!$isFushi && $selectionType === 'zodiac') {
            $comboK = pingma_zodiac_group_k($playLabelPlain, max(1, $nSel));
            return $nSel > $comboK;
        }
        return $isFushi;
    }
}

if (!function_exists('pingma_zodiac_group_k')) {
    /**
     * 肖类每组包含几个生肖（用于 C(n,k) 的 k）。
     * 「N肖」：k=N；「N连肖」：k=N；平特一肖：k=1。
     */
    function pingma_zodiac_group_k(string $playLabelPlain, int $n): int
    {
        if ($n <= 1) {
            return 1;
        }
        if (pingma_is_yixiao_play($playLabelPlain)) {
            return 1;
        }
        $lianK = pingma_zodiac_lian_k_from_label($playLabelPlain);
        if ($lianK > 0) {
            return $lianK;
        }
        $labelN = pingma_zodiac_n_from_label($playLabelPlain);
        if ($labelN <= 0) {
            return 2;
        }
        return $labelN;
    }
}

if (!function_exists('pingma_normalize_number_token')) {
    function pingma_normalize_number_token(string $token): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        if (!preg_match('/^\d{1,2}$/', $token)) {
            return null;
        }
        $n = (int) $token;
        if ($n < 1 || $n > 49) {
            return null;
        }
        return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('pingma_extract_numbers')) {
    /**
     * @return string[]
     */
    function pingma_extract_numbers(string $text): array
    {
        $text = preg_replace('/[\x{00A0}\x{3000}\x{FEFF}\x{200B}-\x{200D}]/u', ' ', $text);
        $text = str_replace(array('，', '、', '／', '。', '.', '～'), array(',', ',', '/', ',', ',', '-'), $text);
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if ($text === '') {
            return array();
        }
        $parts = preg_split('/[\s,\/\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $nums = array();
        foreach ($parts as $p) {
            $n = pingma_normalize_number_token($p);
            if ($n === null) {
                throw new RuntimeException('号码非法：' . $p);
            }
            $nums[] = $n;
        }
        return array_values(array_unique($nums));
    }
}

if (!function_exists('pingma_extract_zodiacs')) {
    /**
     * @return string[]
     */
    function pingma_extract_zodiacs(string $text): array
    {
        $text = preg_replace('/[\x{00A0}\x{3000}\x{FEFF}\x{200B}-\x{200D}]/u', '', $text);
        $text = preg_replace('/\s+/u', '', $text);
        if ($text === '') {
            return array();
        }
        $zset = array_flip(bet_line_zodiac_chars());
        $len = mb_strlen($text, 'UTF-8');
        $out = array();
        $seen = array();
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            if (!isset($zset[$ch])) {
                throw new RuntimeException('非法生肖字符：' . $ch);
            }
            if (!isset($seen[$ch])) {
                $seen[$ch] = true;
                $out[] = $ch;
            }
        }
        return $out;
    }
}

if (!function_exists('pingma_recalc_bet_amounts')) {
    /**
     * 金额由代码统一计算：按组 = 组数×每组单价；整单 = 用户行尾总价（组数仍由 C(n,k) 算）。
     *
     * @param array<string, mixed> $bet
     * @return array<string, mixed>
     */
    function pingma_recalc_bet_amounts(array $bet): array
    {
        $groupCount = (int) ($bet['group_count'] ?? 0);
        if ($groupCount <= 0) {
            return $bet;
        }
        $mode = (string) ($bet['amount_mode'] ?? 'per_group');
        if ($mode === 'flat_total') {
            $total = round((float) ($bet['total_amount'] ?? $bet['flat_total'] ?? 0), 2);
            if ($total <= 0) {
                throw new RuntimeException('金额须为正数');
            }
            $bet['total_amount'] = $total;
            $bet['amount_per_group'] = round($total / $groupCount, 2);
            return $bet;
        }
        $perGroup = round((float) ($bet['amount_per_group'] ?? 0), 2);
        if ($perGroup <= 0) {
            throw new RuntimeException('金额须为正数');
        }
        $bet['amount_mode'] = 'per_group';
        $bet['amount_per_group'] = $perGroup;
        $bet['total_amount'] = round($perGroup * $groupCount, 2);
        return $bet;
    }
}

if (!function_exists('pingma_sum_bets_total')) {
    /**
     * @param array<int, array<string, mixed>> $bets
     */
    function pingma_sum_bets_total(array $bets): float
    {
        $sum = 0.0;
        foreach ($bets as $bet) {
            if (!is_array($bet)) {
                continue;
            }
            $sum += (float) ($bet['total_amount'] ?? 0);
        }
        return round($sum, 2);
    }
}

if (!function_exists('pingma_parse_amount_from_segment')) {
    /**
     * 号码玩法（二中二/三中三）：行尾金额 = 每组单价，**不必写「各」**。
     * 肖类：有「各/各组/每组」= 每组单价；无则行尾金额 = 整单总价。
     *
     * @return array{amount_mode:string,amount_per_group:float,flat_total:float,body:string}
     */
    function pingma_parse_amount_from_segment(string $segment, bool $isZodiac): array
    {
        $segment = preg_replace('/[\x{00A0}\x{3000}\x{FEFF}\x{200B}-\x{200D}]/u', ' ', $segment);
        $segment = trim(preg_replace('/\s+/u', ' ', $segment));
        $amountMode = 'per_group';
        $perGroup = 0.0;
        $flatTotal = 0.0;
        $body = $segment;
        $hasGe = (bool) preg_match('/(?:各组|每组|各)/u', $segment);
        $unitPat = bet_money_unit_suffix_pattern();

        if (preg_match('/(?:各组|每组|各)\s*(\d+(?:\.\d+)?)\s*(?:' . $unitPat . ')?\s*$/u', $segment, $m)) {
            $perGroup = (float) $m[1];
            $body = trim(substr($segment, 0, (int) strpos($segment, $m[0])));
            $amountMode = 'per_group';
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(?:' . $unitPat . ')?\s*$/u', $segment, $m)) {
            $amt = (float) $m[1];
            $body = trim(substr($segment, 0, (int) strpos($segment, $m[0])));
            if ($isZodiac && !$hasGe) {
                $amountMode = 'flat_total';
                $flatTotal = $amt;
            } else {
                $amountMode = 'per_group';
                $perGroup = $amt;
            }
        } else {
            throw new RuntimeException('缺少金额：' . $segment);
        }

        $body = trim(preg_replace('/(?:各组|每组|各)\s*$/u', '', $body));

        if ($amountMode === 'per_group' && $perGroup <= 0) {
            throw new RuntimeException('金额须为正数：' . $segment);
        }
        if ($amountMode === 'flat_total' && $flatTotal <= 0) {
            throw new RuntimeException('金额须为正数：' . $segment);
        }

        return array(
            'amount_mode' => $amountMode,
            'amount_per_group' => $perGroup,
            'flat_total' => $flatTotal,
            'body' => $body,
        );
    }
}

if (!function_exists('pingma_split_segments')) {
    /**
     * @return string[]
     */
    function pingma_split_segments(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return array();
        }
        $lines = preg_split('/\R/u', $raw);
        $segments = array();
        $pat = pingma_play_keyword_pattern();
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/' . $pat . '/u', $line)) {
                throw new RuntimeException('平码格式无法识别（需含二中二/三中三/连肖等玩法关键词）：' . $line);
            }
            if (!preg_match_all('/' . $pat . '/u', $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $hits = $matches[0];
            $cnt = count($hits);
            $lineBytes = strlen($line);
            for ($i = 0; $i < $cnt; $i++) {
                // PREG_OFFSET_CAPTURE 返回字节偏移，须用 substr 而非 mb_substr
                $startByte = (int) $hits[$i][1];
                $endByte = ($i + 1 < $cnt) ? (int) $hits[$i + 1][1] : $lineBytes;
                $seg = trim(substr($line, $startByte, $endByte - $startByte));
                if ($seg !== '') {
                    $segments[] = $seg;
                }
            }
        }
        if (!$segments) {
            throw new RuntimeException('提交内容为空');
        }
        return $segments;
    }
}

if (!function_exists('pingma_parse_one_segment')) {
    function pingma_parse_one_segment(string $segment): array
    {
        $segment = trim($segment);
        $isFushi = (bool) preg_match('/^复式/u', $segment);
        if (!preg_match('/^((?:复式)?(?:二中二|三中三|平特一肖|[一二三四五六七1-7](?:连)?肖))\s*(.*)$/u', $segment, $m)) {
            throw new RuntimeException('无法识别玩法：' . $segment);
        }
        $playLabel = trim($m[1]);
        $rest = trim($m[2]);
        $playLabelPlain = pingma_normalize_yixiao_play_label(preg_replace('/^复式/u', '', $playLabel));

        $isZodiac = (bool) preg_match('/肖$/u', $playLabelPlain);
        $amtInfo = pingma_parse_amount_from_segment($rest, $isZodiac);
        $body = $amtInfo['body'];

        if ($isZodiac) {
            if (pingma_is_yixiao_play($playLabelPlain) && $isFushi) {
                throw new RuntimeException('平特一肖不支持复式，请写「平特一肖马各10」或「一肖马各10」；多个肖请分条或使用二肖及以上');
            }
            $lianK = pingma_zodiac_lian_k_from_label($playLabelPlain);
            $labelN = pingma_zodiac_n_from_label($playLabelPlain);
            $playNeedK = $lianK > 0 ? $lianK : $labelN;
            if ($playNeedK > PINGMA_MAX_ZODIAC_SELECTION) {
                throw new RuntimeException(
                    $playLabelPlain . ' 暂不支持（肖类单条最多 ' . PINGMA_MAX_ZODIAC_SELECTION . ' 个生肖）'
                );
            }
            $body = preg_replace('/^(?:各组|每组|各)\s*/u', '', $body);
            $zodiacs = pingma_extract_zodiacs($body);
            $n = count($zodiacs);
            if (pingma_is_yixiao_play($playLabelPlain)) {
                if ($n !== 1) {
                    throw new RuntimeException(
                        '平特一肖只能选择 1 个生肖（当前 ' . $n . ' 个）；覆盖多肖请分条提交或使用二肖及以上'
                    );
                }
            } elseif ($n < 1 || $n > PINGMA_MAX_ZODIAC_SELECTION) {
                throw new RuntimeException(
                    '肖类玩法最多选择 ' . PINGMA_MAX_ZODIAC_SELECTION . ' 个生肖（当前 ' . $n . ' 个）'
                );
            }
            $comboK = pingma_zodiac_group_k($playLabelPlain, $n);
            if ($n < $comboK) {
                $needLabel = $playLabelPlain !== '' ? $playLabelPlain : '肖类';
                throw new RuntimeException($needLabel . '至少选择 ' . $comboK . ' 个生肖（当前 ' . $n . ' 个）');
            }
            $groupCount = pingma_nCr($n, $comboK);
            if ($groupCount <= 0) {
                throw new RuntimeException('肖类组数计算失败');
            }
            $groups = pingma_combinations($zodiacs, $comboK);
            if ($lianK > 0) {
                static $lianCn = array(1 => '一', 2 => '二', 3 => '三', 4 => '四', 5 => '五', 6 => '六', 7 => '七');
                $label = ($lianCn[$lianK] ?? (string) $lianK) . '连肖';
                $playType = pingma_zodiac_play_type($lianK);
            } elseif (pingma_is_yixiao_play($playLabelPlain)) {
                $label = '平特一肖';
                $playType = 'yixiao';
            } else {
                $labelN = pingma_zodiac_n_from_label($playLabelPlain);
                if ($labelN > 0) {
                    static $xiaoCn = array(1 => '一', 2 => '二', 3 => '三', 4 => '四', 5 => '五', 6 => '六', 7 => '七');
                    $label = ($xiaoCn[$labelN] ?? (string) $labelN) . '肖';
                    $playType = pingma_zodiac_play_type($labelN);
                } else {
                    $playType = pingma_zodiac_play_type($n);
                    $label = pingma_zodiac_play_label($n);
                }
            }
            if ($amtInfo['amount_mode'] === 'flat_total') {
                $flat = round((float) $amtInfo['flat_total'], 2);
                $bet = array(
                    'play_type' => $playType,
                    'play_label' => $label,
                    'selection_type' => 'zodiac',
                    'selection' => $zodiacs,
                    'group_count' => $groupCount,
                    'amount_per_group' => 0.0,
                    'total_amount' => $flat,
                    'amount_mode' => 'flat_total',
                    'flat_total' => $flat,
                    'groups' => $groups,
                    'ball_scope' => 'pingma',
                    'is_fushi' => pingma_is_yixiao_play($playLabelPlain) ? false : ($isFushi || $n > $comboK),
                    'raw_segment' => $segment,
                );
                return pingma_recalc_bet_amounts($bet);
            }
            $bet = array(
                'play_type' => $playType,
                'play_label' => $label,
                'selection_type' => 'zodiac',
                'selection' => $zodiacs,
                'group_count' => $groupCount,
                'amount_per_group' => round((float) $amtInfo['amount_per_group'], 2),
                'total_amount' => 0.0,
                'amount_mode' => 'per_group',
                'groups' => $groups,
                'ball_scope' => 'pingma',
                'is_fushi' => pingma_is_yixiao_play($playLabelPlain) ? false : ($isFushi || $n > $comboK),
                'raw_segment' => $segment,
            );
            return pingma_recalc_bet_amounts($bet);
        }

        $k = strpos($playLabelPlain, '三中三') !== false ? 3 : 2;
        $playType = $k === 3 ? 'sanzhongsan' : 'erzhonger';
        $label = $k === 3 ? '三中三' : '二中二';
        $nums = pingma_extract_numbers($body);
        $n = count($nums);
        $minN = $k;
        $maxN = PINGMA_MAX_NUMBER_SELECTION;
        if ($n < $minN) {
            throw new RuntimeException($label . '至少选择 ' . $minN . ' 个号码');
        }
        if ($n > $maxN) {
            throw new RuntimeException($label . '最多选择 ' . $maxN . ' 个号码（当前 ' . $n . ' 个）');
        }
        $groupCount = pingma_nCr($n, $k);
        $groups = pingma_combinations($nums, $k);
        $bet = array(
            'play_type' => $playType,
            'play_label' => $label,
            'selection_type' => 'number',
            'selection' => $nums,
            'group_count' => $groupCount,
            'amount_per_group' => round((float) $amtInfo['amount_per_group'], 2),
            'total_amount' => 0.0,
            'amount_mode' => 'per_group',
            'groups' => $groups,
            'ball_scope' => 'pingma',
            'is_fushi' => $isFushi || $n > $k,
            'raw_segment' => $segment,
        );
        return pingma_recalc_bet_amounts($bet);
    }
}

if (!function_exists('pingma_format_money')) {
    function pingma_format_money(float $v): string
    {
        if (abs($v - round($v)) < 0.001) {
            return (string) (int) round($v);
        }
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}

if (!function_exists('pingma_format_bet_prefix')) {
    function pingma_format_bet_prefix(array $bet): string
    {
        $label = trim((string) ($bet['play_label'] ?? ''));
        $plain = preg_replace('/^复式/u', '', $label);
        if (pingma_is_yixiao_play($plain) || (string) ($bet['play_type'] ?? '') === 'yixiao') {
            return '平特一肖';
        }
        if (!empty($bet['is_fushi']) && $label !== '' && strpos($label, '复式') !== 0) {
            return '复式' . $label;
        }
        return $label;
    }
}

if (!function_exists('pingma_format_selection')) {
    function pingma_format_selection(array $bet): string
    {
        $sel = $bet['selection'] ?? array();
        if (!is_array($sel) || !$sel) {
            return '';
        }
        if (($bet['selection_type'] ?? '') === 'zodiac') {
            return implode('', $sel);
        }
        return implode('-', $sel);
    }
}

if (!function_exists('pingma_format_amount_part')) {
    function pingma_format_amount_part(array $bet): string
    {
        $bet = pingma_recalc_bet_amounts($bet);
        $groupCount = (int) ($bet['group_count'] ?? 0);
        $mode = (string) ($bet['amount_mode'] ?? 'per_group');
        $perGroup = (float) ($bet['amount_per_group'] ?? 0);
        if ($mode === 'flat_total') {
            return '整单' . pingma_format_money((float) $bet['total_amount']) . '元（' . $groupCount . '组）';
        }
        $total = round($perGroup * $groupCount, 2);
        return $groupCount . '组×' . pingma_format_money($perGroup) . '元=' . pingma_format_money($total) . '元';
    }
}

if (!function_exists('pingma_format_user_price_part')) {
    /** 用户端：仅展示行尾自填金额，不含组数/乘积总价 */
    function pingma_format_user_price_part(array $bet): string
    {
        $bet = pingma_recalc_bet_amounts($bet);
        $mode = (string) ($bet['amount_mode'] ?? 'per_group');
        if ($mode === 'flat_total') {
            $total = (float) ($bet['flat_total'] ?? $bet['total_amount'] ?? 0);
            return pingma_format_money($total) . '元';
        }
        $per = (float) ($bet['amount_per_group'] ?? 0);
        if (($bet['selection_type'] ?? '') === 'zodiac') {
            return '各' . pingma_format_money($per);
        }
        return pingma_format_money($per) . '元';
    }
}

if (!function_exists('pingma_format_bet_line_user')) {
    /** 用户端展示：玩法 + 选号/选肖 + 自填价格；不含组数与计算总价 */
    function pingma_format_bet_line_user(array $bet): string
    {
        $raw = trim((string) ($bet['raw_segment'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }
        $prefix = pingma_format_bet_prefix($bet);
        $sel = pingma_format_selection($bet);
        $price = pingma_format_user_price_part($bet);
        if ($sel !== '') {
            return $prefix . ' ' . $sel . ' ' . $price;
        }
        return trim($prefix . ' ' . $price);
    }
}

if (!function_exists('pingma_format_bets_text_user')) {
    /**
     * @param array<int, array<string, mixed>> $bets
     */
    function pingma_format_bets_text_user(array $bets, string $separator = "\n"): string
    {
        if (!$bets) {
            return '';
        }
        $lines = array();
        foreach ($bets as $bet) {
            if (!is_array($bet)) {
                continue;
            }
            $lines[] = pingma_format_bet_line_user($bet);
        }
        return implode($separator, $lines);
    }
}

if (!function_exists('pingma_format_bet_line')) {
    /** 单条平码玩法可读一行，如：复式二中二 08-18-25 · 3组×50元=150元 */
    function pingma_format_bet_line(array $bet): string
    {
        $prefix = pingma_format_bet_prefix($bet);
        $sel = pingma_format_selection($bet);
        $amt = pingma_format_amount_part($bet);
        if ($sel !== '') {
            return $prefix . ' ' . $sel . ' · ' . $amt;
        }
        return $prefix . ' · ' . $amt;
    }
}

if (!function_exists('pingma_format_bets_text')) {
    /**
     * @param array<int, array<string, mixed>> $bets
     */
    function pingma_format_bets_text(array $bets, string $separator = "\n"): string
    {
        if (!$bets) {
            return '';
        }
        $lines = array();
        foreach ($bets as $bet) {
            if (!is_array($bet)) {
                continue;
            }
            $lines[] = pingma_format_bet_line($bet);
        }
        return implode($separator, $lines);
    }
}

if (!function_exists('pingma_format_bets_summary')) {
    /**
     * @param array<int, array<string, mixed>> $bets
     */
    function pingma_format_bets_summary(array $bets, string $separator = '；'): string
    {
        return pingma_format_bets_text($bets, $separator);
    }
}

if (!function_exists('pingma_attach_display_fields')) {
    /**
     * @param array<int, array<string, mixed>> $bets
     * @return array<int, array<string, mixed>>
     */
    function pingma_attach_display_fields(array $bets): array
    {
        foreach ($bets as $i => $bet) {
            if (!is_array($bet)) {
                continue;
            }
            $bet = pingma_recalc_bet_amounts($bet);
            $bet['display_text'] = pingma_format_bet_line($bet);
            $bets[$i] = $bet;
        }
        return $bets;
    }
}

if (!function_exists('pingma_parse_submit_text')) {
    function pingma_parse_submit_text(string $raw): array
    {
        $segments = pingma_split_segments($raw);
        $bets = array();
        $totalGroups = 0;
        foreach ($segments as $seg) {
            $bet = pingma_parse_one_segment($seg);
            $bets[] = $bet;
            $totalGroups += (int) $bet['group_count'];
        }
        if (!$bets) {
            throw new RuntimeException('提交内容为空');
        }
        if (count($bets) > 500) {
            throw new RuntimeException('单次平码玩法段数过多（上限 500 段）');
        }
        $bets = pingma_attach_display_fields($bets);
        $totalAmount = pingma_sum_bets_total($bets);
        $formattedText = pingma_format_bets_text($bets);
        return array(
            'bets' => $bets,
            'total_amount' => round($totalAmount, 2),
            'total_groups' => $totalGroups,
            'total_bets' => count($bets),
            'normalized_text' => $formattedText,
            'formatted_text' => $formattedText,
        );
    }
}

if (!function_exists('pingma_payout_odds_map')) {
    /** @return array<string, float> play_type => 赔率倍数（含本金） */
    function pingma_payout_odds_map(): array
    {
        return array(
            'erzhonger' => 63.0,
            'sanzhongsan' => 705.0,
            'yixiao' => 1.1,
            'erxiao' => 3.1,
            'sanxiao' => 11.0,
            'sixiao' => 31.0,
            'wuxiao' => 108.0,
            'liuxiao' => 0.0,
            'qixiao' => 0.0,
        );
    }
}

if (!function_exists('pingma_payout_odds_for_play_type')) {
    function pingma_payout_odds_for_play_type(string $playType): float
    {
        $map = pingma_payout_odds_map();
        return (float) ($map[$playType] ?? 0.0);
    }
}

if (!function_exists('pingma_draw_zodiac_hit_set')) {
    /**
     * 七球（6 平码 + 特码）所属生肖集合。
     *
     * @return array<string, true>
     */
    function pingma_draw_zodiac_hit_set(array $zhengMa, string $teMa, ?int $drawUnixTs = null): array
    {
        $set = array();
        $balls = $zhengMa;
        if ($teMa !== '') {
            $balls[] = $teMa;
        }
        foreach ($balls as $ball) {
            $sx = lhc_shengxiao_for_number((string) $ball, $drawUnixTs);
            if ($sx !== '') {
                $set[$sx] = true;
            }
        }
        return $set;
    }
}

if (!function_exists('pingma_refresh_zodiac_bet_groups')) {
    /**
     * 按当前规则从 selection 重算肖类 groups / 组数 / 金额（修正历史错误 C(n,2) 入库数据）。
     *
     * @param array<string, mixed> $bet
     * @return array<string, mixed>
     */
    function pingma_refresh_zodiac_bet_groups(array $bet): array
    {
        if ((string) ($bet['selection_type'] ?? '') !== 'zodiac') {
            return $bet;
        }
        $sel = $bet['selection'] ?? array();
        if (!is_array($sel) || !$sel) {
            return $bet;
        }
        $label = pingma_normalize_yixiao_play_label(
            preg_replace('/^复式/u', '', (string) ($bet['play_label'] ?? ''))
        );
        if (pingma_is_yixiao_play($label) || (string) ($bet['play_type'] ?? '') === 'yixiao') {
            $label = '平特一肖';
            $bet['play_label'] = '平特一肖';
            $bet['play_type'] = 'yixiao';
            $bet['is_fushi'] = false;
        }
        $n = count($sel);
        $k = pingma_zodiac_group_k($label, $n);
        $groups = pingma_combinations($sel, $k);
        if (!$groups) {
            return $bet;
        }
        $bet['groups'] = $groups;
        $bet['group_count'] = count($groups);
        return pingma_recalc_bet_amounts($bet);
    }
}

if (!function_exists('pingma_refresh_number_bet_groups')) {
    /**
     * 按 selection 重算号码类 groups / 组数 / 金额（修正历史错误 group_count 入库数据）。
     *
     * @param array<string, mixed> $bet
     * @return array<string, mixed>
     */
    function pingma_refresh_number_bet_groups(array $bet): array
    {
        if ((string) ($bet['selection_type'] ?? '') !== 'number') {
            return pingma_recalc_bet_amounts($bet);
        }
        $sel = $bet['selection'] ?? array();
        if (!is_array($sel) || !$sel) {
            return pingma_recalc_bet_amounts($bet);
        }
        $pt = (string) ($bet['play_type'] ?? '');
        $k = $pt === 'sanzhongsan' ? 3 : 2;
        $groups = pingma_combinations($sel, $k);
        if (!$groups) {
            return pingma_recalc_bet_amounts($bet);
        }
        $bet['groups'] = $groups;
        $bet['group_count'] = count($groups);
        return pingma_recalc_bet_amounts($bet);
    }
}

if (!function_exists('pingma_normalize_bet_for_payout')) {
    /**
     * 派彩/统计前统一：按当前规则从 selection 重算组数，再算金额（避免 DB 陈旧 group_count 放大下注合计）。
     *
     * @param array<string, mixed> $bet
     * @return array<string, mixed>
     */
    function pingma_normalize_bet_for_payout(array $bet): array
    {
        $mode = (string) ($bet['amount_mode'] ?? 'per_group');
        if ($mode === 'flat_total' && !isset($bet['flat_total'])) {
            $bet['flat_total'] = (float) ($bet['total_amount'] ?? 0);
        }
        if ((string) ($bet['selection_type'] ?? '') === 'zodiac') {
            return pingma_refresh_zodiac_bet_groups($bet);
        }
        return pingma_refresh_number_bet_groups($bet);
    }
}

if (!function_exists('pingma_bet_resolve_groups')) {
    /**
     * @param array<string, mixed> $bet
     * @return array<int, array<int, string>>
     */
    function pingma_bet_resolve_groups(array $bet): array
    {
        if ((string) ($bet['selection_type'] ?? '') === 'zodiac') {
            $bet = pingma_refresh_zodiac_bet_groups($bet);
            $groups = $bet['groups'] ?? array();
            return is_array($groups) ? $groups : array();
        }
        $groups = $bet['groups'] ?? null;
        if (is_string($groups)) {
            $decoded = json_decode($groups, true);
            if (is_array($decoded) && $decoded) {
                return $decoded;
            }
        }
        if (is_array($groups) && $groups) {
            return $groups;
        }
        $sel = $bet['selection'] ?? array();
        if (!is_array($sel) || !$sel) {
            return array();
        }
        $pt = (string) ($bet['play_type'] ?? '');
        $k = $pt === 'sanzhongsan' ? 3 : 2;
        return pingma_combinations($sel, $k);
    }
}

if (!function_exists('pingma_group_hits_draw')) {
    function pingma_group_hits_draw(array $group, string $selectionType, array $zhengSet, array $zodiacHit): bool
    {
        if ($selectionType === 'zodiac') {
            foreach ($group as $sx) {
                if (empty($zodiacHit[(string) $sx])) {
                    return false;
                }
            }
            return true;
        }
        foreach ($group as $num) {
            $n = pingma_normalize_number_token((string) $num);
            if ($n === null) {
                $n = trim((string) $num);
            }
            if (!isset($zhengSet[$n])) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('pingma_zodiac_balls_from_draw')) {
    /**
     * @return array<string, array<string, true>>
     */
    function pingma_zodiac_balls_from_draw(array $zhengMa, string $teMa, ?int $drawUnixTs = null): array
    {
        $zodiacBalls = array();
        $drawBalls = $zhengMa;
        if ($teMa !== '') {
            $drawBalls[] = $teMa;
        }
        foreach ($drawBalls as $ball) {
            $n = pingma_normalize_number_token((string) $ball);
            if ($n === null) {
                continue;
            }
            $sx = lhc_shengxiao_for_number($n, $drawUnixTs);
            if ($sx === '') {
                continue;
            }
            if (!isset($zodiacBalls[$sx])) {
                $zodiacBalls[$sx] = array();
            }
            $zodiacBalls[$sx][$n] = true;
        }
        return $zodiacBalls;
    }
}

if (!function_exists('pingma_format_zodiac_with_draw_balls')) {
    function pingma_format_zodiac_with_draw_balls(string $sx, array $zodiacBalls, ?int $drawUnixTs = null): string
    {
        if (isset($zodiacBalls[$sx]) && $zodiacBalls[$sx]) {
            $balls = array_keys($zodiacBalls[$sx]);
            sort($balls, SORT_STRING);
            return $sx . '(' . implode('/', $balls) . ')';
        }
        $balls = lhc_numbers_for_shengxiao($sx, $drawUnixTs);
        $norm = array();
        foreach ($balls as $b) {
            $nb = pingma_normalize_number_token((string) $b);
            if ($nb !== null) {
                $norm[] = $nb;
            }
        }
        sort($norm, SORT_STRING);
        return $norm ? ($sx . '(' . implode('/', $norm) . ')') : $sx;
    }
}

if (!function_exists('pingma_format_hit_groups_display')) {
    /**
     * @param array<string, array<string, true>>|null $zodiacBalls 肖类且传入时，组合内附带开奖对应球号。
     */
    function pingma_format_hit_groups_display(
        array $hitGroups,
        string $selectionType,
        ?array $zodiacBalls = null,
        ?int $drawUnixTs = null
    ): string {
        if (!$hitGroups) {
            return '';
        }
        $parts = array();
        foreach ($hitGroups as $g) {
            if (!is_array($g)) {
                continue;
            }
            if ($selectionType === 'zodiac') {
                if ($zodiacBalls !== null) {
                    $items = array();
                    foreach ($g as $sx) {
                        $items[] = pingma_format_zodiac_with_draw_balls((string) $sx, $zodiacBalls, $drawUnixTs);
                    }
                    $parts[] = implode('', $items);
                } else {
                    $parts[] = implode('', $g);
                }
            } else {
                $parts[] = implode('-', $g);
            }
        }
        return implode('、', $parts);
    }
}

if (!function_exists('pingma_format_hit_groups_numbers_display')) {
    /**
     * 中奖组合对应的球号：肖类取开奖七球中该肖的球；号码类即组合内号码。
     */
    function pingma_format_hit_groups_numbers_display(
        array $hitGroups,
        string $selectionType,
        array $zhengMa,
        string $teMa,
        ?int $drawUnixTs = null
    ): string {
        if (!$hitGroups) {
            return '';
        }
        if ($selectionType !== 'zodiac') {
            return pingma_format_hit_groups_display($hitGroups, $selectionType);
        }
        $zodiacBalls = pingma_zodiac_balls_from_draw($zhengMa, $teMa, $drawUnixTs);
        $parts = array();
        foreach ($hitGroups as $g) {
            if (!is_array($g)) {
                continue;
            }
            $items = array();
            foreach ($g as $sx) {
                $sx = (string) $sx;
                if (isset($zodiacBalls[$sx]) && $zodiacBalls[$sx]) {
                    $balls = array_keys($zodiacBalls[$sx]);
                    sort($balls, SORT_STRING);
                    $items[] = $sx . '→' . implode('/', $balls);
                } else {
                    $balls = lhc_numbers_for_shengxiao($sx, $drawUnixTs);
                    $norm = array();
                    foreach ($balls as $b) {
                        $nb = pingma_normalize_number_token((string) $b);
                        if ($nb !== null) {
                            $norm[] = $nb;
                        }
                    }
                    sort($norm, SORT_STRING);
                    $items[] = $sx . '→' . implode('/', $norm);
                }
            }
            $parts[] = implode('·', $items);
        }
        return implode('、', $parts);
    }
}

if (!function_exists('pingma_calc_bet_payout')) {
    /**
     * @param array<string, mixed> $bet
     * @return array<string, mixed>
     */
    function pingma_calc_bet_payout(array $bet, array $zhengMa, string $teMa, ?int $drawUnixTs = null): array
    {
        $bet = pingma_normalize_bet_for_payout($bet);
        $zhengSet = array();
        foreach ($zhengMa as $ball) {
            $n = pingma_normalize_number_token((string) $ball);
            if ($n !== null) {
                $zhengSet[$n] = true;
            }
        }
        $zodiacHit = pingma_draw_zodiac_hit_set($zhengMa, $teMa, $drawUnixTs);
        $selType = (string) ($bet['selection_type'] ?? 'number');
        $groups = pingma_bet_resolve_groups($bet);
        $hitGroups = array();
        foreach ($groups as $g) {
            if (!is_array($g)) {
                continue;
            }
            if (pingma_group_hits_draw($g, $selType, $zhengSet, $zodiacHit)) {
                $hitGroups[] = $g;
            }
        }
        $odds = pingma_payout_odds_for_play_type((string) ($bet['play_type'] ?? ''));
        $per = (float) ($bet['amount_per_group'] ?? 0);
        $hitCount = count($hitGroups);
        $payout = round($hitCount * $per * $odds, 2);
        $zodiacBalls = ($selType === 'zodiac')
            ? pingma_zodiac_balls_from_draw($zhengMa, $teMa, $drawUnixTs)
            : null;
        return array(
            'odds' => $odds,
            'group_count' => count($groups),
            'hit_count' => $hitCount,
            'amount_per_group' => $per,
            'stake' => round((float) ($bet['total_amount'] ?? 0), 2),
            'payout' => $payout,
            'payout_per_hit' => round($per * $odds, 2),
            'hit_groups_display' => pingma_format_hit_groups_display($hitGroups, $selType, $zodiacBalls, $drawUnixTs),
            'hit_groups_numbers_display' => pingma_format_hit_groups_numbers_display(
                $hitGroups,
                $selType,
                $zhengMa,
                $teMa,
                $drawUnixTs
            ),
        );
    }
}
