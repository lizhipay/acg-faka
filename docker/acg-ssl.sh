#!/bin/sh
# 一条命令给站点配好 HTTPS：申请证书 → 写 nginx 配置 → 重载。
#
#   acg-ssl 你的域名 你的邮箱
#
# 设计要点：证书与 nginx 片段都落在 /data（数据卷）里，换镜像、重建容器都还在；
# 镜像层里的 /etc/nginx/nginx.conf 一行都不用改（改了也会被下次 pull 覆盖）。
set -eu

DOMAIN="${1:-}"
EMAIL="${2:-}"
DATA=/data
WEBROOT=/var/www/html

die() { echo "✘ $1" >&2; exit 1; }

[ -n "$DOMAIN" ] || die "用法: acg-ssl 你的域名 你的邮箱
例如: acg-ssl shop.abc.com me@abc.com"
[ -n "$EMAIL" ] || die "还缺邮箱。证书快过期时 Let's Encrypt 会发提醒到这个邮箱。
用法: acg-ssl $DOMAIN 你的邮箱"

case "$DOMAIN" in
    -*|*/*|*\ *) die "域名格式不对：$DOMAIN" ;;
esac

echo "→ 申请证书：$DOMAIN"
echo "  （Let's Encrypt 会访问 http://$DOMAIN/.well-known/... 验证域名，"
echo "    所以 80 端口必须能从外网打开，且域名已解析到这台服务器）"

# webroot 模式：证书验证文件写进站点目录，由已经在跑的 nginx 提供，不用停服务。
certbot certonly \
    --webroot -w "$WEBROOT" \
    -d "$DOMAIN" \
    --email "$EMAIL" \
    --agree-tos --no-eff-email \
    --non-interactive \
    --config-dir "$DATA/ssl" \
    --work-dir "$DATA/ssl/work" \
    --logs-dir "$DATA/ssl/logs" \
    || die "证书申请失败。最常见的三个原因：
   1) 域名没解析到这台服务器，或解析还没生效
   2) 80 端口没开（云服务器要去安全组放行 80）
   3) 容器没把 80 映射出来（要有 -p 80:80）
  详细日志：$DATA/ssl/logs/letsencrypt.log"

LIVE="$DATA/ssl/live/$DOMAIN"
[ -f "$LIVE/fullchain.pem" ] || die "证书文件没找到：$LIVE/fullchain.pem"

echo "→ 写入 nginx 配置"
cat > "$DATA/nginx/ssl.conf" <<EOF
# 由 acg-ssl 生成，域名：$DOMAIN
# 这个文件在数据卷里，容器重建后依然生效。要换域名重新跑一次 acg-ssl 即可。

# http 一律跳 https（ACME 续期验证除外，否则续不了期）
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    location ^~ /.well-known/acme-challenge/ {
        root $WEBROOT;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name $DOMAIN;
    root $WEBROOT;
    index index.php;
    charset utf-8;

    ssl_certificate     $LIVE/fullchain.pem;
    ssl_certificate_key $LIVE/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 1d;

    client_max_body_size 50m;

    location = /healthz {
        access_log off;
        fastcgi_pass 127.0.0.1:9000;
        include fastcgi_params;
        fastcgi_param SCRIPT_NAME /ping;
        fastcgi_param SCRIPT_FILENAME /ping;
    }

    location = /index.php {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_read_timeout 300;
        fastcgi_buffer_size 32k;
        fastcgi_buffers 16 16k;
    }

    # 与 80 端口那份保持同一套防护：只放行唯一入口，其余 .php 一律不执行
    if (\$uri ~* "^/(?!index\\.php(/|\$)).*\\.php(/|\$)") { return 404; }

    location ~ ^/(config|kernel|runtime|vendor|encryption|tests)(/|\$) { return 404; }
    location ~ /\\.(?!well-known) { return 404; }
    location ~* \\.(log|sql|sqlite|db|db-wal|db-shm|bak|old|save|orig|swp|swo|tmp|ini|lock)\$ { return 404; }
    location ~* (~|composer\\.(json|lock)|package(-lock)?\\.json)\$ { return 404; }

    location ~* \\.(?:css|js|mjs|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|mp3|ogg)\$ {
        expires 7d;
        add_header Cache-Control "public";
        access_log off;
        try_files \$uri =404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?s=\$uri&\$args;
    }
}
EOF

echo "→ 检查配置并重载 nginx"
nginx -t || die "nginx 配置检查没过，已保留 $DATA/nginx/ssl.conf 供排查"
nginx -s reload

echo ""
echo "✔ 配好了：https://$DOMAIN"
echo ""
echo "  还差最后一步：容器要把 443 映射出来。如果当初只写了 -p 80:80，"
echo "  请按文档重建容器并加上 -p 443:443。"
echo ""
echo "  证书 90 天有效。续期命令（建议加进宿主机 crontab，每天跑一次）："
echo "    docker exec faka acg-ssl-renew"
