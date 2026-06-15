-- =============================================================================
-- 后台左侧：「开奖预测区块」— 管理前台「快乐天天彩」预测条（yuce.html / YuceBlock）
-- =============================================================================
-- 说明：本主题已在 apps/admin/view/default/common/head.html「开奖管理」下增加固定菜单项；
--       且 apps/common/AdminController.php 中：拥有「数据采集」或「私彩」权限即可访问 YuceBlock。
--       下列 SQL 仍可用于角色管理/数据菜单树同步；非必跑也能从侧栏进入。
-- 在 phpMyAdmin 执行本文件全文 → 后台「清除缓存」→ 退出并重新登录。
--
-- 若 mcode M199 已被占用：全文替换 M199 为未占用编码（如 M298）。
-- =============================================================================

DELETE FROM `ay_menu_action` WHERE `mcode` = 'M199';
DELETE FROM `ay_role_level` WHERE `level` LIKE '/admin/YuceBlock%';
DELETE FROM `ay_menu` WHERE `mcode` = 'M199';

-- 插入菜单：父级 pcode 优先与「数据采集」「私彩」同级（即开奖管理下的 mcode）
-- 名称在库里可能略有差异，故用 LIKE；仍找不到则挂到根 pcode=0（至少能看见，再在 系统→菜单管理 里拖到「开奖管理」下）
INSERT INTO `ay_menu` (`mcode`,`pcode`,`name`,`url`,`sorting`,`status`,`shortcut`,`ico`,`create_user`,`update_user`,`create_time`,`update_time`)
SELECT
  'M199',
  COALESCE(
    (SELECT `pcode` FROM `ay_menu` WHERE `name` LIKE '%数据采集%' ORDER BY `id` ASC LIMIT 1),
    (SELECT `pcode` FROM `ay_menu` WHERE `name` LIKE '%私彩%' ORDER BY `id` ASC LIMIT 1),
    (SELECT `m`.`mcode` FROM `ay_menu` `m` WHERE `m`.`name` LIKE '%开奖管理%' ORDER BY `m`.`id` ASC LIMIT 1),
    '0'
  ),
  '开奖预测区块',
  '/admin/YuceBlock/index',
  450,
  '1',
  '1',
  'fa-th-list',
  'admin',
  'admin',
  NOW(),
  NOW()
FROM `ay_menu`
LIMIT 1;

INSERT INTO `ay_menu_action` (`mcode`,`action`) VALUES
('M199','index'),
('M199','add'),
('M199','mod'),
('M199','del'),
('M199','saveSettings'),
('M199','saveSort');

-- 默认角色 R101/R102（官方库常见）
INSERT INTO `ay_role_level` (`rcode`,`level`) VALUES
('R101','/admin/YuceBlock/index'),
('R101','/admin/YuceBlock/add'),
('R101','/admin/YuceBlock/mod'),
('R101','/admin/YuceBlock/del'),
('R101','/admin/YuceBlock/saveSettings'),
('R101','/admin/YuceBlock/saveSort'),
('R102','/admin/YuceBlock/index'),
('R102','/admin/YuceBlock/add'),
('R102','/admin/YuceBlock/mod'),
('R102','/admin/YuceBlock/del'),
('R102','/admin/YuceBlock/saveSettings'),
('R102','/admin/YuceBlock/saveSort');

-- 关键：非「超级管理员」账号只看 ay_role_level；给「已有私彩/数据采集权限」的同一批角色补上 YuceBlock（避免菜单插入了但左侧不显示）
INSERT INTO `ay_role_level` (`rcode`, `level`)
SELECT DISTINCT `b`.`rcode`, `b`.`newlevel`
FROM (
  SELECT DISTINCT `rl`.`rcode`, `v`.`lvl` AS `newlevel`
  FROM `ay_role_level` `rl`
  INNER JOIN `ay_menu` `m` ON `m`.`url` = `rl`.`level`
  CROSS JOIN (
    SELECT '/admin/YuceBlock/index' AS `lvl` UNION ALL
    SELECT '/admin/YuceBlock/add' UNION ALL
    SELECT '/admin/YuceBlock/mod' UNION ALL
    SELECT '/admin/YuceBlock/del' UNION ALL
    SELECT '/admin/YuceBlock/saveSettings' UNION ALL
    SELECT '/admin/YuceBlock/saveSort'
  ) `v`
  WHERE `m`.`name` LIKE '%私彩%'
     OR `m`.`name` LIKE '%数据采集%'
     OR `m`.`url` LIKE '%sicai%'
     OR `m`.`url` LIKE '%Member%'
) `b`
WHERE NOT EXISTS (
  SELECT 1 FROM `ay_role_level` `e` WHERE `e`.`rcode` = `b`.`rcode` AND `e`.`level` = `b`.`newlevel`
);

-- =============================================================================
-- 自检（执行后看结果）
--   SELECT * FROM ay_menu WHERE mcode='M199';
--   SELECT rcode, level FROM ay_role_level WHERE level LIKE '/admin/YuceBlock%';
--
-- 仍无菜单时：
--   1) 浏览器打开： admin.php?p=/YuceBlock/index 或 admin.php?p=/admin/YuceBlock/index
--   2) 后台「系统」→「菜单管理」手动新增一项，URL 填 /admin/YuceBlock/index
--   3) 「系统」→「角色管理」给当前角色勾选该菜单权限
-- =============================================================================
