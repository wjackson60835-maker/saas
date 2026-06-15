-- =============================================================================
-- 已有库升级：为 collect_submission_items 增加「特号」标记 is_special
-- =============================================================================
--
-- 若 phpMyAdmin 报错：#1060 Duplicate column name 'is_special'
-- 表示该列已经加过了，升级已完成，不必再执行任何 ADD COLUMN，可关闭本页。
--
-- 下面脚本可重复执行：列/索引已存在时会自动跳过，不会报错。
-- =============================================================================

-- 1) 列不存在时才 ADD COLUMN
SET @collect_alter_col := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'collect_submission_items'
        AND COLUMN_NAME = 'is_special'
    ),
    'SELECT ''is_special 列已存在，跳过'' AS collect_upgrade_msg',
    'ALTER TABLE `collect_submission_items` ADD COLUMN `is_special` tinyint(1) NOT NULL DEFAULT ''0'' COMMENT ''1=特号（当前规则：该次提交每条均为特号）'' AFTER `amount`'
  )
);
PREPARE collect_stmt_col FROM @collect_alter_col;
EXECUTE collect_stmt_col;
DEALLOCATE PREPARE collect_stmt_col;

-- 2) 索引不存在时才 ADD KEY（需先有 is_special 列）
SET @collect_alter_idx := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'collect_submission_items'
        AND INDEX_NAME = 'idx_is_special'
    ),
    'SELECT ''idx_is_special 索引已存在，跳过'' AS collect_upgrade_msg',
    'ALTER TABLE `collect_submission_items` ADD KEY `idx_is_special` (`is_special`)'
  )
);
PREPARE collect_stmt_idx FROM @collect_alter_idx;
EXECUTE collect_stmt_idx;
DEALLOCATE PREPARE collect_stmt_idx;
