<?php
/**
 * 投注行解析 API：POST/GET 参数 text 或 line（单行）；可选 draw_ts（Unix 时间戳，用于生肖年度表）。
 * 多行用换行分隔，返回 JSON。勿对 text 使用会吞掉冒号/空格的 vars 过滤。
 */
date_default_timezone_set('Asia/Shanghai');
if (!headers_sent()) {
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

require_once __DIR__ . '/bet_line_parser.php';

$text = '';
if (isset($_POST['text'])) {
	$text = (string) $_POST['text'];
} elseif (isset($_POST['line'])) {
	$text = (string) $_POST['line'];
} elseif (isset($_GET['text'])) {
	$text = (string) $_GET['text'];
} elseif (isset($_GET['line'])) {
	$text = (string) $_GET['line'];
}

$drawTs = null;
if (isset($_POST['draw_ts']) && $_POST['draw_ts'] !== '') {
	$drawTs = (int) $_POST['draw_ts'];
} elseif (isset($_GET['draw_ts']) && $_GET['draw_ts'] !== '') {
	$drawTs = (int) $_GET['draw_ts'];
}

$multi = strpos($text, "\n") !== false || strpos($text, "\r") !== false;
if ($multi) {
	$out = bet_lines_parse($text, $drawTs);
	echo json_encode($out, JSON_UNESCAPED_UNICODE);
	exit;
}

$out = bet_line_parse(trim($text), $drawTs);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
