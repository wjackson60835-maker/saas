<?php
/**
 * 开奖预测区块 — 后台快捷入口（与 admin.php 同级）
 *
 * 访问：
 *   https://你的域名/yuceblock_admin.php
 * （需已登录后台；未登录会先走后台登录流程）
 */
define('IS_INDEX', true);
define('URL_BIND', 'admin');
$_GET['p'] = '/admin/YuceBlock/index';

if (version_compare(PHP_VERSION, '7.0.0', '<')) {
    header('Content-Type:text/html; charset=utf-8');
    exit('您服务器PHP的版本太低，程序要求PHP版本不小于7.0');
}

require __DIR__ . '/core/start.php';
