FROM php:8.0-apache
# 更改apt源为阿里云的镜像
RUN set -eux; \
  mv /etc/apt/sources.list /etc/apt/sources.list.bak; \
  echo "deb http://mirrors.aliyun.com/debian/ buster main non-free contrib" >/etc/apt/sources.list; \
  echo "deb-src http://mirrors.aliyun.com/debian/ buster main non-free contrib" >>/etc/apt/sources.list; \
  echo "deb http://mirrors.aliyun.com/debian-security buster/updates main" >>/etc/apt/sources.list; \
  echo "deb-src http://mirrors.aliyun.com/debian-security buster/updates main" >>/etc/apt/sources.list; \
  echo "deb http://mirrors.aliyun.com/debian/ buster-updates main non-free contrib" >>/etc/apt/sources.list; \
  echo "deb-src http://mirrors.aliyun.com/debian/ buster-updates main non-free contrib" >>/etc/apt/sources.list; \
  echo "deb http://mirrors.aliyun.com/debian/ buster-backports main non-free contrib" >>/etc/apt/sources.list; \
  echo "deb-src http://mirrors.aliyun.com/debian/ buster-backports main non-free contrib" >>/etc/apt/sources.list;\
  apt-get update 

# 安装 mysqli 扩展
RUN apt-get update && apt-get install -y --allow-downgrades\
        zlib1g=1:1.2.11.dfsg-1+deb10u2 \
        libzip-dev \
		libfreetype6-dev \
		libjpeg62-turbo-dev \
		libpng-dev \
        zlib1g-dev \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install -j$(nproc) gd && \
    docker-php-ext-install pdo_mysql  && \
    docker-php-ext-install zip


# 将源代码添加到容器中
COPY . /tmp/html_src/
RUN chown -R www-data:www-data /tmp/html_src
# 开启 Apache 的 rewrite 模块以支持伪静态
WORKDIR /var/www/html/
CMD if [ -z "$(ls -A /var/www/html)" ]; then \
        cp -aR /tmp/html_src/. /var/www/html; \
    fi &&\
    a2enmod rewrite && apache2-foreground

