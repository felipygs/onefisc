#!/bin/sh
set -e

cd /app

composer install --no-interaction --prefer-dist --no-progress

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    php artisan migrate --force
fi

exec "$@"
