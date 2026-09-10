# acg-faka 官方镜像：nginx + php-fpm，两个进程由 supervisord 托管。
#
# 为什么 nginx 而不是 mod_php：程序只有 index.php 一个 web 入口，nginx 配置里
# 因此可以只放行它一个，其余 .php 一概不执行 —— 上传目录被塞进 .php 也跑不起来。
# 伪静态规则随镜像发布，用户不需要配置任何东西。
# PHP 版本可切：默认 8.2。
#
# 为什么不是 8.0：PHP 8.0 已于 2023-11 停止安全维护，且它的 Debian bullseye 底座
# 已整体进入归档 —— 连 nginx、openssl 这些系统包都不再有安全更新。8.2 用的是
# bookworm，仍在支持期。composer.json 要求 >=8.0，本程序在 8.2 上实测可正常安装运行。
#
# 确实需要贴合旧线上环境时：
#   docker build --build-arg PHP_VERSION=8.0 -t acg-faka .
# 下面的 apt 段会自动处理 bullseye 的归档源。
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-fpm

ENV ACG_HOME=/var/www/html \
    COMPOSER_ALLOW_SUPERUSER=1

# 1) 常驻的运行时组件
RUN set -eux; \
    # 只有把 PHP_VERSION 降到 8.0 / 8.1 时才会走到这里：它们的基础镜像是 Debian
    # bullseye，已整体进归档，deb.debian.org 上的包全是 404、Release 也过了有效期。
    # 指到 archive.debian.org 并关掉有效期校验才装得上 —— 代价是这些系统包（含 nginx）
    # 不再有安全更新。默认的 8.2 是 bookworm，走正常源，不受影响。
    if . /etc/os-release && [ "$VERSION_CODENAME" = "bullseye" ]; then \
        echo 'deb http://archive.debian.org/debian bullseye main' > /etc/apt/sources.list; \
        rm -f /etc/apt/sources.list.d/*.list; \
        printf 'Acquire::Check-Valid-Until "false";\n' > /etc/apt/apt.conf.d/99no-check-valid-until; \
    fi; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        # 解 .tar.xz 用（线程管理器下载 swoole-cli 时要）。必须显式装：
        # 它本来只是被别的包顺带拉进来的，apt purge --auto-remove 一不小心就连坐删掉，
        # 之后 tar 会报 "xz: Cannot exec"，而调用方只看到"解包失败"。
        xz-utils \
        # 签 HTTPS 证书用。容器里自带才能做到「一条命令配好 SSL」，
        # 不必让用户在宿主机另外装一套或再起一个反代。
        certbot \
        # 单容器全自动模式用的内置数据库与缓存。外接数据库时它们不会启动，
        # 只占镜像体积，不占运行时资源。
        mariadb-server \
        mariadb-client \
        redis-server \
    ; \
    rm -rf /var/lib/apt/lists/* /var/lib/mysql; \
    # 数据目录一律落在 /data（单卷挂载），这里先把默认位置清掉
    mkdir -p /data

# 2) PHP 扩展。
#
# 编译完之后不能按名字去 purge 那些 -dev 包 —— `--auto-remove` 会把它们带进来的
# 运行时库一起删掉（gd / zip 直接罢工），而运行时库的包名各 Debian 版本还不一样
# （bullseye 是 libzip4，bookworm 就不是了）。所以照搬 PHP 官方镜像的做法：
# 编译完用 ldd 反查扩展真正链接到的 .so，再反查它们属于哪些包，标记为 manual 保住。
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libonig-dev \
        libcurl4-openssl-dev \
    ; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        mbstring \
        opcache \
        pdo_mysql \
        zip \
    ; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    find /usr/local/lib/php/extensions -name '*.so' -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*/%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search 2>/dev/null \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual \
    ; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    \
    # 装完立刻自检：少一个扩展安装向导第一步就过不去，宁可在构建时炸。
    # 注意 opcache 的注册名是 "Zend OPcache"，用 "opcache" 查恒为 false。
    php -r '$need = ["bcmath","gd","mbstring","pdo_mysql","zip","redis","curl","json","session","openssl"]; foreach ($need as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "缺少扩展 $e\n"); exit(1); } } if (!extension_loaded("Zend OPcache")) { fwrite(STDERR, "缺少 opcache\n"); exit(1); } if (!function_exists("imagecreatetruecolor") || !function_exists("bcadd")) { fwrite(STDERR, "gd/bcmath 装上了但函数不可用\n"); exit(1); } echo "扩展自检通过\n";'

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR ${ACG_HOME}

COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/acg-faka.conf
COPY docker/php-fpm.conf     /usr/local/etc/php-fpm.d/zz-acg-faka.conf
COPY docker/php.ini          /usr/local/etc/php/conf.d/acg-faka.ini
COPY docker/entrypoint.sh    /usr/local/bin/acg-faka-entrypoint
COPY docker/wait-db.sh       /usr/local/bin/acg-wait-db
COPY docker/supervise-threadmanager.sh /usr/local/bin/acg-supervise-threadmanager
COPY docker/acg-ssl-lib.sh    /usr/local/lib/acg-ssl-lib.sh
COPY docker/acg-ssl.sh        /usr/local/bin/acg-ssl
COPY docker/acg-ssl-import.sh /usr/local/bin/acg-ssl-import
COPY docker/acg-ssl-renew.sh  /usr/local/bin/acg-ssl-renew

