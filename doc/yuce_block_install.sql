-- PbootCMS：开奖预测区块（yuce.html）后台数据
-- 若只需要改表结构、不要下方 INSERT：doc/yuce_block_alter_only.sql（description）；缺 tags 列时另见 doc/yuce_block_alter_add_tags_if_missing.sql。
-- 每条 ay_content：titlecolor=主标题横条背景、enclosure=副标题横条背景（#rgb/#rrggbb）；pics=主标题条内「黄字前缀/绿字标题」字体 JSON（特区块专用，勿当多图）；tags=接口列表行样式 JSON {"lf":"#字色","lb":"#背景"}（仅本区块用，留空则首页/详情三色格默认），在「开奖预测区块 → 添加/修改」中配置。
-- 若库中 ay_content.description 仍为 varchar(500)，副行 HTML 保存易溢出，请执行 doc/yuce_block_alter_description_mediumtext.sql 一次。
-- 执行前确认 scode=365 未被占用；若冲突请修改 ay_content_sort.scode，并同步 config/yuce_block.php 中 'scode'。
-- acode 默认 cn；若站点区域代码不同请全局替换。
-- 栏目使用列表型内容模型 mcode=2（与默认「新闻」相同，可在后台核对）。
-- 主标题黄色前缀：每条内容可在「开奖预测区块」编辑里填「主标题黄字」（ay_content.source）；留空则用栏目描述1（def1）→ config brand → 默认文案。旧数据 source=本站 视为未单独设置。
-- 容器 ID（subtitle）：后台可留空，保存后为 yuce_blk+内容id，在 skin/config.js 的 apiwf 第二参数引用即可无限加条。
-- 后台独立管理页：执行 doc/admin_yuceblock_menu.sql 后，在「文章内容」下进入「开奖预测区块」。

INSERT INTO `ay_content_sort` (`acode`,`mcode`,`pcode`,`scode`,`name`,`listtpl`,`contenttpl`,`status`,`outlink`,`subname`,`def1`,`def2`,`def3`,`ico`,`pic`,`title`,`keywords`,`description`,`filename`,`sorting`,`create_user`,`update_user`,`create_time`,`update_time`,`gtype`,`gid`,`gnote`) VALUES
('cn','2','0','365','开奖预测资料块','','yuce_detail.html','1','','快乐天天彩预测各区块','快乐天天彩','','','','','','','','yuceblk','255','admin','admin',NOW(),NOW(),'4','','');

-- 若栏目已存在，可单独执行：
-- UPDATE ay_content_sort SET def1='快乐天天彩' WHERE scode='365';

