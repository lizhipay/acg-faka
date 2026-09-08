#!/bin/sh
set -e

cd /var/www/html

# 这些目录都会在运行期被安装器、插件市场、上传接口、模板引擎、
# WAF/请求日志、在线更新等逻辑写入。容器启动时再修一次，是为了兼容
# 已存在的 named volume 或从旧版本 docker-compose 迁移过来的 volume。
mkdir -p \
    assets/cache \
    app/Pay \
    app/Plugin \
    app/View/User/Theme \
    config \
    kernel/Install/OS \
    kernel/Install/Update \
    runtime/log \
    runtime/plugin \
    runtime/request \
    runtime/tmp \
    runtime/view \
    runtime/waf

# 网站公开路径 /favicon.ico 指向 assets/cache/favicon.ico。
# 实际图片落在持久化卷中，避免容器重启或重建后丢失。
if [ ! -f assets/cache/favicon.ico ]; then
    if [ -f /usr/local/share/acg-faka/favicon.ico ]; then
        cp /usr/local/share/acg-faka/favicon.ico assets/cache/favicon.ico
    else
        : > assets/cache/favicon.ico
    fi
fi

if [ ! -L favicon.ico ]; then
    # 兼容旧版保存逻辑：它会用上传图片替换 favicon.ico 这个符号链接，导致图片
    # 只存在于容器可写层。重启同一个容器时先迁移到持久化卷，再恢复链接。
    if [ -f favicon.ico ] && [ -s favicon.ico ]; then
        cp favicon.ico assets/cache/favicon.ico
    fi
    rm -f favicon.ico
    ln -s assets/cache/favicon.ico favicon.ico
fi

chown -R www-data:www-data \
    assets/cache \
    app/Pay \
    app/Plugin \
    app/View/User/Theme \
    config \
    kernel/Install \
    runtime

exec docker-php-entrypoint "$@"
