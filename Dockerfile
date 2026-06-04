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
    chmod -R 775 storage bootstrap/cache
# --no-scripts skips the post-autoload-dump `artisan package:discover` (which exited 1 in
# the build env and broke the image); Laravel rebuilds the package manifest lazily at
# runtime. mkdir ensures the gitignored runtime dirs exist before any artisan boot.
# Dropped --quiet so a future build error is visible.

EXPOSE 8080

CMD ["sh", "-c", "sed -i \"s|^DB_HOST=.*|DB_HOST=${DB_HOST:-localhost}|\" .env && sed -i \"s|^DB_DATABASE=.*|DB_DATABASE=${DB_DATABASE:-iicp_directory}|\" .env && sed -i \"s|^DB_USERNAME=.*|DB_USERNAME=${DB_USERNAME:-iicp_dir_user}|\" .env && sed -i \"s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD:-iicp_dir_pass}|\" .env && php artisan config:clear && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8080"]
