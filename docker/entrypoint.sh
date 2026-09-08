#!/bin/sh
set -e

cd /var/www/html

DATA=/data
SKEL=/opt/acg-skel

# ── 1) 播种数据卷 ────────────────────────────────────────────────────────────
# 站点里所有会被写入的目录都软链到 /data，挂一个卷就能保住全部数据。
# 首次启动时把镜像里烘好的原始内容（空白配置、Install.sql、Cartoon 模板…）复制过去。
for name in config install assets_cache plugins pay themes runtime; do
    if [ ! -e "${DATA}/${name}" ]; then
        cp -a "${SKEL}/${name}" "${DATA}/${name}"
    fi
done

mkdir -p \
    "${DATA}/runtime/log" "${DATA}/runtime/plugin" "${DATA}/runtime/request" \
    "${DATA}/runtime/tmp" "${DATA}/runtime/view" "${DATA}/runtime/waf" "${DATA}/runtime/session" \
    "${DATA}/install/OS" "${DATA}/install/Update" \
    "${DATA}/secrets"
chmod 700 "${DATA}/secrets"

# 后台"基础设置"会把上传的 Logo 写到 /favicon.ico，落到持久化目录里免得容器重建后丢失
if [ ! -f "${DATA}/assets_cache/favicon.ico" ] && [ -f /usr/local/share/acg-faka/favicon.ico ]; then
    cp /usr/local/share/acg-faka/favicon.ico "${DATA}/assets_cache/favicon.ico"
fi
if [ ! -L favicon.ico ]; then
    rm -f favicon.ico
    ln -s assets/cache/favicon.ico favicon.ico
fi

chown -R www-data:www-data \
    "${DATA}/config" "${DATA}/install" "${DATA}/assets_cache" \
    "${DATA}/plugins" "${DATA}/pay" "${DATA}/themes" "${DATA}/runtime"

# ── 2) 决定用内置数据库还是外接数据库 ────────────────────────────────────────
# 用户自己给了 ACG_DB_HOST（compose 里指向 mysql 服务，或者手工接外部库）就用他的，
# 什么都没给就启动镜像自带的 MariaDB + Redis —— 单容器 docker run 也能全自动。
BUNDLED=0
if [ -z "${ACG_DB_HOST:-}" ]; then
    BUNDLED=1
fi

