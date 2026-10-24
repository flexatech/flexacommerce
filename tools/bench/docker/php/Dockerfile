# PHP-FPM for the T3 benchmark stack (doc 10 §5.3). The PHP version is a
# build argument so the same file builds 8.2 and the newest supported version.
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-fpm

RUN docker-php-ext-install mysqli opcache \
 && apt-get update && apt-get install -y --no-install-recommends default-mysql-client procps less \
 && rm -rf /var/lib/apt/lists/* \
 && curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
 && chmod +x /usr/local/bin/wp

COPY php.ini /usr/local/etc/php/conf.d/zz-bench.ini
COPY www.conf /usr/local/etc/php-fpm.d/zz-bench.conf
