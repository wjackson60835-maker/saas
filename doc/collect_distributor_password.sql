-- 下级代理登录密码（bcrypt 哈希，不存明文）
-- 前置：collect_distributors 表已存在（通常已执行 doc/collect_distributor_purge.sql）。
-- 说明：执行后 ajax/collect 会识别 pass_hash 列，提交页才支持「分销 #编号 / 渠道@代理名」等代理密码登录。
-- 顺序建议：purge（含本列的新版第 3 段）→ 可选 parent.sql → 若 purge 为旧版未含 pass_hash，再单独执行本文件。
-- 执行前请备份。

ALTER TABLE `collect_distributors`
  ADD COLUMN `pass_hash` varchar(255) NOT NULL DEFAULT '' COMMENT '代理独立登录密码哈希，空表示未启用（旧数据）' AFTER `name`;

-- 若提示 Duplicate column name 'pass_hash'，则已加过，可忽略。
