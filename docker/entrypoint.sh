#!/bin/sh
# Prepara lo que vive en volúmenes y cede el proceso al comando del servicio.
set -e

cd /app

# Un volumen nombrado puede montarse vacío y tapar lo que traía la imagen.
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/public \
    storage/app/private \
    bootstrap/cache

DB_FILE="${DB_DATABASE:-/app/database/sqlite/database.sqlite}"

case "${DB_CONNECTION:-sqlite}" in
    sqlite)
        mkdir -p "$(dirname "$DB_FILE")"
        [ -f "$DB_FILE" ] || : > "$DB_FILE"
        chown -R www-data:www-data "$(dirname "$DB_FILE")"
        ;;
esac

chown -R www-data:www-data storage bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    echo "entrypoint: falta APP_KEY. Generala con 'php artisan key:generate'" >&2
    echo "entrypoint: y dejala en .env, que es de donde compose la toma." >&2
    exit 1
fi

exec gosu www-data "$@"
