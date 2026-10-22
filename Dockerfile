# Example container images for the Static Maps API (#103): one for PHP-FPM
# (target `php`) and one for nginx (target `nginx`), used by compose.yaml.
#
#     docker compose up -d --build
#
# The images hold the code only. Settings, client keys and the tile cache
# live in data/, which compose.yaml mounts from the host.

# --- PHP-FPM with curl, gd (JPEG, FreeType, WebP, AVIF) and imagick -------------
FROM php:8.5-fpm-trixie AS php

# Builds and enables extensions with their libraries; gd gets JPEG, FreeType,
# WebP and AVIF support. (curl is part of the base image.)
COPY --from=mlocati/php-extension-installer:2.12.0 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd imagick \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-static-maps.ini"
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-static-maps.conf

WORKDIR /app
COPY . /app

# Only data/ is writable, by www-data; everything else belongs to root.
RUN mkdir -p data/cache && chown -R www-data:www-data data

USER www-data
VOLUME /app/data
EXPOSE 9000

# --- nginx: serves public/ and passes PHP requests to the php service ----------
FROM nginx:1.30-alpine AS nginx

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY public /app/public

EXPOSE 80
