FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer

FROM php:8.4.26-cli-bookworm@sha256:836ac6c672d1372a47c8fd61b625015bd07760f493fb2eaab2087636498a2b4b AS base
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libonig-dev libxml2-dev libsqlite3-dev \
    && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring dom pcntl \
    && pecl install redis-6.2.0 \
 && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app

FROM base AS test
COPY . .
RUN composer install --no-interaction --prefer-dist --no-progress \
    && composer check-platform-reqs
CMD ["php", "artisan", "test"]

FROM base AS production
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
