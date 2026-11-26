# syntax=docker/dockerfile:1

############################################
# PHP base image (nginx + php-fpm, runs as www-data)
############################################
FROM serversideup/php:8.4-fpm-nginx AS base

USER root
# intl is required by Filament, zip for supplier ZIP files
RUN install-php-extensions intl zip
USER www-data

############################################
# Composer dependencies
############################################
FROM base AS vendor

WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

############################################
# Frontend assets (Vite / Tailwind)
############################################
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi
COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
RUN npm run build

############################################
# Production image
############################################
FROM base AS app

WORKDIR /var/www/html

ENV PHP_OPCACHE_ENABLE=1 \
    # serversideup: run migrations, storage:link and config/route/view caches on start
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true

COPY --chown=www-data:www-data --from=vendor /var/www/html/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi \
    && php artisan filament:assets

EXPOSE 8080
