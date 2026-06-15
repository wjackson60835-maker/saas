-- 内容标题中 ★、☆、*、＊ 的单独字色（#rgb/#rrggbb）。列表模板：[list:titlestars]；详情：{content:titlestars}
-- 列表行背景色见 doc/content_row_bgcolor.sql（字段 row_bgcolor，标签 [list:titlelinebg]）。
-- 表前缀若为自定义请修改表名。
-- 若尚未添加 row_bgcolor，请把下面 AFTER 改为 `titlecolor`。

ALTER TABLE `ay_content` ADD COLUMN `title_star_color` varchar(7) NOT NULL DEFAULT '' COMMENT '标题星号等符号颜色#hex' AFTER `row_bgcolor`;
