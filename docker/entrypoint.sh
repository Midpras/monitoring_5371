#!/bin/sh
set -eu

db_path="${DB_DATABASE:-/var/www/html/storage/app/database/progress.sqlite}"
export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="$db_path"
export SESSION_DRIVER="${SESSION_DRIVER:-file}"
export CACHE_STORE="${CACHE_STORE:-file}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"
export DB_FOREIGN_KEYS="${DB_FOREIGN_KEYS:-true}"
export DB_BUSY_TIMEOUT="${DB_BUSY_TIMEOUT:-5000}"
export DB_JOURNAL_MODE="${DB_JOURNAL_MODE:-wal}"
export DB_SYNCHRONOUS="${DB_SYNCHRONOUS:-normal}"

mkdir -p "$(dirname "$db_path")" storage/app/private storage/app/backups storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
touch "$db_path"
chown -R www-data:www-data storage bootstrap/cache

php artisan migrate --force
if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
    php artisan db:seed --force
fi

exec apache2-foreground
