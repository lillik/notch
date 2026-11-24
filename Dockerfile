FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --ignore-platform-req=ext-sockets

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && php bin/openapi.php

FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx curl libpq \
    && apk add --no-cache --virtual .build-deps postgresql-dev linux-headers \
    && docker-php-ext-install pdo_pgsql pcntl sockets \
    && apk del .build-deps \
    && rm -f /etc/nginx/http.d/default.conf

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY deploy/nginx/api.conf /etc/nginx/http.d/default.conf
COPY deploy/docker/api-entrypoint.sh /usr/local/bin/api-entrypoint.sh
RUN chmod +x /usr/local/bin/api-entrypoint.sh

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html

EXPOSE 8080

CMD ["sh", "/usr/local/bin/api-entrypoint.sh"]
