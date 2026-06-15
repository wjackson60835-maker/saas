<?php
/**
 * 开奖预测区块 — 独立后台管理
 */
namespace app\admin\controller\content;

use core\basic\Controller;
use app\admin\model\content\YuceBlockModel;
use app\admin\model\content\ContentModel;
use app\admin\model\content\ContentSortModel;
use app\home\model\YuceBlockView;

class YuceBlockController extends Controller
{

    /** @var YuceBlockModel */
    private $yuceModel;

    /** @var ContentModel */
    private $contentModel;

    /** @var ContentSortModel */
    private $sortModel;

    public function __construct()
    {
        $this->yuceModel = new YuceBlockModel();
        $this->contentModel = new ContentModel();
        $this->sortModel = new ContentSortModel();
    }

    private function effectiveScode()
    {
        $cfg = YuceBlockModel::readFileConfig();
        return $cfg['scode'];
    }

    private function assertYuceContent($id)
    {
        $id = (int) $id;
        $scode = $this->effectiveScode();
        $row = $this->yuceModel->findOwned($id, $scode);
        if (! $row) {
            error('记录不存在或不属于开奖预测区块！', - 1);
        }
        return $row;
    }

    /**
     * 容器 tbody id，存 subtitle。留空则自动生成 yuce_blk+内容ID（须先通过格式校验或为空）。
     */
    private function finalizeYuceSubtitle($raw, $contentId)
    {
        $raw = $raw === null ? '' : trim((string) $raw);
        if ($raw === '') {
            return 'yuce_blk_' . (int) $contentId;
        }
        return $raw;
    }

    /** 主/副标题横条背景：入库前校验，空则默认蓝 */
    private function yuceBarBgOrDefault($postKey)
    {
        $v = YuceBlockView::sanitizeBarBg(post($postKey));
        return $v !== '' ? $v : YuceBlockView::DEFAULT_BAR_BG;
    }

    /** 后台表单：主标题条内黄字/绿字字体字段 */
    private function assignYuceBarFontForm($picsRaw)
    {
        foreach (YuceBlockView::barFontFormDefaults($picsRaw) as $k => $v) {
            $this->assign($k, $v);
        }
    }

    /** 后台表单：接口列表行字色/背景（存 tags JSON） */
    private function assignYuceListRowForm($tagsRaw)
    {
        $st = YuceBlockView::parseListRowStyleFromTags($tagsRaw);
        $this->assign('yuce_list_fc', $st['fc']);
        $this->assign('yuce_list_bg', $st['bg']);
    }

