-- =============================================================================
-- PbootCMS 后台：重置为 用户名 admin / 密码 123456 仍提示「用户名或密码错误」时排查
--
-- 程序校验：password 字段须等于 md5(md5('123456'))，即下面常量（32 位小写）
--   14e1b600b1fd579f47433b88e8d85291
-- 且 status = '1'（字符 1），用户名区分大小写取决于库排序规则。
--
-- 务必在「网站 config/database.php 里 dbname 指向的同一个库」执行，否则会改错库。
-- =============================================================================

-- ① 看后台用户表实际叫什么（常见 ay_user；改过前缀则是 xx_user）
SHOW TABLES LIKE '%user';

-- ② 看当前账号、密码长度、状态（确认是否真改到行）
SELECT `id`, `ucode`, `username`, LENGTH(`password`) AS pwd_len, `password`, `status`
FROM `ay_user`
ORDER BY `id` ASC
LIMIT 20;

-- pwd_len 应为 32；password 须与下面 UPDATE 里一致；status 须为字符 1。

-- ③ 优先按「创始人编码」10001 更新（重装、迁移后 id 可能不是 1）
UPDATE `ay_user`
SET
  `username` = 'admin',
  `password` = '14e1b600b1fd579f47433b88e8d85291',
  `realname` = '超级管理员',
  `status` = '1',
  `update_time` = NOW()
WHERE `ucode` = '10001'
LIMIT 1;

-- ④ 若上句影响行数为 0，再按最小 id 改一条（先备份，确认是你自己的后台用户）
UPDATE `ay_user`
SET
  `username` = 'admin',
  `password` = '14e1b600b1fd579f47433b88e8d85291',
  `realname` = '超级管理员',
  `status` = '1',
  `update_time` = NOW()
ORDER BY `id` ASC
LIMIT 1;

-- ⑤ 表名若不是 ay_ 前缀，请全局替换表名，例如 `pb_user`：
-- UPDATE `pb_user` SET ... WHERE `ucode`='10001' LIMIT 1;

-- =============================================================================
-- 仍失败时：验证码若开启会提示「验证码错误」而非本句；若仍是用户名密码错，
-- 多为改错库 / 表前缀 / 或服务器上 config 与本地不一致。可用项目根目录
-- reset_admin_once.php（设密钥后浏览器访问一次，用完删除）自动连库更新。
-- =============================================================================

-- =============================================================================
-- 登录被锁定：「您登录失败次数太多已被锁定，请xxx秒后再试」
-- 与数据库无关。删除服务器上下面这个文件即可立刻解除（FTP/SSH/面板均可）：
--
--   网站根目录/runtime/data/9e77657ecbc72afa6aafe227957ebfd4.php
--
-- 其中 9e77657ecbc72afa6aafe227957ebfd4 = md5('login_black')，与程序 apps/admin/controller/IndexController.php 一致。
-- 也可使用根目录 clear_login_lock_once.php（设密钥访问一次后删除该脚本）。
-- =============================================================================
