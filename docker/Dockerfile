# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Build-only intermediate stage — the composer:2 image lacks ext-pcntl/ext-posix
# (required by horizon). The final app stage installs those and runs the real,
# platform-checked install, so ignoring platform reqs here is safe.
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress --no-autoloader --ignore-platform-reqs

FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor ./vendor
# VITE_* values are inlined into the JS bundle by `vite build`, so they must be known
# here — setting them on the running container has no effect. .env is dockerignored,
# hence they come in as build args. Leaving the Reverb key empty is supported: the app
# then runs without realtime updates (see resources/js/echo.ts).
ARG VITE_APP_NAME=Kontorfix
ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
RUN npm run build

FROM dunglas/frankenphp:1-php8.4 AS app
RUN install-php-extensions pcntl posix pdo_pgsql redis zip intl opcache
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress
COPY . .
COPY --from=assets /app/public/build ./public/build
# The whole of storage/ and bootstrap/cache is .dockerignore'd: they hold runtime state
# and locally generated caches, and `bootstrap/cache/config.php` in particular would
# carry a developer's resolved .env — APP_KEY, database password, mail credentials —
# into an image layer. Recreate the skeleton here, before dump-autoload writes the
# package manifest into bootstrap/cache, and before the app runs as www-data and can no
# longer create these itself.
RUN mkdir -p bootstrap/cache \
        storage/app/artifacts storage/app/vcs storage/app/public storage/app/private \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs \
    && composer dump-autoload --optimize --no-dev \
    # `COPY . .` preserves the builder's file modes, and a checkout made under a 0077
    # umask lands here as 0600 — invisible while the image ran as root, fatal the moment
    # it does not (PHP cannot even read config/*.php). Normalise instead of trusting the
    # build host: a+rX adds read everywhere and execute only where it already was.
    && chmod -R a+rX . \
    && chmod +x docker/entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache \
    # Caddy (inside FrankenPHP) writes its data/config dirs on startup; they exist in
    # the base image owned by root. XDG_*_HOME point at them, so a non-root server
    # cannot boot without these.
    && chown -R www-data:www-data /data /config
ENV SERVER_NAME=:8080
EXPOSE 8080
# The base image ships frankenphp with cap_net_bind_service and deliberately leaves the
# user choice to the consumer; SERVER_NAME=:8080 means no privileged port is needed
# anyway. Everything the app and the entrypoint write (storage, bootstrap/cache, the
# storage/app volume mount, /data, /config) is owned by www-data above. Without this an
# arbitrary-write primitive would land as uid 0 with /app writable — a backdoor that
# survives restarts, since the entrypoint recompiles bootstrap/cache on every boot.
USER www-data
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["frankenphp", "php-server", "-r", "public/", "--listen", ":8080"]
