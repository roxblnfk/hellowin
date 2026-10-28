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
COPY .rr.yaml .rr.tls.yaml app.php ./
# The TLS config (docker-compose.tls.yml) is .rr.yaml with the .rr.tls.yaml fragment merged over it.
RUN php -r 'require "vendor/autoload.php"; $yaml = Symfony\Component\Yaml\Yaml::class; \
    file_put_contents(".rr.tls.yaml", $yaml::dump(array_replace_recursive( \
        $yaml::parseFile(".rr.yaml"), $yaml::parseFile(".rr.tls.yaml")), 10, 2));'
COPY public ./public
COPY app ./app
ENV APP_ENV=prod \
    DEBUG=false \
    VERBOSITY_LEVEL=basic \
    TOKENIZER_CACHE_TARGETS=true \
    VIEW_CACHE=true \
    MONOLOG_DEFAULT_LEVEL=INFO \
    TELEMETRY_DRIVER=null \
    RR_LOG_MODE=production \
    RR_LOG_LEVEL=info
# Workers of every pool boot at once: the tokenizer and view caches are warmed here, or each worker would scan the
# classes and race the others to write them, slow on one core. acme is the certificate's volume.
RUN php app.php views:compile >/dev/null \
    && mkdir -p runtime/cache/views runtime/cache/listeners runtime/acme && chown -R www-data:www-data runtime
USER www-data
EXPOSE 8080 10001 2112
HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/ || exit 1
CMD ["rr", "serve", "-c", ".rr.yaml"]
