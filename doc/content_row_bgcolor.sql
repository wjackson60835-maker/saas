-- 内容列表：每条可单独设「列表行背景色」（#rgb/#rrggbb），与「标题颜色」一起用于 [list:titlelinebg] / [list:titlefontcolor]
-- 标题中 ★ 符号单独颜色见 content_title_star_color.sql，模板用 [list:titlestars]。
-- 表前缀若为自定义请修改表名。执行一次即可。

ALTER TABLE `ay_content` ADD COLUMN `row_bgcolor` varchar(7) NOT NULL DEFAULT '' COMMENT '列表行背景色#hex' AFTER `titlecolor`;
