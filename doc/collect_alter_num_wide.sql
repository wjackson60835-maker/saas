-- 已有库升级：放宽 collect_submission_items.num，支持多位数字号码（与 ajax/collect/api.php 一致，最多 32 位）
-- 执行一次即可；若已是 varchar 可跳过 num/tail 的 MODIFY

ALTER TABLE `collect_submission_items`
  MODIFY COLUMN `num` varchar(32) NOT NULL COMMENT '提交号码原样，可为多位数字';

ALTER TABLE `collect_submission_items`
  MODIFY COLUMN `tail` varchar(8) NOT NULL COMMENT '号码末位（尾数统计用）';
