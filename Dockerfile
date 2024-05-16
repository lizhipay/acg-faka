# 使用官方的 PHP 镜像作为基础镜像
FROM php:8.0-apache

# 安装必要的依赖和 PHP 扩展
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        zlib1g-dev \
        libzip-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip pdo_mysql opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* \
    && { \
    echo 'zend_extension=opcache.so'; \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'opcache.enable_file_override=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# 启用 mod_rewrite 和 mod_deflate 模块
RUN a2enmod rewrite deflate

# 设置工作目录
WORKDIR /var/www/html

# 复制源码和 .htaccess 文件
COPY . .

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
RUN composer install --no-dev --optimize-autoloader

# 设置环境变量
ENV MYSQL_HOST=localhost
ENV MYSQL_USER=root
ENV MYSQL_PASSWORD=password
ENV MYSQL_DATABASE=your_database_name

# 设置 AllowOverride
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# 增加权限
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html

# 开放 Apache 端口
EXPOSE 80

# 启动 Apache 服务
CMD ["apache2-foreground"]
