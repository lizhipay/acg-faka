FROM php:8.0-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        zlib1g-dev \
        libzip-dev \
        default-mysql-client \
        redis-tools \
    && rm -rf /var/lib/apt/lists/*

# 安装 Redis 扩展
RUN pecl install redis \
    && docker-php-ext-enable redis

# 配置 PHP 和 Apache
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip pdo_mysql opcache \
    && a2enmod rewrite deflate \
    && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . .
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
RUN composer install --no-dev --optimize-autoloader

# 设置环境变量
ENV MYSQL_HOST=localhost
ENV MYSQL_USER=root
ENV MYSQL_PASSWORD=password
ENV MYSQL_DATABASE=your_database_name
ENV REDIS_HOST=redis
ENV REDIS_PORT=6379

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
