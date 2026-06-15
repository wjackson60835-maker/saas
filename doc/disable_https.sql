-- =============================================================================
-- PbootCMS：关闭「全站强制 HTTPS」（不再把 http 访问 301/302 到 https）
--
-- 程序读取项：Config to_https（0=禁用，1=启用），表一般为 ay_config（表前缀按实际修改）
-- 改库后若仍跳转：删除 runtime/config 目录下自动生成的 *.php 配置缓存，或进后台保存一次「系统配置」
-- =============================================================================

UPDATE `ay_config`
SET `value` = '0'
WHERE `name` = 'to_https'
LIMIT 1;

-- 若上面影响行数为 0（库里还没有该配置名），可执行插入（id 自增时可去掉 id）：
-- INSERT INTO `ay_config` (`name`, `value`, `type`, `sorting`, `description`)
-- VALUES ('to_https', '0', '1', '255', 'HTTPS强制跳转');

-- 邮件 SMTP 的「安全连接」是另一项，与整站 https 无关；不要误关 unless 你需要：
-- UPDATE `ay_config` SET `value`='0' WHERE `name`='smtp_ssl' LIMIT 1;
