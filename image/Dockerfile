# PrestaShop on the official PHP-FPM Alpine image.
#
# Same approach as the vendor image (https://github.com/PrestaShop/docker):
# the official "Classic" distribution zip is extracted to /usr/src/prestashop,
# and the stack's `setup` service copies it to /var/www/html on first run.
# Built on php:<ver>-fpm-alpine to get current PHP patch releases.

ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-fpm-alpine

# PHP extensions required by PrestaShop 9 (curl, dom, fileinfo, iconv, json,
# mbstring, openssl, simplexml and opcache are already in the base image).
RUN set -eux; \
    # icu-data-full: locale data for intl (Alpine ships English only): the
    # back office's Symfony number, money and date fields follow the
    # employee's language (es: "9.990,50", not "9,990.50"). Prices use
    # PrestaShop's own CLDR data.
    apk add --no-cache su-exec icu-data-full; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS freetype-dev icu-dev libjpeg-turbo-dev libpng-dev \
        libwebp-dev libxml2-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" bcmath gd intl pdo_mysql soap zip; \
    runDeps="$( \
        scanelf --needed --nobanner --format '%n#p' --recursive /usr/local/lib/php/extensions \
            | tr ',' '\n' | sort -u \
            | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
    )"; \
    apk add --no-cache --virtual .prestashop-rundeps $runDeps; \
    apk del --no-network .build-deps; \
    php -m | grep -qi -e '^gd$' -e '^intl$'

COPY opcache.ini /usr/local/etc/php/conf.d/opcache-recommended.ini

# PrestaShop Classic distribution: https://github.com/PrestaShopCorp/prestashop-classic
# PS_SHA256 verifies the zip; leave it empty to skip the check.
ARG PS_VERSION=9.1.5-5.0
ARG PS_SHA256=37140cb77c03acf61b832f76893cd8fe1e304b0fc14fa5485cfea4a7bfaf3a72
RUN set -eux; \
    apk add --no-cache --virtual .fetch-deps unzip; \
    curl -fsSL -o /tmp/distribution.zip \
        "https://github.com/PrestaShopCorp/prestashop-classic/releases/download/${PS_VERSION}/prestashop_${PS_VERSION}.zip"; \
    if [ -n "${PS_SHA256}" ]; then echo "${PS_SHA256}  /tmp/distribution.zip" | sha256sum -c -; fi; \
    unzip -q /tmp/distribution.zip prestashop.zip -d /tmp; \
    mkdir -p /usr/src/prestashop; \
    unzip -q /tmp/prestashop.zip -d /usr/src/prestashop; \
    rm /tmp/distribution.zip /tmp/prestashop.zip; \
    chown -R www-data:www-data /usr/src/prestashop; \
    apk del --no-network .fetch-deps

ENV PS_VERSION=${PS_VERSION}
WORKDIR /var/www/html
