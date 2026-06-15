<?php
/**
 * @copyright (C)2016-2099 Hnaoyun Inc.
 * @author XingMeng
 * @email hnxsh@foxmail.com
 * @date 2019年10月05日
 *  会员模型类
 */
namespace app\admin\model\system;

use core\basic\Model;

class sicaiModel extends Model
{

   
    // 添加会员
    public function addsicai(array $data)
    {
        return parent::table('ay_kjdata')->insert($data);
    }
}