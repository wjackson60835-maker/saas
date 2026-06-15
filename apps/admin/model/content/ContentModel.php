<?php
/**
 * @copyright (C)2016-2099 Hnaoyun Inc.
 * @author XingMeng
 * @email hnxsh@foxmail.com
 * @date 2017年12月15日
 *  列表文章模型类
 */
namespace app\admin\model\content;

use core\basic\Config;
use core\basic\Db;
use core\basic\Model;

class ContentModel extends Model
{

    protected $scodes = array();

    /** @var array<string,bool> 本请求内缓存：ay_content 是否含某可选列 */
    private $ayContentColumnExists = array();

    /**
     * 必须在主库判断列是否存在：isExist() 默认走从库，读写分离时刚 ALTER 后主从延迟会导致误判并剥离字段。
     */
    private function sqlIsExistOnMaster(string $sql): bool
    {
        $result = $this->query($sql, 'master');
        if (! $result) {
            return false;
        }
        if ($result instanceof \mysqli_result) {
            $ok = $result->num_rows > 0;
            $result->free();
            return $ok;
        }
        if ($result instanceof \PDOStatement) {
            $ok = (bool) $result->fetch();
            $result->closeCursor();
            return $ok;
        }
        return false;
    }

    /**
     * @param non-empty-string $column 仅字母数字下划线
     */
    private function ayContentHasColumn(string $column): bool
    {
        if (! preg_match('/^\w+$/', $column)) {
            return false;
        }
        if (array_key_exists($column, $this->ayContentColumnExists)) {
            return $this->ayContentColumnExists[$column];
        }
        $prefix = Config::get('database.prefix');
        if (! is_string($prefix) || $prefix === '') {
            $prefix = 'ay_';
        }
        $table = $prefix . 'content';
        $safeTable = str_replace('`', '``', $table);
        $type = Config::get('database.type');
        if ($type === 'sqlite' || $type === 'pdo_sqlite') {
            $this->ayContentColumnExists[$column] = false;
            return false;
        }
        $sql = "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$column}'";
        $this->ayContentColumnExists[$column] = $this->sqlIsExistOnMaster($sql);
        return $this->ayContentColumnExists[$column];
    }

    /**
     * 无对应列时剥离字段，避免 INSERT/UPDATE 报错（执行 doc 下 SQL 后自动保留）。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function stripUnknownOptionalContentColumns(array $data): array
    {
        foreach (array('row_bgcolor', 'title_star_color') as $c) {
            if (array_key_exists($c, $data) && ! $this->ayContentHasColumn($c)) {
                unset($data[$c]);
            }
        }
        return $data;
    }

    /**
     * 保存内容时若库表缺少列表样式列，则尝试自动 ALTER（需账号有 ALTER 权限；失败则仍剥离字段，与手工执行 doc 下 SQL 等价）。
     *
     * @param array<string,mixed> $data
     */
    private function ensureAyContentListStyleColumnsIfNeeded(array $data): void
    {
        if (! array_key_exists('row_bgcolor', $data) && ! array_key_exists('title_star_color', $data)) {
            return;
        }
        $prefix = Config::get('database.prefix');
        if (! is_string($prefix) || $prefix === '') {
            $prefix = 'ay_';
        }
        $table = $prefix . 'content';
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return;
        }
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $type = Config::get('database.type');
        if ($type === 'sqlite' || $type === 'pdo_sqlite') {
            return;
        }

        $defs = array(
            'row_bgcolor' => "ADD COLUMN `row_bgcolor` varchar(7) NOT NULL DEFAULT '' COMMENT '列表行背景色#hex'",
            'title_star_color' => "ADD COLUMN `title_star_color` varchar(7) NOT NULL DEFAULT '' COMMENT '标题星号颜色#hex'",
        );

