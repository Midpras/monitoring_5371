FROM composer:2 AS composer

FROM php:8.4-apache-bookworm AS base
COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev sqlite3 \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install gd
RUN docker-php-ext-install zip
RUN a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-monitoring.ini
COPY docker/apache-mpm.conf /etc/apache2/conf-available/monitoring-mpm.conf
RUN a2enconf monitoring-mpm

FROM base AS vendor
WORKDIR /var/www/html
COPY app/composer.json app/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY app/package.json app/package-lock.json ./
RUN npm ci
COPY app/ ./
RUN npm run build

FROM base
WORKDIR /var/www/html
COPY app/ ./
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/backup-sqlite.sh /usr/local/bin/backup-sqlite
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/backup-sqlite \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint"]
