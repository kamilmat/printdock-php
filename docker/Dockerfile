# Dev image: PHP CLI + pdo_mysql (SQLite is built in) + Composer + Python for the conformance suite.
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip python3 \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
