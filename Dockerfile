FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libsqlite3-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd pdo_sqlite \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && mkdir -p /var/lib/brickle \
    && chown www-data:www-data /var/lib/brickle

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
