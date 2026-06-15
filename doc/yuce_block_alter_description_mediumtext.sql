-- 特区块「副行 HTML」使用 ay_content.description 存富文本，默认 varchar(500) 易超长导致保存截断/报错。
-- 在 MySQL 中执行一次（表前缀若为自定义请改 ay_content 表名）。
-- 与 content 字段同为 MEDIUMTEXT，足够存放副行 HTML。

ALTER TABLE `ay_content` MODIFY COLUMN `description` MEDIUMTEXT NOT NULL COMMENT '描述';
