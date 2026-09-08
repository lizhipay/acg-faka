# Docker 部署

```bash
docker compose up -d
```

就这一条命令。**不需要 `.env`，不需要改任何文件，也不需要自己想数据库密码** ——
打开 <http://localhost:8080> 直接进安装向导，数据库那一步已经替你填好了。

改端口：`ACG_HTTP_PORT=8081 docker compose up -d`

## 这套编排有什么

| 服务 | 镜像 | 说明 |
|---|---|---|
| `app` | 本仓库构建 | nginx + php-fpm 8.2，两个进程由 supervisord 托管，对外只开 80 |
| `mysql` | `mysql:5.7` | 只在内网可达，不往宿主机发布端口 |
| `redis` | `redis:7.2-alpine` | PHP 会话存储（`acg_sess:` 前缀，db 1） |
| `secrets-init` | 一次性任务 | 首次启动生成随机数据库密码，之后退出 |

## 密码是怎么来的

镜像里**不带任何默认密码**。首次 `up` 时 `secrets-init` 会往 `acg_secrets` 卷里写两串
32 位随机密码（root 一串、应用一串，权限 600），MySQL 通过 `MYSQL_*_PASSWORD_FILE`
读它，安装向导则由服务端读出来直接用。

密码**不会渲染进安装页面的 HTML** —— 页面把密码栏留空、提交时带一个
`use_builtin=1` 标记，服务端自己去环境变量里取。

要自己看一眼的话：

```bash
docker compose exec mysql cat /secrets/mysql_root
```

## 想用外部数据库

安装向导第二步点「改用其它数据库」，手填即可，内置的那套就不会被用到。
也可以直接把 compose 里的 `mysql` 服务和 `ACG_DB_*` 环境变量删掉。

## 为什么是 nginx 而不是 Apache

整个程序只有 `index.php` 一个 web 入口，其余请求都靠 `?s=` 路由。所以 nginx 配置里
只放行了它一个，**其它 `.php` 一律返回 404** —— 上传目录里就算真被塞进一个 `.php`
也执行不了。`config/`、`kernel/`、`runtime/`、`vendor/` 和点开头的文件同样直接 404。

伪静态规则随镜像发布（等价于仓库里的 `.htaccess`），用户不需要配置任何东西。

反向代理（Caddy / Traefik / CDN / 宝塔）放在前面时，`X-Forwarded-Proto` 和
`X-Forwarded-For` 会被正确转成 `$_SERVER['HTTPS']` 与真实来访 IP —— 只信任私有网段，
公网伪造的头不生效。

## 已知限制

- **默认 PHP 8.2**（Debian bookworm，仍在支持期）。需要贴合旧线上环境时可以降版本：

  ```bash
  docker compose build --build-arg PHP_VERSION=8.0 app
  ```

  ⚠ 但 **PHP 8.0 / 8.1 已停止安全维护**，它们的 Debian bullseye 底座也整体进了归档 ——
  构建时会自动改用 `archive.debian.org`，代价是镜像里的系统包（**包括 nginx、openssl**）
  拿不到任何新的安全更新。挂公网的站点不建议这么用。

- **MySQL 5.7 官方只发 amd64 镜像**。ARM 机器（Apple Silicon / Ampere / Graviton）
  会通过 qemu 模拟运行 —— 能跑，但导入数据那一步明显偏慢。想要原生性能就把
  compose 里的 `mysql` 服务换成 `mariadb:10.6` 并删掉 `platform:` 那行。

## 持久化

站点数据都在 named volume 里，容器重建不会丢：

`acg_secrets`（数据库密码）、`acg_mysql`、`acg_redis`、`acg_config`、`acg_runtime`、
`acg_install`、`acg_assets_cache`、`acg_plugins`、`acg_pay`、`acg_themes`

## 常用命令

```bash
docker compose logs -f app          # 看日志（访问日志只由 nginx 出，不重复）
docker compose restart app          # 重启
docker compose down                 # 停止（数据保留）
docker compose down -v              # 停止并清空所有数据，回到全新状态
```
