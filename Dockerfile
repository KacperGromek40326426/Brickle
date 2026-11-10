FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libsqlite3-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd pdo_sqlite \
    && printf 'upload_max_filesize=2M\npost_max_size=12M\nmax_file_uploads=5\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/uploads.ini \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && mkdir -p /var/lib/brickle \
    && chown www-data:www-data /var/lib/brickle

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY index.php image.php setup.php .htaccess ./
COPY includes/ ./includes/
COPY assets/ ./assets/

RUN chown -R root:root /var/www/html \
    && find /var/www/html -type d -exec chmod 0555 {} + \
    && find /var/www/html -type f -exec chmod 0444 {} +
