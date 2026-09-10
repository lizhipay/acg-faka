#!/bin/sh
# 一条命令给站点配好 HTTPS：申请证书 → 写 nginx 配置 → 重载。
#
#   acg-ssl 域名... 邮箱 [--no-redirect] [--hsts]
#
# 域名可以写多个，会签成同一张证书（SAN），例如：
#   acg-ssl abc.com www.abc.com shop.abc.com me@abc.com
#
# 设计要点：证书与 nginx 片段都落在 /data（数据卷）里，换镜像、重建容器都还在；
# 镜像层里的 /etc/nginx/nginx.conf 一行都不用改（改了也会被下次 pull 覆盖）。
set -eu

. /usr/local/lib/acg-ssl-lib.sh

DATA=/data
CERT_NAME=acg          # 固定名字，重跑时是「改这张证书」而不是新签一张 xxx-0001
LIVE="$DATA/ssl/live/$CERT_NAME"

die() { echo "✘ $1" >&2; exit 1; }

DOMAINS=""
EMAIL=""
REDIRECT=1
HSTS=0

for a in "$@"; do
    case "$a" in
        --no-redirect) REDIRECT=0 ;;
        --hsts)        HSTS=1 ;;
        -*)            die "不认识的参数：$a（只支持 --no-redirect 和 --hsts）" ;;
        *\**)          die "webroot 方式签不了泛域名（$a）。
  泛域名 *.abc.com 只能用 DNS 验证，子站点需要它，做法见文档「泛域名证书」一节。" ;;
        *@*)           EMAIL="$a" ;;
        */*|*\ *)      die "域名格式不对：$a" ;;
        *)             DOMAINS="$DOMAINS $a" ;;
    esac
done

[ -n "$DOMAINS" ] || die "用法: acg-ssl 域名... 邮箱
例如: acg-ssl shop.abc.com me@abc.com
多个域名: acg-ssl abc.com www.abc.com me@abc.com"
[ -n "$EMAIL" ] || die "还缺邮箱（带 @ 的那个）。证书快过期时 Let's Encrypt 会发提醒到这个邮箱。
用法: acg-ssl$DOMAINS 你的邮箱"

# 组装 certbot 的 -d 参数；域名已校验过不含空格，这里可以安全依赖分词
CERTBOT_D=""
for d in $DOMAINS; do CERTBOT_D="$CERTBOT_D -d $d"; done

# server_name 用的列表（去掉开头空格）
SERVER_NAMES="${DOMAINS# }"

echo "→ 申请证书：$SERVER_NAMES"
echo "  （Let's Encrypt 会逐个访问 http://域名/.well-known/... 验证，"
echo "    所以每个域名都要解析到这台服务器，且 80 端口能从外网打开）"

# webroot 模式：证书验证文件写进站点目录，由已经在跑的 nginx 提供，不用停服务。
# shellcheck disable=SC2086
certbot certonly \
    --webroot -w "$WEBROOT" \
    --cert-name "$CERT_NAME" \
    $CERTBOT_D \
    --email "$EMAIL" \
    --agree-tos --no-eff-email \
    --non-interactive --expand \
    --config-dir "$DATA/ssl" \
    --work-dir "$DATA/ssl/work" \
    --logs-dir "$DATA/ssl/logs" \
    || die "证书申请失败。最常见的三个原因：
   1) 域名没解析到这台服务器，或解析还没生效（多个域名时，有一个不通就整张证书失败）
   2) 80 端口没开（云服务器要去安全组放行 80）
   3) 容器没把 80 映射出来（要有 -p 80:80）
  详细日志：$DATA/ssl/logs/letsencrypt.log"

[ -f "$LIVE/fullchain.pem" ] || die "证书文件没找到：$LIVE/fullchain.pem"

echo "→ 写入 nginx 配置"
acg_write_ssl_conf "$SERVER_NAMES" "$LIVE/fullchain.pem" "$LIVE/privkey.pem" "$HSTS" "$REDIRECT" "acg-ssl"

echo "→ 检查配置并重载 nginx"
nginx -t || die "nginx 配置检查没过，已保留 $DATA/nginx/ssl.conf 供排查"
nginx -s reload

FIRST="${SERVER_NAMES%% *}"
echo ""
echo "✔ 配好了：https://$FIRST"
for d in $DOMAINS; do [ "$d" = "$FIRST" ] || echo "           https://$d"; done
echo ""
if [ "$REDIRECT" = 1 ]; then
    echo "  强制 HTTPS 已开：http:// 访问会 301 跳到 https://（不想强制就加 --no-redirect 重跑）"
else
    echo "  未开强制跳转：http:// 仍然可以直接访问（去掉 --no-redirect 重跑即可开启）"
fi
[ "$HSTS" = 1 ] && echo "  HSTS 已开：浏览器一年内只走 https。注意这一年内改不回 http。"
echo ""
echo "  还差最后一步：容器要把 443 映射出来。如果当初只写了 -p 80:80，"
echo "  请按文档重建容器并加上 -p 443:443。"
echo ""
echo "  证书 90 天有效。续期命令（建议加进宿主机 crontab，每天跑一次）："
echo "    docker exec faka acg-ssl-renew"
