<?php
/**
 * 数据采集后台控制器
 */
namespace app\admin\controller;

use core\basic\Controller;

class CollectController extends Controller
{
    // 数据采集管理
    public function index()
    {
        $this->display('kaijiang/collect_admin.html');
    }
}

