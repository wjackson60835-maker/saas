-- 仅当 ay_content 表里没有 tags 列时执行（先 SHOW COLUMNS FROM `ay_content` LIKE 'tags'; 确认无结果）
-- 标准 PbootCMS 一般已有该列，勿重复执行，否则会报 Duplicate column

ALTER TABLE `ay_content` ADD COLUMN `tags` varchar(500) NOT NULL DEFAULT '' COMMENT 'tag关键字' AFTER `content`;
