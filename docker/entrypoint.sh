#!/bin/sh
set -eu

cd /var/www/html
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Keep the default SQLite database in the persistent storage volume.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ -z "${DB_DATABASE:-}" ] && [ -z "${DB_URL:-}" ]; then
    touch storage/app/database.sqlite
    ln -sfn /var/www/html/storage/app/database.sqlite database/database.sqlite
fi

chown -R www-data:www-data storage bootstrap/cache

if [ "${1:-}" = "apache2-foreground" ]; then
    exec docker-php-entrypoint "$@"
fi

exec gosu www-data docker-php-entrypoint "$@"