INSERT INTO `ay_content` (`acode`,`scode`,`subscode`,`title`,`titlecolor`,`subtitle`,`filename`,`author`,`source`,`outlink`,`date`,`ico`,`pics`,`picstitle`,`content`,`tags`,`enclosure`,`keywords`,`description`,`sorting`,`status`,`istop`,`isrecommend`,`isheadline`,`visits`,`likes`,`oppose`,`create_user`,`update_user`,`create_time`,`update_time`,`gtype`,`gid`,`gnote`) VALUES
('cn','365','','【三头中特】','#333333','toushu_div_tetou','','admin','本站','',NOW(),'','','','<p>后台「内容」中编辑正文，用于详情页说明。</p>','','','0','',10,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【五尾中特】','#333333','weishu_div_tewei','','admin','本站','',NOW(),'','','','<p></p>','','','0','',20,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【平特七字真言】','#333333','zhenyan_ping_xiao','','admin','本站','',NOW(),'','','','<p></p>','','','0','',30,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【平特新词名】','#333333','mingci_ping_xiao','','admin','本站','',NOW(),'','','','<p></p>','','','0','',40,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【平特成语肖】','#333333','chengyu_ping_xiao','','admin','本站','',NOW(),'','','','<p></p>','','','0','',50,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【平特多肖】','#333333','shengxiao_ping_xiao','','admin','本站','',NOW(),'','','','<p></p>','','','0','',60,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【平特一尾】','#333333','weishu_ping_wei','','admin','本站','',NOW(),'','','','<p></p>','','','0','',70,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【一肖一码】','#333333','jinzita_jinzita','','admin','本站','',NOW(),'','','','<p></p>','','','0','',80,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【五肖一码】','#333333','xiaoma_xiaoma','','admin','本站','',NOW(),'','','','<p></p>','','','0','',90,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【代码肖中特】','#333333','daimingxiao','','admin','本站','',NOW(),'','','','<p></p>','','','0','',100,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【六合肖中特】','#333333','liuhexiao','','admin','本站','',NOW(),'','','','<p></p>','','','0','',110,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【三合肖中特】','#333333','sanhexiao','','admin','本站','',NOW(),'','','','<p></p>','','','0','',120,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【方位肖中特】','#333333','fangweixiao','','admin','本站','',NOW(),'','','','<p></p>','','','1','<font size="4" color="#faff00">【东肖】 【南肖】<br>【西肖】 【北肖】</font>',130,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【四季肖中特】','#333333','sijixiao','','admin','本站','',NOW(),'','','','<p></p>','','','1','<font size="4" color="#faff00">【春肖】 【夏肖】<br>【秋肖】 【冬肖】</font>',140,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【三色肖中特】','#333333','sansexiao','','admin','本站','',NOW(),'','','','<p></p>','','','1','<font size="4" color="#faff00">【红肖】<br>【蓝肖】<br>【绿肖】</font>',150,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【阴阳肖中特】','#333333','yinyang','','admin','本站','',NOW(),'','','','<p></p>','','','1','<font size="4" color="#faff00">【阴肖】<br>【阳肖】</font>',160,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【美丑肖中特】','#333333','meichou','','admin','本站','',NOW(),'','','','<p></p>','','','1','<font size="4" color="#faff00">【吉美肖】<br>【凶丑肖】</font>',170,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【笔画肖中特】','#333333','bihua','','admin','本站','',NOW(),'','','','<p></p>','','','1','<font size="4" color="#faff00">【单笔肖】<br>【双笔肖】</font>',180,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【琴棋书画】','#333333','qinqishuhua','','admin','本站','',NOW(),'','','','<p></p>','','','1','<font size="4" color="#faff00">【琴肖】 【棋肖】<br>【书肖】 【画肖】</font>',190,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【天地肖中特】','#333333','tiandi','','admin','本站','',NOW(),'','','','<p></p>','','','1','<font size="4" color="#faff00">【天肖】<br>【地肖】</font>',200,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【前后肖中特】','#333333','qianhou','','admin','本站','',NOW(),'','','','<p></p>','','','1','<font size="4" color="#faff00">【前肖】<br>【后肖】</font>',210,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【家野肖中特】','#333333','jiaye','','admin','本站','',NOW(),'','','','<p></p>','','','1','<font size="4" color="#faff00">【家禽】<br>【野兽】</font>',220,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【单双中特】','#333333','danshuang','','admin','本站','',NOW(),'','','','<p></p>','','','0','',230,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【大小中特】','#333333','daxiao','','admin','本站','',NOW(),'','','','<p></p>','','','0','',240,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【波色中特】','#333333','bose','','admin','本站','',NOW(),'','','','<p></p>','','','0','',250,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【五行中特】','#333333','wuxing','','admin','本站','',NOW(),'','','','<p></p>','','','0','',260,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【八肖中特】','#333333','shengxiao','','admin','本站','',NOW(),'','','','<p></p>','','','0','',270,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【号码中特】','#333333','haoma','','admin','本站','',NOW(),'','','','<p></p>','','','0','',280,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【特码头中特】','#333333','tou_shu','','admin','本站','',NOW(),'','','','<p></p>','','','0','',290,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','',''),
('cn','365','','【特码尾中特】','#333333','wei_shu','','admin','本站','',NOW(),'','','','<p></p>','','','0','',300,'1','0','0','0',0,0,0,'admin','admin',NOW(),NOW(),'4','','');
