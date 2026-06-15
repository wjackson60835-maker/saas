# saas

数据采集 / 平码统计 / 用户提交站点。

## 仓库

- GitHub: https://github.com/wjackson60835-maker/saas

## 服务器首次部署

```bash
cd /www/wwwroot/你的网站目录
git clone https://github.com/wjackson60835-maker/saas.git .

cp config/database.php.example config/database.php
cp config/collect.php.example config/collect.php
# 编辑 config/database.php（数据库）
# 编辑 config/collect.php（后台密码）

chmod +x deploy/update.sh
bash deploy/setup-server.sh   # 可选：检查 PHP 扩展、runtime 目录
```

已有旧代码、不想覆盖配置时：

```bash
cd /你的网站目录
# 先备份 config/database.php config/collect.php
git init
git remote add origin https://github.com/wjackson60835-maker/saas.git
git fetch origin
git checkout -b main
git reset --hard origin/main
# 把备份的配置拷回来
```

## 日常更新

**Linux：**

```bash
cd /你的网站目录
bash deploy/update.sh
```

**Windows 服务器：**

```powershell
cd D:\你的网站目录
powershell -ExecutionPolicy Bypass -File deploy\update.ps1
```

## 本地开发推送

**一键提交并推送（推荐，提交说明自动根据改动生成）：**

```powershell
cd D:\saas
powershell -ExecutionPolicy Bypass -File deploy\git-push.ps1
```

可选手动前缀 / 交互编辑 / 跳过确认：

```powershell
powershell -ExecutionPolicy Bypass -File deploy\git-push.ps1 -Message "修复开奖对照"
powershell -ExecutionPolicy Bypass -File deploy\git-push.ps1 -Interactive
powershell -ExecutionPolicy Bypass -File deploy\git-push.ps1 -Yes
```

Linux / 服务器：

```bash
bash deploy/git-push.sh              # 自动生成：更新 api.php(修改), git-push.sh(修改)
bash deploy/git-push.sh -i           # 可编辑说明
bash deploy/git-push.sh "修复说明"   # 手动说明 + 自动摘要
bash deploy/git-push.sh -y           # 不确认直接提交
```

手动方式：

```powershell
cd d:\saas
git add -A
git -c user.name="你的名字" -c user.email="你的邮箱" commit -m "修改说明"
git push
```

## 不入库的文件（服务器各自保留）

| 文件 | 说明 |
|------|------|
| `config/database.php` | 数据库连接 |
| `config/collect.php` | 采集后台密码等 |
| `runtime/` | 缓存、session |

## 数据库脚本

按需执行 `doc/` 目录下 SQL，例如：

- `doc/collect_module.sql` — 采集模块基础表
- `doc/collect_submission_bets.sql` — 平码明细表
