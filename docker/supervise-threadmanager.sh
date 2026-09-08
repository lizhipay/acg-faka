#!/bin/sh
# 把线程管理器的守护进程登记给 supervisord，并立刻拉起来。
#
# 两个地方会调用它：
#   1) entrypoint —— 容器启动时，插件已经装好的情况
#   2) 插件自己的 service.sh —— 刚装完/刚启动时，省掉"还要重启一次容器"
# 幂等：重复调用只是重写配置再 update 一次。
set -e

HOME_DIR="${ACG_HOME:-/var/www/html}"
TM_DIR="${HOME_DIR}/app/Plugin/ThreadManager"
CONF=/etc/supervisor/conf.d/acg-threadmanager.conf

# 插件没装或二进制还没下：清掉配置，别留一个会反复重启失败的程序
if [ ! -x "${TM_DIR}/swoole-cli" ] || [ ! -f "${TM_DIR}/service.sh" ]; then
    if [ -f "${CONF}" ]; then
        rm -f "${CONF}"
        supervisorctl reread >/dev/null 2>&1 || true
        supervisorctl update >/dev/null 2>&1 || true
    fi
    exit 0
fi

chmod +x "${TM_DIR}/service.sh" 2>/dev/null || true

# 守护进程要在这里建单例锁。root 跑过 install / 手工清理过的话，这个目录可能不存在
# 或者属主变成 root，www-data 起来就会报"无法打开锁文件"然后被 supervisord 判 FATAL。
install -d -o www-data -g www-data -m 755 \
    "${HOME_DIR}/runtime/plugin/ThreadManager" 2>/dev/null || true

# command 里必须用站点下的路径（app/Plugin/ThreadManager），不能用 /data/plugins/…：
# service.sh 靠自身位置往上三级推算站点根，从 /data/plugins 推出来会变成 /。
#
# 前面套 acg-wait-db：守护进程启动要连数据库，内置 MariaDB 比它起得慢，
# 不等的话会连崩三次直接 FATAL。
# stopwaitsecs 要大于插件配置里的 stop_timeout（默认 20），否则任务没收尾就被砍。
cat > "${CONF}" <<CONF
[program:threadmanager]
command=/usr/local/bin/acg-wait-db ${TM_DIR}/service.sh run
directory=${HOME_DIR}
user=www-data
priority=30
autostart=true
autorestart=true
startretries=3
stopsignal=TERM
stopwaitsecs=40
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
CONF

# supervisord 还没起来时（entrypoint 阶段）只写配置就够了，它启动时自会读取
if ! supervisorctl status >/dev/null 2>&1; then
    exit 0
fi

supervisorctl reread >/dev/null 2>&1 || true
supervisorctl update >/dev/null 2>&1 || true
supervisorctl start threadmanager >/dev/null 2>&1 || true
exit 0