COPY . ${ACG_HOME}

# config/database.php 被 .dockerignore 排除在构建上下文之外（构建机上那份含真实凭证），
# 这里重新写一份空模板：安装向导跑完会把用户填的信息覆盖进去。
RUN set -eux; \
    mkdir -p \
        /usr/local/share/acg-faka \
        ${ACG_HOME}/assets/cache \
        ${ACG_HOME}/app/Pay \
        ${ACG_HOME}/app/Plugin \
        ${ACG_HOME}/app/View/User/Theme \
        ${ACG_HOME}/config \
        ${ACG_HOME}/kernel/Install/OS \
        ${ACG_HOME}/kernel/Install/Update \
        ${ACG_HOME}/runtime/log \
        ${ACG_HOME}/runtime/plugin \
        ${ACG_HOME}/runtime/request \
        ${ACG_HOME}/runtime/tmp \
        ${ACG_HOME}/runtime/view \
        ${ACG_HOME}/runtime/waf \
    ; \
    printf '%s\n' \
        '<?php' \
        'declare(strict_types=1);' \
        '' \
        'return [' \
        "    'driver' => 'mysql'," \
        "    'host' => ''," \
        "    'port' => 3306," \
        "    'database' => ''," \
        "    'username' => ''," \
        "    'password' => ''," \
        "    'charset' => 'utf8mb4'," \
        "    'collation' => 'utf8mb4_unicode_ci'," \
        "    'prefix' => ''," \
        '];' \
        > ${ACG_HOME}/config/database.php; \
    if [ -f ${ACG_HOME}/favicon.ico ]; then \
        cp ${ACG_HOME}/favicon.ico /usr/local/share/acg-faka/favicon.ico; \
        cp ${ACG_HOME}/favicon.ico ${ACG_HOME}/assets/cache/favicon.ico; \
        ln -sf assets/cache/favicon.ico ${ACG_HOME}/favicon.ico; \
    fi; \
    if [ ! -f ${ACG_HOME}/vendor/autoload.php ]; then \
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction; \
    else \
        composer dump-autoload --optimize --no-interaction; \
    fi; \
    chown -R www-data:www-data ${ACG_HOME}; \
    chmod -R ug+rwX ${ACG_HOME}; \
    chmod +x /usr/local/bin/acg-faka-entrypoint /usr/local/bin/acg-wait-db /usr/local/bin/acg-supervise-threadmanager /usr/local/bin/acg-ssl /usr/local/bin/acg-ssl-import /usr/local/bin/acg-ssl-renew; \
    nginx -t -c /etc/nginx/nginx.conf

# 所有会被写入的目录统一搬到 /data 并软链回去 —— 挂一个卷就能保住全部数据
# （数据库、Redis、配置、上传、插件、模板、安装锁）。
# 镜像里的原始内容先烘成 skel，首次启动时由 entrypoint 播种到 /data。
RUN set -eux; \
    mkdir -p /opt/acg-skel; \
    for pair in \
        "config:config" \
        "kernel/Install:install" \
        "assets/cache:assets_cache" \
        "app/Plugin:plugins" \
        "app/Pay:pay" \
        "app/View/User/Theme:themes" \
        "runtime:runtime" \
    ; do \
        src="${ACG_HOME}/${pair%%:*}"; \
        name="${pair##*:}"; \
        mkdir -p "$(dirname "/opt/acg-skel/${name}")"; \
        mv "${src}" "/opt/acg-skel/${name}"; \
        ln -s "/data/${name}" "${src}"; \
    done; \
    chown -R www-data:www-data /opt/acg-skel; \
    # 关键：官方 php 镜像把 /var/www/html 设成 1777（world-writable + sticky）。
    # 在这种目录下 Linux 的 fs.protected_symlinks 会**禁止非软链属主跟随软链** ——
    # 软链属 root、php-fpm 跑在 www-data，结果 www-data 眼里 runtime/config 全都
    # "不存在"，前台直接 500 而且什么错都不报。两手都要改：
    #   1) 软链属主改成 www-data（与进程 uid 一致）
    #   2) docroot 去掉 world-writable 和 sticky（本来也不该是 1777）
    chown -h www-data:www-data \
        "${ACG_HOME}/config" "${ACG_HOME}/kernel/Install" "${ACG_HOME}/assets/cache" \
        "${ACG_HOME}/app/Plugin" "${ACG_HOME}/app/Pay" "${ACG_HOME}/app/View/User/Theme" \
        "${ACG_HOME}/runtime"; \
    chown www-data:www-data "${ACG_HOME}"; \
    chmod 755 "${ACG_HOME}"; \
    test -L "${ACG_HOME}/app/Plugin"

VOLUME ["/data"]

EXPOSE 80 443

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(trim((string)@file_get_contents("http://127.0.0.1/healthz")) === "pong" ? 0 : 1);'

ENTRYPOINT ["acg-faka-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
