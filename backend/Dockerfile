FROM php:8.3-fpm-alpine AS production

# Системные зависимости + autoconf для PECL
RUN apk add --no-cache \
    autoconf \
    g++ \
    make \
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
    fcgi \
    oniguruma-dev

# PHP расширения
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
        exif \
        mbstring

# Redis расширение
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# PHP конфигурация
COPY docker/php/php.ini      /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

COPY . .

RUN mkdir -p storage/logs \
             storage/framework/cache \
             storage/framework/sessions \
             storage/framework/views \
             bootstrap/cache

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 storage bootstrap/cache

EXPOSE 9000

USER www-data

CMD ["php-fpm"]
