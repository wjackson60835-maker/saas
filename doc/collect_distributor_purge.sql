-- 渠道二级分销 + 提交关联 + 每日中午计划任务清理「昨日」数据
-- 执行前请备份数据库；与 collect_module.sql 同一库

-- 1) 分销端（隶属于 collect_passkeys，同一渠道账号下维护多个分销名）
CREATE TABLE IF NOT EXISTS `collect_distributors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pass_id` bigint(20) unsigned NOT NULL COMMENT 'collect_passkeys.id',
  `name` varchar(40) NOT NULL DEFAULT '',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pass_name` (`pass_id`,`name`),
  KEY `idx_pass` (`pass_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='渠道下二级分销端';

-- 2) 提交单关联分销（未执行迁移时接口会降级为无分销字段）
ALTER TABLE `collect_submissions`
  ADD COLUMN `distributor_id` bigint(20) unsigned NULL DEFAULT NULL COMMENT 'collect_distributors.id' AFTER `pass_id`,
  ADD COLUMN `distributor_name` varchar(40) NOT NULL DEFAULT '' COMMENT '冗余展示名' AFTER `distributor_id`,
  ADD KEY `idx_distributor` (`distributor_id`),
  ADD KEY `idx_pass_created` (`pass_id`,`created_at`);

-- 若提示 Duplicate column，说明已执行过，可忽略本段 ALTER。

-- 3) 代理独立登录密码（bcrypt 哈希；与 doc/collect_distributor_password.sql 同义，二选一执行即可）
ALTER TABLE `collect_distributors`
  ADD COLUMN `pass_hash` varchar(255) NOT NULL DEFAULT '' COMMENT '代理独立登录密码哈希，空表示未启用（旧数据）' AFTER `name`;

-- 若提示 Duplicate column name 'pass_hash'，说明本列已存在，可忽略本段。

-- 计划任务（每天北京时间 12:00）：在 config/collect.php 设置 cron_purge_secret 后执行 HTTP GET，例如：
-- curl "https://你的域名/ajax/collect/api.php?action=cron_purge_yesterday&secret=与配置一致"
-- Windows 计划任务：操作「启动程序」program= curl.exe，参数填上行 URL（或 php 脚本内请求）。

-- 无限层级下级代理：在以上表已存在后，另执行 doc/collect_distributor_parent.sql（增加 parent_id 并调整唯一索引）。
-- 代理登录密码：本文件第 3 段已含 pass_hash；若早期库未跑过第 3 段，可单独补执行 doc/collect_distributor_password.sql。
