#!/bin/sh
set -e

# Patch .env with runtime DB env vars (phpdotenv reads the file; Docker env vars alone are not enough)
sed -i "s|^DB_HOST=.*|DB_HOST=${DB_HOST:-localhost}|" .env
sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_DATABASE:-iicp_directory}|" .env
sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USERNAME:-iicp_dir_user}|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD:-iicp_dir_pass}|" .env

php artisan config:clear
php artisan migrate --force

# Install nginx config
cp /app/config/nginx-fpm.conf /etc/nginx/http.d/default.conf

# Configure PHP-FPM pool for concurrency
cat > /usr/local/etc/php-fpm.d/www.conf <<'PHP_FPM'
[www]
user = www-data
group = www-data
listen = 127.0.0.1:9000
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
pm.max_requests = 500
PHP_FPM

# Enable OPcache
cat > /usr/local/etc/php/conf.d/opcache.ini <<'OPCACHE'
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=0
opcache.validate_timestamps=0
opcache.fast_shutdown=1
OPCACHE

# Start PHP-FPM in background, nginx in foreground
php-fpm -D
exec nginx -g 'daemon off;'
