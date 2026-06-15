<?php
/**
 * @copyright (C)2020-2099 Hnaoyun Inc.
 * @author XingMeng
 * @email hnxsh@foxmail.com
 * @date 2020年3月8日
 *  个人扩展标签可编写到本类中，升级不会覆盖
 */
namespace app\home\controller;

use app\home\model\YuceBlockView;
use app\home\model\ParserModel;
use core\basic\Controller;

class ExtLabelController
{

    protected $content;

    /* 必备启动函数 */
    public function run($content)
    {
        // 接收数据
        $this->content = $content;
        
        // 执行个人自定义标签函数
        $this->test();
        $this->yuceBarColors();
        $this->yuceRecommend();
        $this->newsRecommend();
        
        // 返回数据
        return $this->content;
    }

    // 测试扩展单个标签
    private function test()
    {
        $this->content = str_replace('{pboot:userip}', get_user_ip(), $this->content);
    }

    /**
     * yuce_detail：顶条背景 + 黄字/绿字内联样式（每条 titlecolor / pics JSON，依赖 yuce-predict-page-cid）
     */
    private function yuceBarColors()
    {
        $tBg = '{pboot:yuce_detail_title_bar_bg}';
        $tBr = '{pboot:yuce_detail_font_brand_style}';
        $tTi = '{pboot:yuce_detail_font_title_style}';
        $tLf = '{pboot:yuce_detail_list_row_fc}';
        $tLb = '{pboot:yuce_detail_list_row_bg}';
        if (strpos($this->content, $tBg) === false && strpos($this->content, $tBr) === false && strpos($this->content, $tTi) === false && strpos($this->content, $tLf) === false && strpos($this->content, $tLb) === false) {
            return;
        }
        $fallback = (object) array(
            'pics' => '',
            'titlecolor' => ''
        );
        if (! preg_match('/<span[^>]*yuce-predict-page-cid[^>]*>\s*(\d+)\s*<\/span>/i', $this->content, $m)) {
            $row = $fallback;
        } else {
            $got = (new ParserModel())->getContent((int) $m[1]);
            $row = $got ? $got : $fallback;
        }
        $bg = htmlspecialchars(YuceBlockView::resolvedTitleBarBg($row), ENT_QUOTES, 'UTF-8');
        $st = YuceBlockView::barFontStyleAttrs($row);
        $this->content = str_replace($tBg, $bg, $this->content);
        $this->content = str_replace($tBr, $st['brand'], $this->content);
        $this->content = str_replace($tTi, $st['title'], $this->content);
        $lst = YuceBlockView::detailListRowStylePlaceholders($row);
        $this->content = str_replace($tLf, $lst['fc'], $this->content);
        $this->content = str_replace($tLb, $lst['bg'], $this->content);
    }

    /**
     * 开奖预测详情页 yuce_detail.html：输出「最新 10 条」推荐（依赖模板内 yuce-predict-page-cid 与 {content:id} 已解析）
     */
    private function yuceRecommend()
    {
        if (strpos($this->content, '{pboot:yuce_recommend}') === false) {
            return;
        }
        // class 可为单独或与其它类共存，且 {content:id} 已在前序步骤替换为数字
        if (! preg_match('/<span[^>]*yuce-predict-page-cid[^>]*>\s*(\d+)\s*<\/span>/i', $this->content, $m)) {
            $this->content = str_replace('{pboot:yuce_recommend}', '', $this->content);
            return;
        }
        $html = YuceBlockView::renderRecommendHtml((int) $m[1], 10);
        $this->content = str_replace('{pboot:yuce_recommend}', $html, $this->content);
    }

    /**
     * 新闻详情 news.html：同栏目推荐列表（依赖 id="news-rec-meta" 上 data-cid / data-scode，已由 {content:id}、{sort:scode} 解析）
     */
    private function newsRecommend()
    {
        if (strpos($this->content, '{pboot:news_recommend}') === false) {
            return;
        }
        if (! preg_match('/<span[^>]+id="news-rec-meta"[^>]*>/i', $this->content, $sm)) {
            $this->content = str_replace('{pboot:news_recommend}', '', $this->content);
            return;
        }
        $tag = $sm[0];
        if (! preg_match('/data-cid="(\d+)"/', $tag, $cid) || ! preg_match('/data-scode="(\d+)"/', $tag, $scode)) {
            $this->content = str_replace('{pboot:news_recommend}', '', $this->content);
            return;
        }
        $html = YuceBlockView::renderSameSortRecommendHtml($scode[1], (int) $cid[1], 12);
        $this->content = str_replace('{pboot:news_recommend}', $html, $this->content);
    }
}