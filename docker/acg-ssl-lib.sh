#!/bin/sh
# acg-ssl / acg-ssl-import 共用的 nginx 配置生成。
# 抽出来是因为两条命令写的是同一个 /data/nginx/ssl.conf，
# 分开维护迟早会飘（一边加了防护规则另一边忘了 = 只有 443 是个洞）。
#
# acg_write_ssl_conf <域名列表> <证书路径> <私钥路径> <hsts:0|1> <redirect:0|1> <来源说明>

WEBROOT=/var/www/html

acg_write_ssl_conf() {
    _names="$1"; _cert="$2"; _key="$3"; _hsts="$4"; _redirect="$5"; _origin="$6"

    _hsts_line=""
    # HSTS：告诉浏览器「以后这个域名只准走 https」，比 301 更彻底，但一年内不可撤销，
    # 所以默认不开。add_header 会被 location 里的同名指令整组顶掉，用到的块里各写一次。
    [ "$_hsts" = 1 ] && _hsts_line='add_header Strict-Transport-Security "max-age=31536000" always;'

    # 强制跳转 = 额外写一个 80 端口的 server 块把这些域名 301 走。
    # 不强制时干脆不写：80 端口交回镜像里的 default_server，那份带着完整防护规则，
    # 也照常提供 /.well-known/，certbot 续期不受影响。
    if [ "$_redirect" = 1 ]; then
        _http_server="# http 一律跳 https（ACME 续期验证除外，否则续不了期）
server {
    listen 80;
    listen [::]:80;
    server_name $_names;

    location ^~ /.well-known/acme-challenge/ {
        root $WEBROOT;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}"
    else
        _http_server="# 未开强制跳转：80 端口走镜像自带的 default_server，这里不额外声明。"
    fi

    cat > /data/nginx/ssl.conf <<EOF
# 由 $_origin 生成，域名：$_names
# 这个文件在数据卷里，容器重建后依然生效。
# 要加/换域名，把所有域名一起再跑一次命令即可（这个文件会被整个覆盖）。

$_http_server

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name $_names;
    root $WEBROOT;
    index index.php;
    charset utf-8;

    ssl_certificate     $_cert;
    ssl_certificate_key $_key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 1d;

    client_max_body_size 50m;
    $_hsts_line

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
        $_hsts_line
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
        $_hsts_line
        access_log off;
        try_files \$uri =404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?s=\$uri&\$args;
        $_hsts_line
    }
}
EOF
}
