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
    exec php artisan horizon
elif [ "$role" = "scheduler" ]; then
    exec php artisan schedule:work
elif [ "$role" = "reverb" ]; then
    exec php artisan reverb:start --host=0.0.0.0 --port=8080
fi

exec "$@"
