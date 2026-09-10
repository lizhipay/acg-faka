#!/bin/sh
# 装一张你自己已经有的证书（Cloudflare 源站证书、买来的商业证书、公司内部 CA 都行）。
#
#   acg-ssl-import --cert 证书文件 --key 私钥文件 [域名...] [--no-redirect] [--hsts]
#
# 不写域名时，自动从证书里读（SAN），所以泛域名 *.abc.com 也不用你手打。
# 和 acg-ssl 写的是同一个 /data/nginx/ssl.conf —— 后跑的那条覆盖前一条。
set -eu

. /usr/local/lib/acg-ssl-lib.sh

DATA=/data
STORE="$DATA/ssl/custom"

die() { echo "✘ $1" >&2; exit 1; }

CERT=""; KEY=""; DOMAINS=""; REDIRECT=1; HSTS=0
while [ $# -gt 0 ]; do
    case "$1" in
        --cert) CERT="${2:-}"; shift 2 || die "--cert 后面要跟证书文件路径" ;;
        --key)  KEY="${2:-}";  shift 2 || die "--key 后面要跟私钥文件路径" ;;
        --no-redirect) REDIRECT=0; shift ;;
        --hsts)        HSTS=1; shift ;;
        -*) die "不认识的参数：$1" ;;
        *)  DOMAINS="$DOMAINS $1"; shift ;;
    esac
done

[ -n "$CERT" ] && [ -n "$KEY" ] || die "用法: acg-ssl-import --cert 证书文件 --key 私钥文件 [域名...]

先把两个文件拷进容器，再执行，例如：
  docker cp cert.pem faka:/tmp/
  docker cp key.pem  faka:/tmp/
  docker exec faka acg-ssl-import --cert /tmp/cert.pem --key /tmp/key.pem"

[ -f "$CERT" ] || die "证书文件不存在：$CERT
  （路径是**容器里**的路径。先 docker cp 拷进来：docker cp cert.pem faka:/tmp/）"
[ -f "$KEY" ]  || die "私钥文件不存在：$KEY
  （路径是**容器里**的路径。先 docker cp 拷进来：docker cp key.pem faka:/tmp/）"

openssl x509 -in "$CERT" -noout >/dev/null 2>&1 \
    || die "这不像一张证书：$CERT
  证书是 -----BEGIN CERTIFICATE----- 开头的文本。别把私钥当证书传了。"
openssl pkey -in "$KEY" -noout >/dev/null 2>&1 \
    || die "这不像一个私钥：$KEY
  私钥是 -----BEGIN PRIVATE KEY----- 或 -----BEGIN RSA PRIVATE KEY----- 开头的文本。
  如果它提示要密码，先解密：openssl rsa -in $KEY -out /tmp/key-plain.pem"

# 最容易踩的坑：两个文件不是一对（在面板上分开下载、下错了一版）。
# nginx 启动时才报 key values mismatch，那时站已经挂了，所以这里先比公钥。
_c=$(openssl x509 -in "$CERT" -noout -pubkey 2>/dev/null | openssl md5)
_k=$(openssl pkey -in "$KEY" -pubout 2>/dev/null | openssl md5)
[ "$_c" = "$_k" ] || die "证书和私钥不是一对，装上去 nginx 会起不来。
  多半是从面板下载时下错了版本，或者拿了别的域名那张。重新下载配对的两个文件。"

# 有效期：过期/还没生效的证书浏览器一样报错，早点说清楚
openssl x509 -in "$CERT" -noout -checkend 0 >/dev/null 2>&1 \
    || die "这张证书已经过期了：$(openssl x509 -in "$CERT" -noout -enddate | cut -d= -f2)"

# 域名：没给就从证书 SAN 里读，泛域名也能正确带出来
if [ -z "$DOMAINS" ]; then
    DOMAINS=$(openssl x509 -in "$CERT" -noout -ext subjectAltName 2>/dev/null \
        | tr ',' '\n' | sed -n 's/.*DNS://p' | tr -d ' ' | tr '\n' ' ')
    [ -n "$DOMAINS" ] || DOMAINS=$(openssl x509 -in "$CERT" -noout -subject 2>/dev/null | sed -n 's/.*CN *= *//p')
    [ -n "$DOMAINS" ] || die "证书里没读到域名，请手动写在命令后面：
  acg-ssl-import --cert $CERT --key $KEY abc.com '*.abc.com'"
    echo "→ 从证书里读到域名：$DOMAINS"
fi
SERVER_NAMES=$(echo "$DOMAINS" | tr -s ' ' | sed 's/^ //;s/ $//')

echo "→ 保存证书到数据卷"
mkdir -p "$STORE"
install -m 644 "$CERT" "$STORE/fullchain.pem"
install -m 600 "$KEY"  "$STORE/privkey.pem"

# 链不全的话安卓和一些老客户端会报错，桌面 Chrome 却看着正常——最难查的一类问题。
# Cloudflare 源站证书本来就是单张，不算问题，所以只提示不拦。
_n=$(grep -c -- "-----BEGIN CERTIFICATE-----" "$STORE/fullchain.pem" || true)
if [ "$_n" -le 1 ]; then
    _iss=$(openssl x509 -in "$STORE/fullchain.pem" -noout -issuer)
    case "$_iss" in
        *Cloudflare*) : ;;  # 源站证书就该是单张，走 Cloudflare 时它自己认
        *) echo "  ⚠ 这个文件里只有 1 张证书，没带中间证书。桌面浏览器可能看着正常，"
           echo "    但安卓和部分老客户端会报证书错误。请到证书商那里下载「完整链 /"
           echo "    fullchain / nginx 格式」的那份，通常是证书 + 中间证书拼在一起。" ;;
    esac
fi

echo "→ 写入 nginx 配置"
acg_write_ssl_conf "$SERVER_NAMES" "$STORE/fullchain.pem" "$STORE/privkey.pem" "$HSTS" "$REDIRECT" "acg-ssl-import"

echo "→ 检查配置并重载 nginx"
nginx -t || die "nginx 配置检查没过，已保留 $DATA/nginx/ssl.conf 供排查"
nginx -s reload

_first=$(echo "$SERVER_NAMES" | cut -d' ' -f1)
echo ""
echo "✔ 装好了：$SERVER_NAMES"
echo "  到期时间：$(openssl x509 -in "$STORE/fullchain.pem" -noout -enddate | cut -d= -f2)"
echo ""
if [ "$REDIRECT" = 1 ]; then
    echo "  强制 HTTPS 已开：http:// 会 301 跳到 https://"
    echo "  （挂 CDN 且回源走 http 的话，这里会变成无限重定向，加 --no-redirect 重跑）"
else
    echo "  未开强制跳转：80 端口继续照常提供服务"
fi
[ "$HSTS" = 1 ] && echo "  HSTS 已开：浏览器一年内只走 https。注意这一年内改不回 http。"
echo ""
echo "  容器要把 443 映射出来（-p 443:443），没映射的话按文档重建容器。"
echo "  这张证书到期前记得重新跑一次这条命令换新的，acg-ssl-renew 只续 Let's Encrypt 的。"
