-- 已有「开奖预测」栏目（scode=365）时：把内容页模板改为 yuce_detail.html，点击首页预测条标题可进详情。
-- 执行后后台「内容模型 / 栏目」里该栏目的内容模板应显示为 yuce_detail.html。

UPDATE `ay_content_sort` SET `contenttpl` = 'yuce_detail.html' WHERE `scode` = '365' LIMIT 1;
