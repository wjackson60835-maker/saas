-- 数据收集与统计模块 SQL（MySQL 5.7+）
-- 执行前请确认已选择正确数据库

CREATE TABLE IF NOT EXISTS `collect_passkeys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key_name` varchar(50) NOT NULL DEFAULT '',
  `pass_hash` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `collect_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `collect_block_windows` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `start_time` char(5) NOT NULL,
  `end_time` char(5) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `collect_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `period_no` varchar(20) NOT NULL DEFAULT '',
  `pass_id` bigint(20) unsigned NOT NULL,
  `raw_text` text NOT NULL,
  `parsed_json` longtext,
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_items` int(11) NOT NULL DEFAULT '0',
  `client_ip` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_period_no` (`period_no`),
  KEY `idx_pass_id` (`pass_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `collect_submission_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint(20) unsigned NOT NULL,
  `period_no` varchar(20) NOT NULL DEFAULT '',
  `num` varchar(32) NOT NULL COMMENT '提交号码原样，可为多位数字',
  `tail` varchar(8) NOT NULL COMMENT '号码末位（尾数统计用）',
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `is_special` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=特号（当前规则：该次提交每条均为特号）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_submission_id` (`submission_id`),
  KEY `idx_period_num` (`period_no`,`num`),
  KEY `idx_tail` (`tail`),
  KEY `idx_is_special` (`is_special`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初始设置：开启提交
INSERT INTO `collect_settings` (`setting_key`, `setting_value`)
VALUES ('submit_enabled', '1')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- 示例禁提时段：21:20-21:50（按需保留或删除）
-- INSERT INTO `collect_block_windows` (`start_time`, `end_time`, `enabled`) VALUES ('21:20', '21:50', 1);

-- 示例密码（明文临时值，建议通过后台或脚本改为 password_hash 结果）
-- 明文兼容：当前接口支持 pass_hash 直接等于输入密码
INSERT INTO `collect_passkeys` (`key_name`, `pass_hash`, `status`)
VALUES ('默认渠道', '123456', 1);

