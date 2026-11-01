# 部署指南

以 CentOS 7.9 + 宝塔 + Nginx + PHP 8.2 + MySQL 8 为例；其他 Linux 发行版同理。

## 1. 环境要求

| 组件 | 要求 |
| --- | --- |
| PHP | 8.2+（CLI 与 FPM 版本一致） |
| MySQL | 8.0+，字符集 `utf8mb4` |
| Nginx | 1.20+（建议开启 `realip` 模块） |
| 扩展 | `pdo_mysql`、`openssl`、`curl`、`mbstring`、`json`、`session`、`gd` |
| 可选 | Node ≥ 20.10 + Chrome（验证码求解）；`proc_open` 可用（异步任务） |

## 2. 上传与权限

```bash
# 站点目录建议：/www/wwwroot/aigw
cd /www/wwwroot/aigw
chown -R www:www .
find storage -type d -exec chmod 775 {} \;
chmod 600 .env
```

`storage/` 下会自动创建：

- `storage/sessions/` 会话文件
- `storage/logs/` 应用日志（`app-YYYY-MM-DD.log`、`php-error.log`、worker 日志）
- `storage/tmp/` 临时文件
- `storage/backup/`、`storage/login/`、`storage/persona/` 业务缓存

## 3. 安装

```bash
cp .env.example .env
vi .env      # 填 AIGW_APP_URL、AIGW_DB_*、AIGW_APP_KEY

php database/install.php
```

期望输出：

```text
[✓] 数据库连接成功 (127.0.0.1:3306)
[✓] 数据库就绪：aigw
[✓] 表结构创建完成（共 N 条语句）
[✓] 管理员账号：admin / **********（请登录后立即修改）
[✓] 网关 API Key：sk-aigw-********
```

> 建表脚本每张表前都有 `DROP TABLE IF EXISTS`，因此 `--force` 等于清空整库。脚本对已有业务数据的库有二次确认（需 `AIGW_ALLOW_DESTRUCTIVE=1`）。

## 4. Web 服务器

### 4.1 Nginx（推荐）

用 [deploy/nginx-aigw.conf](../deploy/nginx-aigw.conf) 整体替换站点配置，注意：

- 站点「网站目录」保持为**项目根目录**（`/www/wwwroot/aigw`），不要指到 `public/`
- 入口 rewrite 到 `public/index.php`
- `/assets/*` 走 `public/assets` 下的真实文件
- 关闭 `open_basedir`（应用需要读写 `storage/` 并启动子进程）
- 流式接口需关闭代理缓冲（`proxy_buffering off`、`X-Accel-Buffering: no`）

### 4.2 Apache

站点根指向项目根，仓库根目录的 `.htaccess` 已提供等价规则：

```apache
RewriteRule ^assets/(.*)$ public/assets/$1 [L]
RewriteRule ^$ public/index.php [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ public/index.php [L]
```

### 4.3 PHP-FPM / php.ini

参考：

- [deploy/php-fpm-snippet.conf](../deploy/php-fpm-snippet.conf)
- [deploy/php-ini-snippet.ini](../deploy/php-ini-snippet.ini)

要点：

```ini
session.save_path = "/www/wwwroot/aigw/storage/sessions"
session.gc_maxlifetime = 604800
output_buffering = Off
zlib.output_compression = Off
```

> 若开启了 `open_basedir`，`storage/` 将不可写、`.env` 读不到，表现为「登录后立刻掉线」「500」。

## 5. 计划任务

本项目无常驻进程，依赖 cron 周期触发：

```cron
# 内置调度：自动签到 / Token 保活 / 积分巡检
*/5 * * * * cd /www/wwwroot/aigw && /www/server/php/82/bin/php bin/scheduler.php >> /www/wwwroot/aigw/storage/logs/cron.log 2>&1

# 用户工作流调度
* * * * * cd /www/wwwroot/aigw && /www/server/php/82/bin/php bin/workflow-worker.php >> /www/wwwroot/aigw/storage/logs/cron.log 2>&1
```

说明：

- 必须写 **PHP 绝对路径**（宝塔定时任务的 PATH 与登录 shell 不同）
- `scheduler.php` 幂等，触发间隔需大于单次执行耗时
- 各任务总开关默认关闭，需在管理台「设置」中打开
- 若使用 ZCode 领额度能力，计划任务环境需要能访问 Node：`AIGW_NODE_BIN=/usr/bin/node`

其他 worker（由 Web 端自动拉起，cron 仅兜底）：

| 脚本 | 用途 |
| --- | --- |
| `bin/action-worker.php <task_id>` | 账号动作异步执行（如领额度） |
| `bin/research-worker.php <task_id>` / `--due` | 深度研究任务 |
| `bin/responses-worker.php <response_id>` / `--due` | 后台 Responses |

## 6. CDN 场景（腾讯云 EdgeOne）

EdgeOne 的回源 IP 段接口已下线，无法用 `set_real_ip_from` 还原客户端 IP。本项目采用**回源令牌**：

1. `openssl rand -hex 32` 生成口令，填入 `AIGW_ORIGIN_TOKEN`
2. 在 CDN 控制台为**回源请求**添加同名请求头，值为该口令
3. Nginx 端对不带（或带错）口令的请求直接 403，挡掉绕过 CDN 的直连扫描
4. PHP 端只在令牌正确时采信 `AIGW_CLIENT_IP_HEADER`（EO 为 `eo-connecting-ip`）
5. 必须确认 EO 控制台开启「真实客户端 IP 回源」

三个配置必须成套，缺一个会静默退化成「所有请求共用一个限流桶」。

## 7. 升级与回滚

```bash
# 备份
mysqldump -u<user> -p <db> > backup-$(date +%F-%H%M).sql
tar -czf storage-backup-$(date +%F).tar.gz storage .env

# 升级（代码覆盖后）
php database/install.php        # 已有表会跳过建表，不会清空数据
```

需要新增表/字段时，单独执行增量 SQL，**不要**带 `--force`。

## 8. 常见问题

| 现象 | 排查 |
| --- | --- |
| 登录成功但刷新即掉线 | `session.save_path` 不可写；`open_basedir` 拦截；多 worker 临时目录不一致 |
| 流式无输出、一次性吐完 | Nginx/PHP 开启了压缩或缓冲；检查 `proxy_buffering`、`output_buffering`、`zlib.output_compression` |
| 页面 500 且无详情 | 看 `storage/logs/php-error.log`；临时开启 `AIGW_APP_DEBUG` 定位后立即关闭 |
| 账号全部失效 | `AIGW_APP_KEY` 被更换，历史凭据无法解密；恢复原 key |
| 异步任务一直 PENDING | `proc_open` 被 `disable_functions` 禁用、`AIGW_PHP_BIN` 指向错误；看 `storage/logs/action-worker.log` |
| CDN 回源 524 | 长耗时任务必须走后台 worker；确认 `background` / 异步任务已启用 |
| 真实 IP 全是内网地址 | 可信代理配置缺失或回源令牌未生效 |
| CentOS 7 安装 Node 报 `GLIBC_2.28 not found` | 使用 glibc-217 专用构建，**不要升级系统 glibc** |
| Chrome 无法启动 | CentOS 7 只能装 ≤ 125 的 Chrome；无显示环境需 Xvfb |
