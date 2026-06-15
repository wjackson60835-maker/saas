<?php
/**
 * 后台入口文件（标准）
 */
define('IS_INDEX', true);
define('URL_BIND', 'admin');

if (version_compare(PHP_VERSION, '7.0.0', '<')) {
    header('Content-Type:text/html; charset=utf-8');
    exit('您服务器PHP的版本太低，程序要求PHP版本不小于7.0');
}

require __DIR__ . '/core/start.php';
