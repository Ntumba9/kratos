# syntax=docker/dockerfile:1.7
# KRATOS CORE runtime image (php-fpm). Targets: dev, prod.

FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache icu-libs libpq libzip fcgi \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libpq-dev libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql pgsql zip opcache pcntl \
 && pecl install redis-6.2.0 \
 && docker-php-ext-enable redis \
 && apk del .build-deps \
 && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infra/docker/php/conf.d/kratos.ini /usr/local/etc/php/conf.d/zz-kratos.ini
COPY infra/docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-kratos.conf

WORKDIR /var/www/html

HEALTHCHECK --interval=15s --timeout=3s --retries=5 \
  CMD SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1

# ---------------------------------------------------------------- development
FROM base AS dev
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
# Source is bind-mounted in development; see docker-compose.yml.

# ---------------------------------------------------------------- production
FROM base AS prod
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
COPY core/composer.json core/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY core/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && rm -f .env \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data

