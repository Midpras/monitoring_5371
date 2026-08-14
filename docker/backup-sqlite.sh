#!/bin/sh
set -eu

db_path="${DB_DATABASE:-/var/www/html/storage/app/database/progress.sqlite}"
backup_dir="${SQLITE_BACKUP_DIR:-/var/www/html/storage/app/backups}"
mkdir -p "$backup_dir"
backup="$backup_dir/progress-$(date +%F-%H%M%S).sqlite"
sqlite3 "$db_path" ".backup '$backup'"
find "$backup_dir" -type f -name 'progress-*.sqlite' -mtime +7 -delete
