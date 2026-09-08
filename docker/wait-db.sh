#!/bin/sh
# php-fpm 的启动闸门。
#
# 内置数据库模式下 MariaDB 比 nginx/php-fpm 起得慢好几秒，用户点得快一点就会撞上
# "Connection refused"。supervisord 的 priority 只保证启动顺序、不保证就绪，
# 所以在这里等它真的开始接受连接再放行。
set -e

if [ "${ACG_WAIT_DB:-0}" = "1" ]; then
    i=0
    until mariadb-admin --protocol=tcp \
            -h "${ACG_DB_HOST:-127.0.0.1}" -P "${ACG_DB_PORT:-3306}" ping >/dev/null 2>&1; do
        i=$((i + 1))
        if [ "${i}" -ge 90 ]; then
            echo "[acg-faka] 等待内置数据库超时，仍继续启动" >&2
            break
        fi
        sleep 1
    done
    [ "${i}" -lt 90 ] && echo "[acg-faka] 内置数据库已就绪，启动 PHP"
fi

exec "$@"
