FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html

# 安装系统基础工具
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
    && a2enmod rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && printf '%s\n' 'ServerName localhost' > /etc/apache2/conf-available/server-name.conf \
    && a2enconf server-name \
    && rm -rf /var/lib/apt/lists/*

# 引入官方社区扩展安装工具
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
    bcmath \
    curl \
    gd \
    mbstring \
    opcache \
    pdo_mysql \
    redis \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html
COPY docker/php.ini /usr/local/etc/php/conf.d/acg-faka.ini
COPY docker/entrypoint.sh /usr/local/bin/acg-faka-entrypoint

RUN mkdir -p \
        /usr/local/share/acg-faka \
        /var/www/html/assets/cache \
        /var/www/html/app/Pay \
        /var/www/html/app/Plugin \
        /var/www/html/app/View/User/Theme \
        /var/www/html/kernel/Install/OS \
        /var/www/html/kernel/Install/Update \
        /var/www/html/runtime/log \
        /var/www/html/runtime/plugin \
        /var/www/html/runtime/request \
        /var/www/html/runtime/tmp \
        /var/www/html/runtime/view \
        /var/www/html/runtime/waf \
    && if [ -f /var/www/html/favicon.ico ]; then \
        cp /var/www/html/favicon.ico /usr/local/share/acg-faka/favicon.ico; \
        cp /var/www/html/favicon.ico /var/www/html/assets/cache/favicon.ico; \
        ln -sf assets/cache/favicon.ico /var/www/html/favicon.ico; \
    fi \
    && if [ ! -d /var/www/html/vendor ] || [ ! -f /var/www/html/vendor/autoload.php ]; then \
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction; \
    else \
        composer dump-autoload --optimize --no-interaction; \
    fi \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R ug+rwX /var/www/html \
    && chmod +x /usr/local/bin/acg-faka-entrypoint

EXPOSE 80

ENTRYPOINT ["acg-faka-entrypoint"]
CMD ["apache2-foreground"]