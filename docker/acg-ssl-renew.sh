#!/bin/sh
# 证书续期。Let's Encrypt 证书 90 天到期，certbot 只在剩余不足 30 天时才真的续，
# 所以这条命令每天跑一次是安全的，不会触发频率限制。
set -eu
DATA=/data

certbot renew \
    --webroot -w /var/www/html \
    --config-dir "$DATA/ssl" \
    --work-dir "$DATA/ssl/work" \
    --logs-dir "$DATA/ssl/logs" \
    --quiet

# 续期成功后必须重载，否则 nginx 还拿着旧证书跑到过期
nginx -t && nginx -s reload
