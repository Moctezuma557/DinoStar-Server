FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip libpq-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_pgsql pcntl mbstring dom xml xmlwriter \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
    && composer check-platform-reqs --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