    /** 主标题黄字，存 ay_content.source（空则前台用栏目/配置默认） */
    private function sanitizeYuceBrand($raw)
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $s = trim(clear_html_blank((string) $raw));
        if (mb_strlen($s, 'UTF-8') > 80) {
            return mb_substr($s, 0, 80, 'UTF-8');
        }
        return $s;
    }

    public function index()
    {
        $cfg = YuceBlockModel::readFileConfig();
        $scode = $cfg['scode'];
        $sort = $this->sortModel->getSort($scode);
        $list = $sort ? $this->yuceModel->listByScode($scode) : array();

        $this->assign('cfg_scode', $cfg['scode']);
        $this->assign('cfg_brand', $cfg['brand']);
        $this->assign('sort', $sort);
        $this->assign('scode', $scode);
        $this->assign('items', $list);
        $this->assign('items_count', is_array($list) ? count($list) : 0);
        $this->assign('sort_missing', ! $sort);
        $this->display('content/yuceblock.html');
    }

    /**
     * 保存全局设置：栏目描述1(def1)、配置文件 scode/brand（横条背景在每条「添加/修改」里设置）
     */
    public function saveSettings()
    {
        if (! $_POST) {
            location(url('/admin/YuceBlock/index'));
        }

        $scode = trim(post('cfg_scode', 'vars'));
        $brand = post('cfg_brand', 'vars');
        $def1 = post('sort_def1', 'vars');

        if ($scode === '') {
            alert_back('栏目编码不能为空！');
        }

        if (! preg_match('/^[\w\-]+$/', $scode)) {
            alert_back('栏目编码格式不正确！');
        }

        $sort = $this->sortModel->getSort($scode);
        if (! $sort) {
            alert_back('当前区域下不存在该栏目编码，请先在「内容栏目」中创建或核对 config。');
        }

        if (! YuceBlockModel::writeFileConfig($scode, $brand === null ? '' : (string) $brand)) {
            alert_back('写入 config/yuce_block.php 失败，请检查 config 目录权限！');
        }

        $this->sortModel->modSort($scode, array(
            'def1' => $def1 === null ? '' : clear_html_blank($def1),
            'update_user' => session('username')
        ));

        $this->log('保存开奖预测区块设置成功');
        success('保存成功！', url('/admin/YuceBlock/index'));
    }

    public function add()
    {
        $cfg = YuceBlockModel::readFileConfig();
        $scode = $cfg['scode'];
        $sort = $this->sortModel->getSort($scode);
        if (! $sort) {
            error('请先创建开奖预测栏目（scode=' . htmlspecialchars($scode) . '）', url('/admin/YuceBlock/index'));
        }

        if ($_POST) {
            $title = post('title', 'vars');
            $subtitle = post('subtitle', 'vars');
            $keywords = post('keywords', 'vars');
            $description = post('description');
            $content = post('content');
            $status = post('status', 'int', '', '', 1);
            $outlink = post('outlink', 'vars');
            $yuceBrand = $this->sanitizeYuceBrand(post('yuce_brand', 'vars'));
            $titleBarBg = $this->yuceBarBgOrDefault('titlecolor');
            $subtitleBarBg = $this->yuceBarBgOrDefault('yuce_subbar_bg');

            if (! $title) {
                alert_back('标题不能为空！');
            }
            $subtitle = $subtitle === null ? '' : trim((string) $subtitle);
            if ($subtitle !== '' && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $subtitle)) {
                alert_back('容器 ID 须字母开头、仅字母数字下划线，或留空由系统自动生成。');
            }
            if ($keywords !== '0' && $keywords !== '1') {
                alert_back('「显示副标题行」请填 0 或 1！');
            }
            $picsJson = YuceBlockView::buildBarFontsJsonFromPost();
            if ($picsJson === null) {
                alert_back('标题字体样式配置过长或无效！');
            }
            $listTags = YuceBlockView::buildListRowTagsFromPost();
            if ($listTags === null) {
                alert_back('接口列表行颜色配置无效！');
            }
            $sortingPost = post('sorting');
            if ($sortingPost === null || $sortingPost === '') {
                $minSort = $this->yuceModel->minSortingInScode($scode);
                $sorting = ($minSort === null) ? 0 : ($minSort - 1);
            } else {
                $sorting = (int) $sortingPost;
            }

            // 勿用 post('date','vars')：vars 不允许冒号，「2026-04-07 10:00:00」会失败变 null 后落为当前时间；与内容管理一致用 post('date')
            $dateVal = post('date');
            $publishDate = ($dateVal !== null && trim((string) $dateVal) !== '') ? $dateVal : date('Y-m-d H:i:s');

            $data = array(
                'acode' => session('acode'),
                'scode' => $scode,
                'subscode' => '',
                'title' => $title,
                'titlecolor' => $titleBarBg,
                'row_bgcolor' => '',
                'title_star_color' => '',
                'subtitle' => $subtitle,
                'filename' => '',
                'author' => session('username'),
                'source' => $yuceBrand,
                'outlink' => $outlink ?: '',
                'date' => $publishDate,
                'ico' => '',
                'pics' => $picsJson,
                'picstitle' => '',
                'content' => $content ?: '',
                'tags' => $listTags,
                'enclosure' => $subtitleBarBg,
                'keywords' => $keywords,
                'description' => $description ?: '',
                'sorting' => (int) $sorting,
                'status' => (string) $status,
                'istop' => '0',
                'isrecommend' => '0',
                'isheadline' => '0',
                'gid' => 0,
                'gtype' => '4',
                'gnote' => '',
                'visits' => 0,
                'likes' => 0,
                'oppose' => 0,
                'create_user' => session('username'),
                'update_user' => session('username')
            );

            $newId = $this->contentModel->addContent($data);
            if ($newId !== false) {
                $finalSub = $this->finalizeYuceSubtitle($subtitle, (int) $newId);
                if ($subtitle === '' || $finalSub !== $subtitle) {
                    $this->contentModel->modContent((int) $newId, array(
                        'subtitle' => $finalSub,
                        'update_user' => session('username')
                    ));
                }
                $this->log('开奖预测区块新增内容成功 id=' . $newId);
                success('添加成功！', url('/admin/YuceBlock/index'));
            }
            alert_back('添加失败！');
        }

        $this->assign('mod', false);
        $this->assign('row', (object) array(
            'title' => '',
            'subtitle' => '',
            'keywords' => '0',
            'description' => '',
            'content' => '',
            'sorting' => '',
            'status' => '1',
            'outlink' => '',
            'source' => '',
            'titlecolor' => YuceBlockView::DEFAULT_BAR_BG,
            'enclosure' => YuceBlockView::DEFAULT_BAR_BG,
            'pics' => '',
            'tags' => '',
            'date' => date('Y-m-d H:i:s')
        ));
        $this->assignYuceBarFontForm('');
        $this->assignYuceListRowForm('');
        $this->assign('sort', $sort);
        $this->assign('scode', $scode);
        $this->display('content/yuceblock_edit.html');
    }

    public function mod()
    {
        if (! $id = get('id', 'int')) {
            error('参数错误！', - 1);
        }
        $this->assertYuceContent($id);
        $cfg = YuceBlockModel::readFileConfig();
        $sort = $this->sortModel->getSort($cfg['scode']);

        if (! $sort) {
            error('栏目不存在！', url('/admin/YuceBlock/index'));
        }

        if (($field = get('field', 'var')) !== '' && is_string($field) && ! is_null($value = get('value', 'var'))) {
            if (! in_array($field, array(
                'status'
            ), true)) {
                alert_back('不允许修改该字段！');
            }
            $this->contentModel->modContent($id, array(
                $field => $value,
                'update_user' => session('username')
            ));
            location(- 1);
        }

        if ($_POST) {
            $title = post('title', 'vars');
            $subtitle = post('subtitle', 'vars');
            $keywords = post('keywords', 'vars');
            $description = post('description');
            $content = post('content');
            $sorting = post('sorting', 'int');
            $status = post('status', 'int', '', '', 1);
            $outlink = post('outlink', 'vars');
            $yuceBrand = $this->sanitizeYuceBrand(post('yuce_brand', 'vars'));
            $titleBarBg = $this->yuceBarBgOrDefault('titlecolor');
            $subtitleBarBg = $this->yuceBarBgOrDefault('yuce_subbar_bg');

            if (! $title) {
                alert_back('标题不能为空！');
            }
            $subtitle = $subtitle === null ? '' : trim((string) $subtitle);
            if ($subtitle !== '' && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $subtitle)) {
                alert_back('容器 ID 须字母开头、仅字母数字下划线，或留空自动生成。');
            }
            $finalSub = $this->finalizeYuceSubtitle($subtitle, $id);
            if ($keywords !== '0' && $keywords !== '1') {
                alert_back('「显示副标题行」请填 0 或 1！');
            }
            $picsJson = YuceBlockView::buildBarFontsJsonFromPost();
            if ($picsJson === null) {
                alert_back('标题字体样式配置过长或无效！');
            }
            $listTags = YuceBlockView::buildListRowTagsFromPost();
            if ($listTags === null) {
                alert_back('接口列表行颜色配置无效！');
            }
            if ($sorting === '' || $sorting === null) {
                $sorting = 255;
            }

            $dateVal = post('date');
            $publishDate = ($dateVal !== null && trim((string) $dateVal) !== '') ? $dateVal : date('Y-m-d H:i:s');

            $data = array(
                'title' => $title,
                'subtitle' => $finalSub,
                'titlecolor' => $titleBarBg,
                'enclosure' => $subtitleBarBg,
                'keywords' => $keywords,
                'description' => $description ?: '',
                'content' => $content ?: '',
                'sorting' => (int) $sorting,
                'status' => (string) $status,
                'outlink' => $outlink !== null ? $outlink : '',
                'source' => $yuceBrand,
                'pics' => $picsJson,
                'tags' => $listTags,
                'date' => $publishDate,
                'update_user' => session('username')
            );

            if ($this->contentModel->modContent($id, $data)) {
                $this->log('开奖预测区块修改内容 id=' . $id);
                success('修改已保存！', url('/admin/YuceBlock/index'));
            }
            location(- 1);
        }

        $row = $this->contentModel->getContent($id);
        $this->assign('mod', true);
        $this->assign('row', $row);
        $this->assignYuceBarFontForm(isset($row->pics) ? $row->pics : '');
        $this->assignYuceListRowForm(isset($row->tags) ? $row->tags : '');
        $this->assign('sort', $sort);
        $this->assign('scode', $cfg['scode']);
        $this->display('content/yuceblock_edit.html');
    }

    public function del()
    {
        if (! $id = get('id', 'int')) {
            error('参数错误！', - 1);
        }
        $this->assertYuceContent($id);
        if ($this->contentModel->delContent($id)) {
            $this->contentModel->delContentExt($id);
            $this->log('开奖预测区块删除 id=' . $id);
            success('删除成功！', - 1);
        }
        error('删除失败！', - 1);
    }

    /**
     * 批量保存排序
     */
    public function saveSort()
    {
        if (! $_POST) {
            location(url('/admin/YuceBlock/index'));
        }
        $listall = post('listall');
        $sorting = post('sorting');
        $scode = $this->effectiveScode();
        if (! $listall || ! is_array($listall)) {
            alert_back('无数据！');
        }
        foreach ($listall as $key => $value) {
            $cid = (int) $value;
            if (! $cid) {
                continue;
            }
            $this->assertYuceContent($cid);
            $s = isset($sorting[$key]) ? $sorting[$key] : 255;
            if ($s === '' || ! is_numeric($s)) {
                $s = 255;
            }
            $this->contentModel->modContent($cid, array(
                'sorting' => (int) $s
            ));
        }
        $this->log('开奖预测区块批量排序');
        success('排序已保存！', - 1);
    }
}
