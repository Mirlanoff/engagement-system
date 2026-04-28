# ================================================================
#  Laravel — Multi-stage Dockerfile
# ================================================================

# ── Stage 1: Composer dependencies ──────────────────────────────
FROM composer:2.7 AS composer-deps

WORKDIR /app

COPY composer.json ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

# ── Stage 2: Production PHP-FPM image ───────────────────────────
FROM php:8.3-fpm-alpine AS production

RUN apk add --no-cache \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libwebp-dev \
    zip \
    libzip-dev \
    unzip \
    git \
    curl \
    supervisor \
    fcgi

RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        bcmath \
        opcache \
        pcntl \
        exif

RUN pecl install redis && docker-php-ext-enable redis

RUN curl -o /usr/local/bin/php-fpm-healthcheck \
    https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck \
    && chmod +x /usr/local/bin/php-fpm-healthcheck

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

COPY --from=composer-deps /app/vendor ./vendor
COPY . .

RUN mkdir -p storage/logs storage/framework/cache \
             storage/framework/sessions storage/framework/views \
             bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 storage bootstrap/cache

EXPOSE 9000

USER www-data

CMD ["php-fpm"]
