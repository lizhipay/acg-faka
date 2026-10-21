#!/bin/sh
# 打上 session 对象注入硬化补丁，重编 PHP（新增 session.harden 开关）。
#
# 补丁来自 https://github.com/lizhipay/php/tree/df7dacca4c996128176c3b9c3eeb772cc997f0fb/hardening
# 原样放在 docker/php-patches/，更新时整份替换并改上面的 commit。
#
# 官方镜像把 session 扩展静态编进了 php 主程序，没法单独换掉，只能整个重编。
# 源码用镜像自带的 /usr/src/php.tar.xz（官方构建时已验过签名），configure 参数和
# CFLAGS 原样取自基础镜像，所以除了补丁本身，产物与官方构建一致。
# 找不到对应版本的补丁、或补丁打不上，都让构建失败，不静默跳过。
set -eu

patch_dir="$1"
minor="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
patch_file="${patch_dir}/php-${minor}-session-hardening.patch"
if [ ! -f "${patch_file}" ]; then
    echo "[acg-faka] 没有 PHP ${minor} 的 session 硬化补丁：${patch_file}" >&2
    exit 1
fi

configure_options="$(php-config --configure-options)"

savedAptMark="$(apt-mark showmanual)"
apt-get update
# shellcheck disable=SC2086
apt-get install -y --no-install-recommends \
    ${PHPIZE_DEPS} \
    patch \
    libargon2-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    libreadline-dev \
    libsodium-dev \
    libsqlite3-dev \
    libssl-dev \
    libxml2-dev \
    zlib1g-dev

export \
    CFLAGS="${PHP_CFLAGS}" \
    CPPFLAGS="${PHP_CPPFLAGS}" \
    LDFLAGS="${PHP_LDFLAGS}" \
    PHP_BUILD_PROVIDER='https://github.com/docker-library/php' \
    PHP_UNAME='Linux - Docker'

docker-php-source extract
cd /usr/src/php
patch -p1 --no-backup-if-mismatch < "${patch_file}"

debMultiarch="$(dpkg-architecture --query DEB_BUILD_MULTIARCH)"
if [ ! -d /usr/include/curl ] && [ ! -e /usr/local/include/curl ]; then
    ln -sT "/usr/include/${debMultiarch}/curl" /usr/local/include/curl
fi

# shellcheck disable=SC2086
./configure ${configure_options}
make -j "$(nproc)"
find . -type f -name '*.a' -delete
make install
find /usr/local -type f -perm '/0111' -exec sh -euc 'strip --strip-all "$@" || :' -- '{}' +
make clean
cd /
docker-php-source delete

apt-mark auto '.*' > /dev/null
# shellcheck disable=SC2086
[ -z "${savedAptMark}" ] || apt-mark manual ${savedAptMark} > /dev/null
find /usr/local -type f -executable -exec ldd '{}' ';' \
    | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
    | sort -u \
    | xargs -r dpkg-query --search \
    | awk 'sub(":$", "", $1) { print $1 }' \
    | sort -u \
    | xargs -r apt-mark manual > /dev/null
apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false
rm -rf /var/lib/apt/lists/* /tmp/pear ~/.pearrc

if ! php -r 'exit(ini_get("session.harden") === false ? 1 : 0);'; then
    echo "[acg-faka] 重编后没有 session.harden，补丁没生效" >&2
    exit 1
fi
php --version
