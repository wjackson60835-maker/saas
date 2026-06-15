<?php
/**
 * 兼容后台地址 p=/YuceBlock/index（与 p=/admin/YuceBlock/index 等效）
 * 实际逻辑在 content\YuceBlockController
 */
namespace app\admin\controller;

use app\admin\controller\content\YuceBlockController as YuceBlockContentController;

class YuceBlockController extends YuceBlockContentController
{
}
