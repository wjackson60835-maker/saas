<?php
/**
 * @copyright (C)2016-2099 Hnaoyun Inc.
 * @author XingMeng
 * @email hnxsh@foxmail.com
 * @date 2016年11月5日
 *  管理后台入口文件
 */

// 定义为入口文件
define('IS_INDEX', true);

// 入口文件地址绑定
define('URL_BIND', 'admin');

// p 已含 admin/ 时再被 URL_BIND 拼接会变成 admin/admin/...，导致控制器路径错误并出现根目录 404.html（外观与 nginx 404 相同）
if (isset($_GET['p']) && $_GET['p'] !== '') {
    $p = ltrim((string) $_GET['p'], '/');
    while (stripos($p, 'admin/') === 0) {
        $p = substr($p, 6);
    }
    $_GET['p'] = $p === '' ? '' : '/' . $p;
}

// PHP版本检测
if (version_compare(phpversion(),'7.0.0','<')) {
    header('Content-Type:text/html; charset=utf-8');
    exit('您服务器PHP的版本太低，程序要求PHP版本不小于7.0');
}

// 引用内核启动文件
require dirname(__FILE__) . '/core/start.php';