if [ "${BUNDLED}" = "1" ]; then
    MYSQL_DIR="${DATA}/mysql"
    PW_FILE="${DATA}/secrets/mysql_app"

    mkdir -p "${MYSQL_DIR}" "${DATA}/redis" /run/mysqld
    chown -R mysql:mysql "${MYSQL_DIR}" /run/mysqld
    chown -R redis:redis "${DATA}/redis"

    if [ ! -d "${MYSQL_DIR}/mysql" ]; then
        echo "[acg-faka] 首次启动：初始化内置数据库…"
        mariadb-install-db --user=mysql --datadir="${MYSQL_DIR}" \
            --auth-root-authentication-method=normal --skip-test-db >/dev/null 2>&1

        # 密码随机生成后只落在数据卷里，镜像本身不带任何默认密码
        PW="$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)"
        printf '%s' "${PW}" > "${PW_FILE}"
        chmod 600 "${PW_FILE}"

        # 建库建号必须让服务器真正跑起来：--bootstrap 隐含 --skip-grant-tables，
        # CREATE USER / GRANT 在那个模式下会直接报 1290。
        # 所以照官方镜像的做法，用 socket 临时起一次，建完立刻关掉。
        INIT_SOCK=/run/mysqld/init.sock
        mariadbd --user=mysql --datadir="${MYSQL_DIR}" \
            --skip-networking --socket="${INIT_SOCK}" --skip-log-bin >/dev/null 2>&1 &
        INIT_PID=$!

        READY=0
        for _ in $(seq 1 60); do
            if mariadb-admin --socket="${INIT_SOCK}" -u root ping >/dev/null 2>&1; then READY=1; break; fi
            sleep 1
        done
        if [ "${READY}" != "1" ]; then
            echo "[acg-faka] 内置数据库启动失败" >&2
            exit 1
        fi

        # 授权主机写 '%'：程序走 TCP 连 127.0.0.1，而 MySQL 的 'localhost'
        # 只匹配 unix socket，用 localhost 授权会连不上。
        mariadb --socket="${INIT_SOCK}" -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`acg_faka\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'acg'@'%' IDENTIFIED BY '${PW}';
GRANT ALL PRIVILEGES ON \`acg_faka\`.* TO 'acg'@'%';
FLUSH PRIVILEGES;
SQL

        mariadb-admin --socket="${INIT_SOCK}" -u root shutdown
        wait "${INIT_PID}" 2>/dev/null || true

        chown -R mysql:mysql "${MYSQL_DIR}"
        echo "[acg-faka] 内置数据库已就绪（库 acg_faka / 用户 acg，密码随机生成）"
    fi

    # 交给 supervisord 托管；priority 比 php-fpm/nginx 小，先起
    cat > /etc/supervisor/conf.d/acg-bundled.conf <<'CONF'
[program:mariadb]
command=/usr/sbin/mariadbd --user=mysql --datadir=/data/mysql --bind-address=127.0.0.1 --skip-name-resolve --skip-log-bin --innodb-buffer-pool-size=256M --max-connections=200
priority=1
autostart=true
autorestart=true
startretries=3
stopsignal=TERM
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:redis]
command=/usr/bin/redis-server --dir /data/redis --appendonly yes --bind 127.0.0.1 --save ""
priority=2
autostart=true
autorestart=true
startretries=3
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
CONF

    # 安装向导据此自动填好连接信息，用户一个字都不用输
    export ACG_DB_HOST=127.0.0.1
    export ACG_DB_PORT=3306
    export ACG_DB_DATABASE=acg_faka
    export ACG_DB_USERNAME=acg
    export ACG_DB_PASSWORD_FILE="${PW_FILE}"
    export ACG_DB_PREFIX=acg_
    export ACG_REDIS_HOST=127.0.0.1
    # 让 php-fpm 等 MariaDB 就绪再启动，见 docker/wait-db.sh
    export ACG_WAIT_DB=1
else
    # 外接数据库模式：内置服务一律不启动
    rm -f /etc/supervisor/conf.d/acg-bundled.conf
fi

# ── 3) 会话后端 ──────────────────────────────────────────────────────────────
# 有 redis 就用 redis，没有退回文件。写死在 php.ini 里的话，没有 redis 的环境
# session_start() 会直接失败 —— 安装向导不开 session 所以看不出来，一登录后台就炸。
SESSION_INI=/usr/local/etc/php/conf.d/zz-acg-session.ini
if [ -n "${ACG_REDIS_HOST:-}" ]; then
    printf 'session.save_handler = redis\nsession.save_path = "tcp://%s:%s?database=%s&prefix=acg_sess:"\n' \
        "${ACG_REDIS_HOST}" "${ACG_REDIS_PORT:-6379}" "${ACG_REDIS_DB:-1}" > "${SESSION_INI}"
else
    printf 'session.save_handler = files\nsession.save_path = "/var/www/html/runtime/session"\n' > "${SESSION_INI}"
fi

# 内置数据库的密码放在只读的密钥文件里，读出来变成普通环境变量交给 php-fpm
# （pool 里 clear_env=no），安装向导才拿得到。
if [ -n "${ACG_DB_PASSWORD_FILE:-}" ] && [ -r "${ACG_DB_PASSWORD_FILE}" ]; then
    ACG_DB_PASSWORD="$(cat "${ACG_DB_PASSWORD_FILE}")"
    export ACG_DB_PASSWORD
fi

exec "$@"
