<?php
// =======可用于二开时自定义路由，升级不覆盖============
return array(
    
    'url_route' => array(
        // URL地址路由，如后台站点信息控制器：'admin/Site' => 'admin/content.Site',
        // 开奖预测区块（升级不覆盖 apps/common/route.php 时此处仍生效）
        'admin/YuceBlock' => 'admin/content.YuceBlock',
    )
);