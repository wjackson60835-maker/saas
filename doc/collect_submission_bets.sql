-- 平码玩法明细表（二中二 / 三中三 / 连肖等按组计费）
-- MySQL 5.7+，执行前请选择正确数据库

CREATE TABLE IF NOT EXISTS `collect_submission_bets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint(20) unsigned NOT NULL,
  `period_no` varchar(20) NOT NULL DEFAULT '',
  `play_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'erzhonger/sanzhongsan/yixiao…qixiao',
  `play_label` varchar(32) NOT NULL DEFAULT '' COMMENT '展示名：二中二、三肖等',
  `ball_scope` varchar(16) NOT NULL DEFAULT 'pingma',
  `selection_type` varchar(16) NOT NULL DEFAULT 'number' COMMENT 'number|zodiac',
  `selection_json` text NOT NULL COMMENT '所选号码或生肖 JSON 数组',
  `groups_json` longtext COMMENT '展开组合 JSON',
  `group_count` int(11) NOT NULL DEFAULT '0',
  `amount_per_group` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_mode` varchar(16) NOT NULL DEFAULT 'per_group' COMMENT 'per_group|flat_total',
  `raw_segment` text,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_submission_id` (`submission_id`),
  KEY `idx_period_play` (`period_no`,`play_type`),
  KEY `idx_ball_scope` (`ball_scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
