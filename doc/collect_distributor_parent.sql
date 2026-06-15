-- 代理无限层级：在 collect_distributors 增加 parent_id（0=渠道下顶级，与 collect_passkeys.id 同级）
-- 在已执行 collect_distributor_purge.sql 的基础上执行；执行前请备份

ALTER TABLE `collect_distributors`
  ADD COLUMN `parent_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '上级代理 id，0 表示直属渠道下顶级' AFTER `pass_id`,
  ADD KEY `idx_distributor_parent` (`pass_id`, `parent_id`);

-- 原 (pass_id, name) 全局唯一；改为同一上级下名称唯一（不同分支可重名若需再调）
ALTER TABLE `collect_distributors` DROP INDEX `uniq_pass_name`;
ALTER TABLE `collect_distributors` ADD UNIQUE KEY `uniq_pass_parent_name` (`pass_id`, `parent_id`, `name`);

-- 若提示 Duplicate entry：说明已有同名同父记录，先改名后再执行 DROP/ADD 索引段。
