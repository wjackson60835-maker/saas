<?php
/**
 * @copyright (C)2016-2099 Hnaoyun Inc.
 * @author XingMeng
 * @email hnxsh@foxmail.com
 * @date 2019年10月05日
 *  会员模型类
 */
namespace app\admin\model\member;

use core\basic\Model;

class MemberModel extends Model
{

    // 获取会员列表
    public function getList()
    {
        $field = array(
            'a.*',
            'b.gname'
        );
        $join = array(
            'ay_member_group b',
            'a.gid=b.id',
            'LEFT'
        );
        return parent::table('ay_member a')->field($field)
            ->join($join)
            ->order('a.id desc')
            ->page()
            ->select();
    }
	// 获取私彩开奖列表
    public function sicaigetList()
    {
        $field = array(
            'a.*',
            'b.gname'
        );
        $join = array(
            'ay_member_group b',
            'a.gid=b.id',
            'LEFT'
        );
        return parent::table('ay_kjdata a')->field($field)
            ->join($join)
            ->order('a.id desc')
            ->page()
            ->select();
    }

    // 查找会员
    public function findMember($field, $keyword)
    {
        $fields = array(
            'a.*',
            'b.gname'
        );
        $join = array(
            'ay_member_group b',
            'a.gid=b.id',
            'LEFT'
        );
        return parent::table('ay_member a')->field($fields)
            ->join($join)
            ->like($field, $keyword)
            ->order('a.id desc')
            ->page()
            ->select();
    }
    // 查找私彩
    public function sicaifindMember($field, $keyword)
    {
        $fields = array(
            'a.*',
            'b.gname'
        );
        $join = array(
            'ay_member_group b',
            'a.gid=b.id',
            'LEFT'
        );
        return parent::table('ay_kjdata a')->field($fields)
            ->join($join)
            ->like($field, $keyword)
            ->order('a.id desc')
            ->page()
            ->select();
    }
    // 检查会员
    public function checkMember($where)
    {
        return parent::table('ay_member')->where($where)->find();
    }

    // 获取最后一个code
    public function getLastCode()
    {
        return parent::table('ay_member')->order('id DESC')->value('ucode');
    }
    
    // 获取会员详情
    public function getMember($id)
    {
        $field = array(
            'a.*',
            'b.gname'
        );
        $join = array(
            'ay_member_group b',
            'a.gid=b.id',
            'LEFT'
        );
        return parent::table('ay_member a')->field($field)
            ->join($join)
            ->where("a.id=$id")
            ->find();
    }
    // 获取私彩详情
    public function sicaigetMember($id)
    {
        $field = array(
            'a.*',
            'b.gname'
        );
        $join = array(
            'ay_member_group b',
            'a.gid=b.id',
            'LEFT'
        );
        return parent::table('ay_kjdata a')->field($field)
            ->join($join)
            ->where("a.id=$id")
            ->find();
    }
    // 添加会员
    public function addMember(array $data)
    {
        return parent::table('ay_member')->insert($data);
    }
    // 添加私彩
    public function sicaiaddMember(array $data)
    {
        return parent::table('ay_kjdata')->insert($data);
    }
    // 删除会员
    public function delMember($id)
    {
        return parent::table('ay_member')->where("id=$id")->delete();
    }

    // 删除会员
    public function delMemberList($ids)
    {
        return parent::table('ay_member')->delete($ids);
    }
	
	// 删除私彩
    public function sicaidelMember($id)
    {
        return parent::table('ay_kjdata')->where("id=$id")->delete();
    }

    // 删除私彩
    public function sicaidelMemberList($ids)
    {
        return parent::table('ay_kjdata')->delete($ids);
    }

    // 修改会员
    public function modMember($id, $data)
    {
        return parent::table('ay_member')->where("id=$id")->update($data);
    }

    // 修改会员
    public function modMemberList($ids, $data)
    {
        return parent::table('ay_member')->in('id', $ids)->update($data);
    }
    
	  // 修改私彩数据
    public function sicaimodMember($id, $data)
    {
        return parent::table('ay_kjdata')->where("id=$id")->update($data);
    }

    // 修改私彩数据
    public function sicaimodMemberList($ids, $data)
    {
        return parent::table('ay_kjdata')->in('id', $ids)->update($data);
    }
    // 会员字段
    public function getFields()
    {
        return parent::table('ay_member_field')->where('status=1')
            ->order('sorting')
            ->select();
    }
}