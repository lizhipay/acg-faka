# Docker 部署说明

本项目的 Docker 编排包含三个服务：

- `app`：PHP 8.2 + Apache，启用 `.htaccess` 伪静态。
- `mysql`：MySQL 8.0，存储商品、订单、用户、卡密、配置等核心数据。
- `redis`：Redis 7，作为 PHP Session 存储，保存登录态、验证码等临时状态。

插件相关目录已经做持久化：

- `assets/cache`：后台/用户上传文件、远程图片缓存、缩略图，以及持久化后的 `/favicon.ico`。
- `app/Plugin`：通用插件。
- `app/Pay`：支付插件。
- `app/View/User/Theme`：用户端主题模板。
- `kernel/Install`：安装锁和应用商店临时下载目录。
- `runtime`：缓存、视图缓存、运行时文件。

容器启动脚本会自动修复这些持久化目录的 `www-data` 写权限，避免应用商店显示“安装成功”但实际解压不到插件目录、上传目录无法创建、站点 Logo 无法覆盖等问题。

## 启动

```bash
docker compose up -d --build
```

默认访问地址：

```text
http://127.0.0.1:8080
```

如需修改端口：

```bash
ACG_HTTP_PORT=8081 docker compose up -d --build
```

## 首次安装填写

安装页面里的数据库信息请填写：

```text
数据库地址：mysql
数据库名称：acg_faka
数据库账号：acg
数据库密码：acg_password
数据库前缀：acg_
```

安装完成后后台地址：

```text
http://127.0.0.1:8080/admin
```

## Redis Session 验证

访问页面、登录后台或触发验证码后，可以检查 Redis Session：

```bash
docker exec -it acg-faka-redis redis-cli -n 1 keys "acg_sess:*"
```

## 重置本地 Docker 环境

如果需要清空 MySQL、Redis、安装锁和运行缓存，执行：

```bash
docker compose down -v
```

然后重新构建：

```bash
docker compose up -d --build
```
