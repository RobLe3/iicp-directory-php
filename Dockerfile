FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx curl && \
    docker-php-ext-install pdo pdo_mysql

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . .

RUN cp .env.example .env && \
    mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && \
    composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --ignore-platform-reqs && \
    php artisan key:generate --force && \
    chmod -R 775 storage bootstrap/cache && \
    chmod +x docker-testbed-entrypoint.sh
# --no-scripts skips the post-autoload-dump `artisan package:discover` (which exited 1 in
# the build env and broke the image); Laravel rebuilds the package manifest lazily at
# runtime. mkdir ensures the gitignored runtime dirs exist before any artisan boot.
# Dropped --quiet so a future build error is visible.

EXPOSE 8080

# Testbed entrypoint writes container env into .env (php artisan serve does not forward
# it to the request context) then migrates + serves. Production uses deploy/ + php-fpm.
CMD ["sh", "./docker-testbed-entrypoint.sh"]