        foreach ($defs as $col => $fragment) {
            if ($this->ayContentHasColumn($col)) {
                continue;
            }
            $sql = 'ALTER TABLE ' . $quotedTable . ' ' . $fragment;
            if ($this->runListStyleDdlBestEffort($sql)) {
                $this->ayContentColumnExists[$col] = true;
            }
        }
        // 强制下次 strip 前在主库重新探测，避免缓存里仍是「无列」
        unset($this->ayContentColumnExists['row_bgcolor'], $this->ayContentColumnExists['title_star_color']);
    }

    private function runListStyleDdlBestEffort(string $sql): bool
    {
        $cfg = Config::get('database');
        if (! is_array($cfg)) {
            return false;
        }
        $type = Config::get('database.type');
        try {
            if ($type === 'mysqli') {
                $host = isset($cfg['host']) ? (string) $cfg['host'] : '127.0.0.1';
                if ($host === 'localhost') {
                    $host = '127.0.0.1';
                }
                $port = isset($cfg['port']) ? (int) $cfg['port'] : 3306;
                $mysqli = @new \mysqli($host, (string) ($cfg['user'] ?? ''), (string) ($cfg['passwd'] ?? ''), (string) ($cfg['dbname'] ?? ''), $port);
                if ($mysqli->connect_errno) {
                    return false;
                }
                $charset = Config::get('database.charset') ?: 'utf8';
                $mysqli->set_charset($charset);
                $mysqli->query($sql);
                $errno = (int) $mysqli->errno;
                $mysqli->close();
                return $errno === 0 || $errno === 1060;
            }
            if ($type === 'pdo_mysql') {
                $charset = Config::get('database.charset') ?: 'utf8';
                $dsn = 'mysql:host=' . ($cfg['host'] ?? '127.0.0.1') . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . ($cfg['dbname'] ?? '') . ';charset=' . $charset;
                $pdo = new \PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['passwd'] ?? ''));
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                try {
                    $pdo->exec($sql);
                    return true;
                } catch (\PDOException $e) {
                    $msg = $e->getMessage();
                    if (strpos($msg, '1060') !== false || stripos($msg, 'Duplicate column') !== false) {
                        return true;
                    }
                    return false;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    // 获取文章列表
    public function getList($mcode, $where = array())
    {
        $field = array(
            'a.id',
            'b.name as sortname',
            'a.scode',
            'c.name as subsortname',
            'a.subscode',
            'a.title',
            'a.titlecolor',
            'a.subtitle',
            'a.date',
            'a.sorting',
            'a.status',
            'a.istop',
            'a.isrecommend',
            'a.isheadline',
            'a.visits',
            'a.ico',
            'a.pics',
            'a.filename',
            'a.outlink',
            'd.urlname',
            'b.filename as sortfilename'
        );
        foreach (array('row_bgcolor', 'title_star_color') as $optCol) {
            if ($this->ayContentHasColumn($optCol)) {
                $field[] = 'a.' . $optCol;
            }
        }
        $join = array(
            array(
                'ay_content_sort b',
                'a.scode=b.scode',
                'LEFT'
            ),
            array(
                'ay_content_sort c',
                'a.subscode=c.scode',
                'LEFT'
            ),
            array(
                'ay_model d',
                'b.mcode=d.mcode',
                'LEFT'
            )
        );
        return parent::table('ay_content a')->field($field)
            ->where("b.mcode='$mcode'")
            ->where('d.type=2 OR d.type is null ')
            ->where("a.acode='" . session('acode') . "'")
            ->where($where)
            ->join($join)
            ->order('a.sorting ASC,a.id DESC')
            ->page()
            ->select();
    }

    // 查找指定分类及子类文章
    public function findContent($mcode, $scode, $keyword)
    {
        $fields = array(
            'a.id',
            'b.name as sortname',
            'a.scode',
            'c.name as subsortname',
            'a.subscode',
            'a.title',
            'a.subtitle',
            'a.date',
            'a.sorting',
            'a.status',
            'a.istop',
            'a.isrecommend',
            'a.isheadline',
            'a.visits',
            'a.ico',
            'a.pics',
            'a.filename',
            'a.outlink',
            'd.urlname',
            'b.filename as sortfilename'
        );
        $join = array(
            array(
                'ay_content_sort b',
                'a.scode=b.scode',
                'LEFT'
            ),
            array(
                'ay_content_sort c',
                'a.subscode=c.scode',
                'LEFT'
            ),
            array(
                'ay_model d',
                'b.mcode=d.mcode',
                'LEFT'
            )
        );
        $this->scodes = array(); // 先清空
        $scodes = $this->getSubScodes($scode);
        return parent::table('ay_content a')->field($fields)
            ->where("b.mcode='$mcode'")
            ->where('d.type=2 OR d.type is null ')
            ->where("a.acode='" . session('acode') . "'")
            ->in('a.scode', $scodes)
            ->like('a.title', $keyword)
            ->join($join)
            ->order('a.sorting ASC,a.id DESC')
            ->page()
            ->select();
    }

    // 在全部栏目查找文章
    public function findContentAll($mcode, $keyword)
    {
        $fields = array(
            'a.id',
            'b.name as sortname',
            'a.scode',
            'c.name as subsortname',
            'a.subscode',
            'a.title',
            'a.subtitle',
            'a.date',
            'a.sorting',
            'a.status',
            'a.istop',
            'a.isrecommend',
            'a.isheadline',
            'a.visits',
            'a.ico',
            'a.pics',
            'a.filename',
            'a.outlink',
            'd.urlname',
            'b.filename as sortfilename'
        );
        $join = array(
            array(
                'ay_content_sort b',
                'a.scode=b.scode',
                'LEFT'
            ),
            array(
                'ay_content_sort c',
                'a.subscode=c.scode',
                'LEFT'
            ),
            array(
                'ay_model d',
                'b.mcode=d.mcode',
                'LEFT'
            )
        );
        return parent::table('ay_content a')->field($fields)
            ->where("b.mcode='$mcode'")
            ->where('d.type=2 OR d.type is null ')
            ->where("a.acode='" . session('acode') . "'")
            ->like('a.title', $keyword)
            ->join($join)
            ->order('a.sorting ASC,a.id DESC')
            ->page()
            ->select();
    }

    // 获取子栏目
    public function getSubScodes($scode)
    {
        if (! $scode) {
            return;
        }
        $this->scodes[] = $scode;
        $subs = parent::table('ay_content_sort')->where("pcode='$scode'")->column('scode');
        if ($subs) {
            foreach ($subs as $value) {
                $this->getSubScodes($value);
            }
        }
        return $this->scodes;
    }

    // 检查文章
    public function checkContent($where)
    {
        return parent::table('ay_content')->field('id')
            ->where($where)
            ->find();
    }

    // 获取文章详情
    public function getContent($id)
    {
        $field = array(
            'a.*',
            'b.name as sortname',
            'c.name as subsortname',
            'd.*'
        );
        $join = array(
            array(
                'ay_content_sort b',
                'a.scode=b.scode',
                'LEFT'
            ),
            array(
                'ay_content_sort c',
                'a.subscode=c.scode',
                'LEFT'
            ),
            array(
                'ay_content_ext d',
                'a.id=d.contentid',
                'LEFT'
            )
        );
        return parent::table('ay_content a')->field($field)
            ->where("a.id=$id")
            ->where("a.acode='" . session('acode') . "'")
            ->join($join)
            ->find();
    }

    // 添加文章
    public function addContent(array $data)
    {
        $this->ensureAyContentListStyleColumnsIfNeeded($data);
        $data = $this->stripUnknownOptionalContentColumns($data);
        return parent::table('ay_content')->autoTime()->insertGetId($data);
    }

    // 删除文章
    public function delContent($id)
    {
        return parent::table('ay_content')->where("id=$id")
            ->where("acode='" . session('acode') . "'")
            ->delete();
    }

    // 删除文章
    public function delContentList($ids)
    {
        return parent::table('ay_content')->where("acode='" . session('acode') . "'")->delete($ids);
    }

    // 修改文章
    public function modContent($id, $data)
    {
        if (is_array($data)) {
            $this->ensureAyContentListStyleColumnsIfNeeded($data);
            $data = $this->stripUnknownOptionalContentColumns($data);
        }
        return parent::table('ay_content')->autoTime()
            ->in('id', $id)
            ->where("acode='" . session('acode') . "'")
            ->update($data);
    }

    // 复制内容到指定栏目
    public function copyContent($ids, $scode)
    {
        // 查找出要复制的主内容
        $data = parent::table('ay_content')->in('id', $ids)->select(1);
        
        foreach ($data as $key => $value) {
            // 查找扩展内容
            $extdata = parent::table('ay_content_ext')->where('contentid=' . $value['id'])->find(1);
            
            // 去除主键并修改栏目
            unset($value['id']);
            $value['scode'] = $scode;
            
            // 插入主内容
            $id = parent::table('ay_content')->insertGetId($value);
            
            // 插入扩展内容
            if ($id && $extdata) {
                unset($extdata['extid']);
                $extdata['contentid'] = $id;
                $result = parent::table('ay_content_ext')->insert($extdata);
            } else {
                $result = $id;
            }
        }
        return $result;
    }

    // 查找文章扩展内容
    public function findContentExt($id)
    {
        return parent::table('ay_content_ext')->where("contentid=$id")->find();
    }

    // 添加文章扩展内容
    public function addContentExt(array $data)
    {
        return parent::table('ay_content_ext')->insert($data);
    }

    // 修改文章扩展内容
    public function modContentExt($id, $data)
    {
        return parent::table('ay_content_ext')->where("contentid=$id")->update($data);
    }

    // 删除文章扩展内容
    public function delContentExt($id)
    {
        return parent::table('ay_content_ext')->where("contentid=$id")->delete();
    }

    // 删除文章扩展内容
    public function delContentExtList($ids)
    {
        return parent::table('ay_content_ext')->delete($ids, 'contentid');
    }

    // 检查自定义URL名称
    public function checkFilename($filename, $where = array())
    {
        return parent::table('ay_content')->field('id')
            ->where("filename='$filename'")
            ->where($where)
            ->find();
    }

    public function getImage()
    {
        $list = parent::table('ay_content')->limit(2000)->column('ico,pics,content');
        foreach ($list as &$value){
            preg_match_all('/<img\s+.*?src=\s?[\'|\"](.*?(\.gif|\.jpg|\.png|\.jpeg))[\'|\"].*?[\/]?>/i', decode_string($value['content']), $match);
            $value['content_img'] = $match[1];
            $value['pics'] = explode(',',$value['pics']);
            unset($value['content']);
        }
        return $list;
    }
}