<?php
/**
 * 开奖预测区块：PHP 从 ay_content 拉取列表并输出 HTML（后台增删改排序即生效）
 * 主标题黄色前缀：本条 ay_content.source（后台「主标题黄字」）→ 栏目 def1 → config brand → 默认「快乐天天彩」
 * （旧数据 source 为「本站」等占位时视为未单独设置，走栏目/配置默认）
 * 横条背景（每条独立）：ay_content.titlecolor=主标题条、enclosure=副标题条（#rgb/#rrggbb；旧默认 #333333 视为未设置走默认蓝）
 * 主标题条内字体（每条独立）：ay_content.pics 存 JSON {"b":{ff,fs,fc,fw},"t":{...}}，b=黄字前缀、t=绿字标题（特区块专用，勿当多图用）
 * 接口开奖列表行样式（每条独立）：ay_content.tags 存 JSON {"lf":"#文字色","lb":"#背景色"}，仅本区块使用；留空则沿用模板默认三色格样式
 */
namespace app\home\model;

use app\home\controller\ParserController;

class YuceBlockView
{

    const DEFAULT_BAR_BG = '#2196F3';

    /**
     * 十六进制背景色校验（防 CSS 注入）。
     *
     * @param mixed $raw
     * @return string 合法则返回 #rgb/#rrggbb，否则空串
     */
    public static function sanitizeBarBg($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $raw)) {
            return $raw;
        }
        return '';
    }

    /**
     * @return array{scode:string,defaultBrand:string}
     */
    private static function getPredictContext()
    {
        $cfg = array();
        $cfgFile = CONF_PATH . '/yuce_block.php';
        if (is_file($cfgFile)) {
            $loaded = include $cfgFile;
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        }
        $scode = ! empty($cfg['scode']) ? (string) $cfg['scode'] : '365';
        $brand = '';
        $sort = (new ParserModel())->getSort($scode);
        if ($sort && isset($sort->def1)) {
            $brand = trim((string) $sort->def1);
        }
        if ($brand === '' && ! empty($cfg['brand'])) {
            $brand = trim((string) $cfg['brand']);
        }
        if ($brand === '') {
            $brand = '快乐天天彩';
        }
        return array(
            'scode' => $scode,
            'defaultBrand' => $brand
        );
    }

    /**
     * @return array 0=>scode, 1=>defaultBrand 文案
     */
    private static function getPredictScodeAndBrand()
    {
        $c = self::getPredictContext();
        return array($c['scode'], $c['defaultBrand']);
    }

    /** 本条主标题横条背景（titlecolor；旧占位 #333333 视为默认蓝） */
    public static function resolvedTitleBarBg($row)
    {
        $raw = isset($row->titlecolor) ? trim((string) $row->titlecolor) : '';
        if ($raw === '' || strcasecmp($raw, '#333333') === 0) {
            return self::DEFAULT_BAR_BG;
        }
        $v = self::sanitizeBarBg($raw);
        return $v !== '' ? $v : self::DEFAULT_BAR_BG;
    }

    /** 本条副标题横条背景（enclosure 仅存色值） */
    public static function resolvedSubtitleBarBg($row)
    {
        $v = self::sanitizeBarBg(isset($row->enclosure) ? $row->enclosure : '');
        return $v !== '' ? $v : self::DEFAULT_BAR_BG;
    }

    /** 字体族入库前清洗（允许中文、逗号分隔多字体） */
    public static function sanitizeFontFamilyStorage($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $raw = preg_replace('/[^\p{L}\p{N}\s,\-_]/u', '', $raw);
        if (mb_strlen($raw, 'UTF-8') > 100) {
            return mb_substr($raw, 0, 100, 'UTF-8');
        }
        return $raw;
    }

    /** 字号，如 18px / 1.2rem */
    public static function sanitizeFontSize($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '18px';
        }
        if (preg_match('/^\d{1,3}(\.\d{1,2})?(px|pt|rem|em|%)$/i', $raw)) {
            return strtolower($raw);
        }
        return '18px';
    }

    /** 字重：normal / bold / 100–900 */
    public static function sanitizeFontWeight($raw)
    {
        $raw = strtolower(trim((string) $raw));
        if ($raw === '' || $raw === 'bold') {
            return '700';
        }
        if ($raw === 'normal') {
            return '400';
        }
        if (preg_match('/^[1-9]00$/', $raw)) {
            return $raw;
        }
        return '700';
    }

    /**
     * 从 pics JSON 解析黄字/绿字样式，缺省与旧版 <font size=4> 观感一致。
     *
     * @return array{b:array{ff:string,fs:string,fc:string,fw:string},t:array{ff:string,fs:string,fc:string,fw:string}}
     */
    public static function parseBarFontsFromPics($row)
    {
        $defaults = array(
            'b' => array(
                'ff' => '微软雅黑',
                'fs' => '18px',
                'fc' => '#faff00',
                'fw' => '700'
            ),
            't' => array(
                'ff' => '微软雅黑',
                'fs' => '18px',
                'fc' => '#c5ffda',
                'fw' => '700'
            )
        );
        $raw = isset($row->pics) ? trim((string) $row->pics) : '';
        if ($raw !== '' && $raw[0] === '{') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                foreach (array(
                    'b',
                    't'
                ) as $k) {
                    if (empty($j[$k]) || ! is_array($j[$k])) {
                        continue;
                    }
                    $x = $j[$k];
                    if (! empty($x['ff'])) {
                        $s = self::sanitizeFontFamilyStorage($x['ff']);
                        if ($s !== '') {
                            $defaults[$k]['ff'] = $s;
                        }
                    }
                    if (! empty($x['fs'])) {
                        $defaults[$k]['fs'] = self::sanitizeFontSize($x['fs']);
                    }
                    if (! empty($x['fc'])) {
                        $c = self::sanitizeBarBg($x['fc']);
                        if ($c !== '') {
                            $defaults[$k]['fc'] = $c;
                        }
                    }
                    if (isset($x['fw']) && $x['fw'] !== '') {
                        $defaults[$k]['fw'] = self::sanitizeFontWeight($x['fw']);
                    }
                }
            }
        }
        return $defaults;
    }

    private static function fontFamilyToCssValue($ff)
    {
        $ff = trim((string) $ff);
        if ($ff === '') {
            return 'Microsoft YaHei,微软雅黑,sans-serif';
        }
        if (strpos($ff, ',') !== false) {
            return $ff;
        }
        return $ff . ',Microsoft YaHei,微软雅黑,sans-serif';
    }

    /**
     * @return array{brand:string,title:string} 已 htmlspecialchars，可直接写入 style=""
     */
    public static function barFontStyleAttrs($row)
    {
        $f = self::parseBarFontsFromPics($row);
        $b = $f['b'];
        $t = $f['t'];
        $bCss = 'font-family:' . self::fontFamilyToCssValue($b['ff']) . ';font-size:' . $b['fs'] . ';color:' . $b['fc'] . ';font-weight:' . $b['fw'];
        $tCss = 'font-family:' . self::fontFamilyToCssValue($t['ff']) . ';font-size:' . $t['fs'] . ';color:' . $t['fc'] . ';font-weight:' . $t['fw'];
        return array(
            'brand' => htmlspecialchars($bCss, ENT_QUOTES, 'UTF-8'),
            'title' => htmlspecialchars($tCss, ENT_QUOTES, 'UTF-8')
        );
    }

    /** 后台表单默认值（平面键名） */
    public static function barFontFormDefaults($picsRaw)
    {
        $row = (object) array(
            'pics' => $picsRaw
        );
        $f = self::parseBarFontsFromPics($row);
        return array(
            'yuce_font_b_ff' => $f['b']['ff'],
            'yuce_font_b_fs' => $f['b']['fs'],
            'yuce_font_b_fc' => $f['b']['fc'],
            'yuce_font_b_fw' => $f['b']['fw'] === '700' ? 'bold' : ($f['b']['fw'] === '400' ? 'normal' : $f['b']['fw']),
            'yuce_font_t_ff' => $f['t']['ff'],
            'yuce_font_t_fs' => $f['t']['fs'],
            'yuce_font_t_fc' => $f['t']['fc'],
            'yuce_font_t_fw' => $f['t']['fw'] === '700' ? 'bold' : ($f['t']['fw'] === '400' ? 'normal' : $f['t']['fw'])
        );
    }

    /** 从 POST 生成 pics JSON */
    public static function buildBarFontsJsonFromPost()
    {
        $one = function ($p) {
            return array(
                'ff' => self::sanitizeFontFamilyStorage(post($p . '_ff')),
                'fs' => self::sanitizeFontSize(post($p . '_fs')),
                'fc' => self::sanitizeBarBg(post($p . '_fc')),
                'fw' => self::sanitizeFontWeight(post($p . '_fw'))
            );
        };
        $b = $one('yuce_font_b');
        $t = $one('yuce_font_t');
        if ($b['fc'] === '') {
            $b['fc'] = '#faff00';
        }
        if ($t['fc'] === '') {
            $t['fc'] = '#c5ffda';
        }
        if ($b['ff'] === '') {
            $b['ff'] = '微软雅黑';
        }
        if ($t['ff'] === '') {
            $t['ff'] = '微软雅黑';
        }
        $j = json_encode(array(
            'b' => $b,
            't' => $t
        ), JSON_UNESCAPED_UNICODE);
        if (strlen($j) > 950) {
            return null;
        }
        return $j;
    }

    /**
     * 解析 tags 中接口列表行颜色（lf=字色 lb=背景，#rgb/#rrggbb）。
     *
     * @return array{fc:string,bg:string} 已校验的色值，空串表示不覆盖前台默认
     */
    public static function parseListRowStyleFromTags($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw[0] !== '{') {
            return array(
                'fc' => '',
                'bg' => ''
            );
        }
        $j = json_decode($raw, true);
        if (! is_array($j)) {
            return array(
                'fc' => '',
                'bg' => ''
            );
        }
        $fc = '';
        $bg = '';
        if (! empty($j['lf'])) {
            $c = self::sanitizeBarBg($j['lf']);
            if ($c !== '') {
                $fc = $c;
            }
        }
        if (! empty($j['lb'])) {
            $c = self::sanitizeBarBg($j['lb']);
            if ($c !== '') {
                $bg = $c;
            }
        }
        return array(
            'fc' => $fc,
            'bg' => $bg
        );
    }

    /**
     * 后台 POST → 写入 ay_content.tags（仅列表行色；空则清空）
     *
     * @return string|null 合法 JSON 或空串；过长则 null
     */
    public static function buildListRowTagsFromPost()
    {
        $fc = self::sanitizeBarBg(post('yuce_list_fc'));
        $bg = self::sanitizeBarBg(post('yuce_list_bg'));
        if ($fc === '' && $bg === '') {
            return '';
        }
        $obj = array();
        if ($fc !== '') {
            $obj['lf'] = $fc;
        }
        if ($bg !== '') {
            $obj['lb'] = $bg;
        }
        $j = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($j === false || strlen($j) > 200) {
            return null;
        }
        return $j;
    }

    /**
     * data-* 属性用（已转义）
     *
     * @return array{fcEsc:string,bgEsc:string}
     */
    public static function listRowDataAttrsForHtml($row)
    {
        $st = self::parseListRowStyleFromTags(isset($row->tags) ? $row->tags : '');
        return array(
            'fcEsc' => $st['fc'] !== '' ? htmlspecialchars($st['fc'], ENT_QUOTES, 'UTF-8') : '',
            'bgEsc' => $st['bg'] !== '' ? htmlspecialchars($st['bg'], ENT_QUOTES, 'UTF-8') : ''
        );
    }

    /**
     * 详情页扩展标签：列表行字色 / 背景（无设置则空串）
     */
    public static function detailListRowStylePlaceholders($row)
    {
        $st = self::parseListRowStyleFromTags(isset($row->tags) ? $row->tags : '');
        return array(
            'fc' => $st['fc'] !== '' ? htmlspecialchars($st['fc'], ENT_QUOTES, 'UTF-8') : '',
            'bg' => $st['bg'] !== '' ? htmlspecialchars($st['bg'], ENT_QUOTES, 'UTF-8') : ''
        );
    }

    /**
     * 详情页底部：同栏目下最新 N 条（排除当前篇），链到详情或外链。
     *
     * @param int $excludeContentId 当前 ay_content.id
     * @param int $limit 条数，默认 10
     * @return string
     */
    public static function renderRecommendHtml($excludeContentId, $limit = 10)
    {
        $excludeContentId = (int) $excludeContentId;
        $limit = max(1, min(50, (int) $limit));
        list($scode, $defaultBrand) = self::getPredictScodeAndBrand();
        $defaultBrandEsc = htmlspecialchars($defaultBrand, ENT_QUOTES, 'UTF-8');

        $parserModel = new ParserModel();
        $order = 'a.date DESC,a.id DESC';
        $fetchNum = $limit * 4 + 20;
        $rows = $parserModel->getList($scode, $fetchNum, $order, array(), array(), array(), true, 1, 'title,source,outlink,pics', get_lg());
        if (! $rows) {
            return '';
        }

        $linker = new ParserController();
        $out = array();
        foreach ($rows as $row) {
            if ((int) $row->id === $excludeContentId) {
                continue;
            }
            if (count($out) >= $limit) {
                break;
            }
            $out[] = $row;
        }
        if (! $out) {
            return '';
        }

        ob_start();
        echo '<div class="yuce-d-rec box" style="padding:8px;margin:12px 0 24px;">';
        echo '<div class="yuce-d-rec-hd" style="font-size:16px;font-weight:bold;color:#1565c0;padding:8px 4px;border-bottom:2px solid #2196F3;font-family:\'Microsoft YaHei\',sans-serif;">更多预测玩法（最新）</div>';
        echo '<ul class="yuce-d-rec-list" style="list-style:none;margin:0;padding:0;font-family:\'Microsoft YaHei\',sans-serif;">';
        foreach ($out as $row) {
            $title = htmlspecialchars($row->title, ENT_QUOTES, 'UTF-8');
            $rowBrand = trim((string) $row->source);
            if ($rowBrand === '' || $rowBrand === '本站') {
                $brandEsc = $defaultBrandEsc;
            } else {
                $brandEsc = htmlspecialchars($rowBrand, ENT_QUOTES, 'UTF-8');
            }
            $barStyle = 'text-decoration:none;color:inherit;display:block;padding:10px 8px;border-bottom:1px solid #eee;';
            $hasOutlink = trim((string) ($row->outlink ?? '')) !== '';
            echo '<li style="background:#fff;">';
            if ($hasOutlink) {
                $href = htmlspecialchars((string) $row->outlink, ENT_QUOTES, 'UTF-8');
                echo '<a target="_blank" rel="noopener" href="' . $href . '" style="' . $barStyle . '">';
            } elseif (isset($row->type, $row->urlname, $row->scode, $row->id)) {
                $href = htmlspecialchars(
                    $linker->parserLink(
                        (int) $row->type,
                        (string) $row->urlname,
                        'content',
                        (string) $row->scode,
                        isset($row->sortfilename) ? (string) $row->sortfilename : '',
                        (string) $row->id,
                        isset($row->filename) ? (string) $row->filename : ''
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                );
                echo '<a href="' . $href . '" style="' . $barStyle . '">';
            } else {
                echo '<span style="' . $barStyle . 'cursor:default;">';
            }
            $fontSt = self::barFontStyleAttrs($row);
            echo '<span style="' . $fontSt['brand'] . '">' . $brandEsc . '</span>';
            echo '<span style="' . $fontSt['title'] . ';margin-left:6px;">' . $title . '</span>';
            if ($hasOutlink || (isset($row->type, $row->urlname, $row->scode, $row->id))) {
                echo '</a>';
            } else {
                echo '</span>';
            }
            echo '</li>';
        }
        echo '</ul></div>';
        return ob_get_clean();
    }

    /**
     * 新闻/文章详情页（如 news.html）：当前栏目下最新 N 条，排除本篇，可点击跳转。
     *
     * @param string|int $scode 栏目 scode
     * @param int $excludeContentId 当前 ay_content.id
     * @param int $limit 条数，默认 12，最大 30
     * @return string
     */
    public static function renderSameSortRecommendHtml($scode, $excludeContentId, $limit = 12)
    {
        $scode = (string) (int) $scode;
        $excludeContentId = (int) $excludeContentId;
        if ($scode === '0') {
            return '';
        }
        $limit = max(1, min(30, (int) $limit));
        $parserModel = new ParserModel();
        $order = 'a.date DESC,a.id DESC';
        $fetchNum = $limit * 4 + 15;
        $rows = $parserModel->getList($scode, $fetchNum, $order, array(), array(), array(), true, 1, 'title,outlink,date', get_lg());
        if (! $rows) {
            return '';
        }
        $linker = new ParserController();
        $out = array();
        foreach ($rows as $row) {
            if ((int) $row->id === $excludeContentId) {
                continue;
            }
            if (count($out) >= $limit) {
                break;
            }
            $out[] = $row;
        }
        if (! $out) {
            return '';
        }
        $rowStyle = 'display:flex;align-items:center;justify-content:space-between;text-decoration:none;color:#222;padding:12px 10px;border-bottom:1px solid #eee;font-size:15px;gap:10px;';
        ob_start();
        echo '<div class="news-rec-wrap box" style="margin:10px 8px;padding:0;background:transparent;border:none;box-shadow:none;">';
        echo '<div class="news-rec-hd" style="font-size:16px;font-weight:bold;color:#1565c0;padding:10px 8px;background:#fff;border:1px solid #ddd;border-radius:6px 6px 0 0;border-bottom:2px solid #2196F3;font-family:\'Microsoft YaHei\',sans-serif;">最新相关帖子推荐</div>';
        echo '<ul class="news-rec-list" style="list-style:none;margin:0;padding:0;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 6px 6px;overflow:hidden;font-family:\'Microsoft YaHei\',sans-serif;">';
        foreach ($out as $row) {
            $title = htmlspecialchars($row->title, ENT_QUOTES, 'UTF-8');
            $dateStr = '';
            if (! empty($row->date)) {
                $ts = strtotime($row->date);
                $dateStr = $ts ? date('Y-m-d', $ts) : '';
            }
            $dateStrEsc = htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8');
            echo '<li style="margin:0;padding:0;">';
            $hasOutlink = trim((string) ($row->outlink ?? '')) !== '';
            if ($hasOutlink) {
                $href = htmlspecialchars((string) $row->outlink, ENT_QUOTES, 'UTF-8');
                echo '<a target="_blank" rel="noopener" href="' . $href . '" style="' . $rowStyle . '">';
            } elseif (isset($row->type, $row->urlname, $row->scode, $row->id)) {
                $href = htmlspecialchars(
                    $linker->parserLink(
                        (int) $row->type,
                        (string) $row->urlname,
                        'content',
                        (string) $row->scode,
                        isset($row->sortfilename) ? (string) $row->sortfilename : '',
                        (string) $row->id,
                        isset($row->filename) ? (string) $row->filename : ''
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                );
                echo '<a href="' . $href . '" style="' . $rowStyle . '">';
            } else {
                echo '<span style="' . $rowStyle . 'cursor:default;color:#666;">';
            }
            echo '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:bold;">' . $title . '</span>';
            if ($dateStrEsc !== '') {
                echo '<span style="flex-shrink:0;font-size:12px;color:#888;">' . $dateStrEsc . '</span>';
            }
            if ($hasOutlink || (isset($row->type, $row->urlname, $row->scode, $row->id))) {
                echo '</a>';
            } else {
                echo '</span>';
            }
            echo '</li>';
        }
        echo '</ul></div>';
        return ob_get_clean();
    }

    /**
     * @return string
     */
    public static function renderBlocks()
    {
        $ctx = self::getPredictContext();
        $scode = $ctx['scode'];
        $brand = $ctx['defaultBrand'];
        $defaultBrandEsc = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');

        $parserModel = new ParserModel();

        $order = 'a.sorting ASC,a.id ASC';
        $rows = $parserModel->getList($scode, 500, $order, array(), array(), array(), true, 1, 'title,subtitle,keywords,description,source,titlecolor,enclosure,pics,tags', get_lg());

        if (! $rows) {
            return '';
        }

        $linker = new ParserController();

        ob_start();
        foreach ($rows as $row) {
            $titleBarBg = htmlspecialchars(self::resolvedTitleBarBg($row), ENT_QUOTES, 'UTF-8');
            $subtitleBarBg = htmlspecialchars(self::resolvedSubtitleBarBg($row), ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars($row->title, ENT_QUOTES, 'UTF-8');
            $sub = trim((string) $row->subtitle);
            if ($sub !== '' && preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $sub)) {
                $subId = $sub;
            } else {
                $subId = 'yuce_blk_' . (int) $row->id;
            }
            $hasSubRow = (trim((string) $row->keywords) === '1');

            $rowBrand = trim((string) $row->source);
            if ($rowBrand === '' || $rowBrand === '本站') {
                $brandEsc = $defaultBrandEsc;
            } else {
                $brandEsc = htmlspecialchars($rowBrand, ENT_QUOTES, 'UTF-8');
            }

            $fontSt = self::barFontStyleAttrs($row);
            $barInner = '<span style="' . $fontSt['brand'] . '">' . $brandEsc . '</span>'
                . '<span style="' . $fontSt['title'] . ';margin-left:4px;">' . $title . '</span>';
            $barStyle = 'text-decoration:none;color:inherit;display:block;width:100%;';

            $detailHref = '';
            $hasOutlink = trim((string) ($row->outlink ?? '')) !== '';
            if (! $hasOutlink && isset($row->type, $row->urlname, $row->scode, $row->id)) {
                $detailHref = $linker->parserLink(
                    (int) $row->type,
                    (string) $row->urlname,
                    'content',
                    (string) $row->scode,
                    isset($row->sortfilename) ? (string) $row->sortfilename : '',
                    (string) $row->id,
                    isset($row->filename) ? (string) $row->filename : ''
                );
            }

            echo ' <table style="border-collapse: collapse" width="100%" height="40">';
            echo '<tbody><tr><td align="center" style="background:' . $titleBarBg . ';">';
            if ($hasOutlink) {
                $href = htmlspecialchars((string) $row->outlink, ENT_QUOTES, 'UTF-8');
                echo '<a target="_blank" rel="noopener" href="' . $href . '" style="' . $barStyle . '">' . $barInner . '</a>';
            } elseif ($detailHref !== '') {
                $href = htmlspecialchars($detailHref, ENT_QUOTES, 'UTF-8');
                echo '<a href="' . $href . '" style="' . $barStyle . '">' . $barInner . '</a>';
            } else {
                echo '<span style="' . $barStyle . 'cursor:default;">' . $barInner . '</span>';
            }
            echo '</td></tr></tbody></table>';

            if ($hasSubRow && isset($row->description) && $row->description !== '') {
                $subRowTd = 'background:' . $subtitleBarBg . ';padding:8px 6px;text-align:center;'
                    . 'max-width:100%;box-sizing:border-box;word-wrap:break-word;overflow-wrap:break-word;';
                echo ' <table style="border-collapse:collapse;width:100%;table-layout:fixed" role="presentation"><tbody><tr><td style="' . $subRowTd . '">';
                echo '<div style="max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;box-sizing:border-box;">';
                echo $row->description;
                echo '</div></td></tr></tbody></table>';
            }

            $listDa = self::listRowDataAttrsForHtml($row);
            $dfa = $listDa['fcEsc'] !== '' ? ' data-list-fc="' . $listDa['fcEsc'] . '"' : '';
            $dba = $listDa['bgEsc'] !== '' ? ' data-list-bg="' . $listDa['bgEsc'] . '"' : '';
            echo '<div class="content">';
            echo '<table class="toushu"><tbody id="' . htmlspecialchars($subId, ENT_QUOTES, 'UTF-8') . '"' . $dfa . $dba . '></tbody></table>';
            echo '<div class="contentdi"></div>';
            echo '</div>';
        }
        return ob_get_clean();
    }
}
