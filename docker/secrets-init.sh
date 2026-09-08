#!/bin/sh
# 首次启动时为内置 MySQL 随机生成 root / 应用两套密码，写进密钥卷。
# 已经存在就原样保留 —— 否则重启一次密码就变了，装好的站点连不上库。
#
# 这样做的意义：镜像里不带任何默认密码，用户也不需要自己想一个。
set -e

mkdir -p /secrets

for name in mysql_root mysql_app; do
    file="/secrets/${name}"
    if [ -s "${file}" ]; then
        echo "已存在，保留：${file}"
        continue
    fi
    # tr -dc 过滤掉 shell / MySQL 里有特殊含义的字符，免得密码带 $ ' " 之类惹麻烦
    LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32 > "${file}"
    chmod 600 "${file}"
    echo "已生成：${file}"
done
