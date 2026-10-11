# syntax=docker/dockerfile:1

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.* ./
RUN npm run build

FROM php:8.3-fpm-alpine AS php-base
WORKDIR /var/www/html
RUN apk add --no-cache fcgi icu-libs libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev sqlite-dev \
    && docker-php-ext-install -j$(nproc) bcmath intl opcache pcntl pdo_mysql pdo_sqlite zip \
    && apk del .build-deps
COPY docker/php.ini /usr/local/etc/php/conf.d/99-finance.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-finance.conf
COPY --chmod=755 docker/healthcheck-fpm.sh /usr/local/bin/healthcheck-fpm

FROM php-base AS dependencies
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

FROM php-base AS app
COPY --from=dependencies --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
USER www-data
EXPOSE 9000
HEALTHCHECK --interval=10s --timeout=3s --start-period=15s --retries=3 CMD healthcheck-fpm
CMD ["php-fpm", "-F"]

# Optional development/check image: keeps test tools out of the runtime image.
FROM dependencies AS development
RUN composer install --prefer-dist --no-interaction --no-progress \
    && chown -R www-data:www-data /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
USER www-data
CMD ["composer", "check"]

FROM nginx:1.28-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
EXPOSE 80
HEALTHCHECK --interval=10s --timeout=3s --start-period=15s --retries=3 CMD wget -q --spider http://127.0.0.1/up || exit 1
