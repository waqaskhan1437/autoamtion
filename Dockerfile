FROM php:8.2-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    libcurl4-openssl-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    unzip \
    nginx \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        curl \
        ftp \
        gd \
        mysqli \
        pdo_mysql \
        zip \
    && rm -rf /var/lib/apt/lists/*

RUN echo "daemon off;" >> /etc/nginx/nginx.conf

RUN sed -i 's/listen = 9000/listen = 9001/' /usr/local/etc/php-fpm.d/www.conf 2>/dev/null || true

COPY default/nginx.conf /etc/nginx/sites-available/default

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["sh", "-c", "php-fpm && nginx"]
