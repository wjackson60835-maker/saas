<?php
/**
 * 开奖预测区块 — 后台列表查询
 */
namespace app\admin\model\content;

use core\basic\Model;

class YuceBlockModel extends Model
{

    /**
     * 指定栏目下全部内容（按排序）
     *
     * @param string $scode
     * @return array|\stdClass[]
     */
    public function listByScode($scode)
    {
        $scode = escape_string($scode);
        return parent::table('ay_content')
            ->where("scode='$scode'")
            ->where("acode='" . session('acode') . "'")
            ->order('sorting ASC,id ASC')
            ->select();
    }

    /**
     * 是否为本区块下的内容
     *
     * @param int $id
     * @param string $scode
     * @return \stdClass|null
     */
    public function findOwned($id, $scode)
    {
        $id = (int) $id;
        $scode = escape_string($scode);
        return parent::table('ay_content')
            ->where("id=$id")
            ->where("scode='$scode'")
            ->where("acode='" . session('acode') . "'")
            ->find();
    }

    /**
     * 当前栏目下最小 sorting（用于新增时置顶：新条 = min-1）
     *
     * @param string $scode
     * @return int|null 无记录时为 null
     */
    public function minSortingInScode($scode)
    {
        $scode = escape_string($scode);
        $acode = escape_string(session('acode'));
        $v = parent::table('ay_content')
            ->where("scode='$scode'")
            ->where("acode='$acode'")
            ->order('sorting ASC,id ASC')
            ->value('sorting');
        if ($v === null || $v === '') {
            return null;
        }
        return (int) $v;
    }

    /**
     * 读取 config/yuce_block.php
     *
     * @return array{scode:string,brand:string}
     */
    public static function readFileConfig()
    {
        $path = CONF_PATH . '/yuce_block.php';
        if (! is_file($path)) {
            return array(
                'scode' => '365',
                'brand' => '快乐天天彩'
            );
        }
        $c = include $path;
        if (! is_array($c)) {
            return array(
                'scode' => '365',
                'brand' => '快乐天天彩'
            );
        }
        return array(
            'scode' => isset($c['scode']) ? (string) $c['scode'] : '365',
            'brand' => isset($c['brand']) ? (string) $c['brand'] : '快乐天天彩'
        );
    }

    /**
     * 写入 config/yuce_block.php（仅 scode、brand）
     *
     * @param string $scode
     * @param string $brand
     * @return bool
     */
    public static function writeFileConfig($scode, $brand)
    {
        $path = CONF_PATH . '/yuce_block.php';
        $data = array(
            'scode' => $scode,
            'brand' => $brand
        );
        $body = "<?php\n/**\n * 开奖预测区块（yuce.html）\n * - scode：内容栏目编码\n * - brand：兜底黄字前缀（单条可在后台填「主标题黄字」覆盖；栏目 def1 优先于本项）\n */\nreturn " . var_export($data, true) . ";\n";
        return (bool) file_put_contents($path, $body);
    }
}
