FROM php:8.3-fpm-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli intl gd zip opcache \
    && rm -rf /var/lib/apt/lists/*
RUN printf 'memory_limit=256M\nupload_max_filesize=32M\npost_max_size=32M\nmax_execution_time=120\ndate.timezone=UTC\n' > /usr/local/etc/php/conf.d/modx.ini
WORKDIR /var/www/html
