#!/bin/sh
# php-fpm 的启动闸门。
#
# 内置数据库模式下 MariaDB 比 nginx/php-fpm 起得慢好几秒，用户点得快一点就会撞上
# "Connection refused"。supervisord 的 priority 只保证启动顺序、不保证就绪，
# 所以在这里等它真的开始接受连接再放行。
set -e

if [ "${ACG_WAIT_DB:-0}" = "1" ]; then
    # 用裸 TCP 探活而不是 mariadb-admin ping：后者不带凭据连上去，MariaDB 每次都会
    # 往日志里记一条 "Access denied for user ..."，容器启动日志上看着像出了事。
    i=0
    until php -r 'exit(@fsockopen(getenv("ACG_DB_HOST") ?: "127.0.0.1", (int)(getenv("ACG_DB_PORT") ?: 3306), $e, $s, 2) ? 0 : 1);' >/dev/null 2>&1; do
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
