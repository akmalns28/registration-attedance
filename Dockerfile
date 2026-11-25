ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.4-bookworm
FROM ${FRANKENPHP_IMAGE} AS php-base

RUN cp "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini" \
    && install-php-extensions bcmath exif gd intl mbstring pcntl pdo_mysql zip \
    && php -r 'exit(empty(gd_info()["JPEG Support"]) || empty(gd_info()["PNG Support"]) || empty(gd_info()["WebP Support"]) ? 1 : 0);'
COPY php.prod.ini /usr/local/etc/php/conf.d/zz-rams-production.ini
WORKDIR /app

FROM composer:2 AS composer-tool
FROM php-base AS dependencies
COPY --from=composer-tool /usr/bin/composer /usr/bin/composer
COPY . .
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM node:24-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --include=dev --ignore-scripts
COPY vite.config.js ./
COPY resources resources
COPY --from=dependencies /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

FROM php-base
ENV SERVER_NAME=:80
COPY --from=dependencies /app /app
COPY --from=frontend /app/public/build /app/public/build
