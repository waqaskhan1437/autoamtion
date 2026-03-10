FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    libcurl4-openssl-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        curl \
        ftp \
        gd \
        mysqli \
        pdo_mysql \
        zip \
    && a2enmod rewrite headers expires \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/start-apache.sh /usr/local/bin/start-apache.sh
RUN chmod +x /usr/local/bin/start-apache.sh

COPY . /var/www/html

RUN mkdir -p /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html

EXPOSE 10000

CMD ["start-apache.sh"]

