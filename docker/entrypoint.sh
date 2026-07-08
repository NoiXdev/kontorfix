#!/bin/sh
set -e

role="${CONTAINER_ROLE:-app}"

if [ "$role" = "app" ]; then
    php artisan migrate --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    exec "$@"
elif [ "$role" = "worker" ]; then
    exec php artisan queue:work --tries=3 --max-time=3600
elif [ "$role" = "scheduler" ]; then
    exec php artisan schedule:work
fi

exec "$@"
