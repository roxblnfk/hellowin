# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.4
ARG RR_VERSION=3.0.0
ARG COMPOSER_VERSION=2.10

FROM ghcr.io/roadrunner-server/roadrunner:${RR_VERSION} AS rr

FROM composer:${COMPOSER_VERSION} AS composer

FROM php:${PHP_VERSION}-cli-alpine AS base
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install -j"$(nproc)" sockets \
    && apk del .build-deps
WORKDIR /app

FROM base AS composer-base
RUN apk add --no-cache git unzip
COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1
COPY composer.json composer.lock ./

# Development image: dev dependencies, sources are bind-mounted (see docker-compose.dev.yml).
FROM composer-base AS dev
COPY --from=rr /usr/bin/rr /usr/bin/rr
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-scripts --prefer-dist --no-progress
EXPOSE 8080 10001 2112
CMD ["rr", "serve", "-c", ".rr.yaml"]

FROM composer-base AS vendor
# Dependencies only: this layer is rebuilt only when composer.* change.
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY app ./app
COPY app.php ./
RUN composer dump-autoload --no-dev --optimize --no-scripts

FROM base AS app
COPY --from=rr /usr/bin/rr /usr/bin/rr
COPY --from=vendor /app/vendor ./vendor
COPY .rr.yaml app.php ./
COPY public ./public
COPY app ./app
RUN mkdir -p runtime && chown -R www-data:www-data runtime
USER www-data
ENV APP_ENV=prod \
    DEBUG=false
EXPOSE 8080 10001 2112
HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/ || exit 1
CMD ["rr", "serve", "-c", ".rr.yaml"]
