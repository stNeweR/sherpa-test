# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli-alpine AS vendor

RUN apk add --no-cache git unzip curl \
 && curl -fsSL -o /tmp/composer-setup.php https://getcomposer.org/installer \
 && EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)" \
 && ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" \
 && [ "$EXPECTED" = "$ACTUAL" ] \
 && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && rm /tmp/composer-setup.php \
 && composer --version

ARG PACKAGIST_MIRROR=""

ENV COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /app

COPY backend/composer.json backend/composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    if [ -n "$PACKAGIST_MIRROR" ]; then composer config -g repos.packagist composer "$PACKAGIST_MIRROR"; fi

RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install \
      --no-dev \
      --prefer-dist \
      --no-scripts \
      --no-autoloader \
      --no-interaction \
      --no-progress \
      --ignore-platform-reqs

COPY backend/ ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM php:${PHP_VERSION}-cli-alpine AS test

RUN apk add --no-cache git unzip curl libpq icu-libs libzip rabbitmq-c \
 && apk add --no-cache --virtual .build-deps \
      $PHPIZE_DEPS \
      libpq-dev \
      icu-dev \
      libzip-dev \
      oniguruma-dev \
      postgresql-dev \
      rabbitmq-c-dev \
 && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql zip \
 && pecl install amqp \
 && docker-php-ext-enable amqp \
 && apk del .build-deps

COPY --from=vendor /usr/local/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1

CMD ["php", "vendor/bin/phpunit"]

FROM php:${PHP_VERSION}-fpm-alpine AS runtime

ARG PACKAGIST_MIRROR=""

RUN apk add --no-cache libpq icu-libs libzip rabbitmq-c \
 && apk add --no-cache --virtual .build-deps \
      $PHPIZE_DEPS \
      libpq-dev \
      icu-dev \
      libzip-dev \
      oniguruma-dev \
      postgresql-dev \
      rabbitmq-c-dev \
 && docker-php-ext-install -j"$(nproc)" intl opcache pdo_pgsql zip \
 && pecl install amqp \
 && docker-php-ext-enable amqp \
 && apk del .build-deps

COPY --from=vendor /usr/local/bin/composer /usr/local/bin/composer
COPY --from=vendor /app /var/www/html
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

RUN mkdir -p /var/www/html/var/cache \
             /var/www/html/var/proxies \
             /var/www/html/storage/logs \
             /var/www/html/storage/imports \
 && chown -R www-data:www-data /var/www/html/var /var/www/html/storage \
 && chmod -R 775 /var/www/html/var /var/www/html/storage

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
