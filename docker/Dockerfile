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
# CVE-2026-109618 / CVE-2026-109619 (RCE via PHP's longjmp-based OOM and fatal-error
# handling, CVSS 9.2, exploited in the wild). Fixed in PHP 8.4.27. PHP is compiled into
# the FrankenPHP base image rather than installed from apt — the base image even pins
# `php*` to Pin-Priority -1 — so no apt step here can patch it; only a rebuilt base image
# can. Fail the build rather than ship a vulnerable runtime; the guard clears itself once
# the build pulls a patched base. Note this matters more here than the CVSS alone implies:
# docker/php.ini documents anonymous, unauthenticated request shapes that reach PHP's
# memory limit during request startup, which is precisely the state this CVE weaponises.
ARG MIN_PHP_VERSION=8.4.27
RUN set -eu; \
    actual="$(php -r 'echo PHP_VERSION;')"; \
    if [ "$(php -r 'echo version_compare(PHP_VERSION, "'"${MIN_PHP_VERSION}"'", ">=") ? "ok" : "old";')" != "ok" ]; then \
        echo "FATAL: base image ships PHP ${actual}, need >= ${MIN_PHP_VERSION} (CVE-2026-109618/109619)." >&2; \
        exit 1; \
    fi
RUN install-php-extensions pcntl posix pdo_pgsql redis zip intl opcache
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
# See docker/php.ini's own comment: the base image loads no php.ini at all, so without
# this the compiled-in post_max_size=8M default would reject any OCI blob push above that
# size before the streaming upload path in App\Services\Oci\BlobStore ever ran.
COPY docker/php.ini /usr/local/etc/php/conf.d/kontorfix.ini
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
# `frankenphp run`, not `php-server`: `php-server` builds its own Caddy config
# on the fly and has no way to attach the request-body limit docker/Caddyfile sets
# (see that file's own comment for what it protects and why php.ini alone cannot).
# `--config` is relative to WORKDIR (/app), where `COPY . .` above already placed
# docker/Caddyfile — no separate COPY needed, unlike docker/php.ini, which has to
# land at an absolute system path COPY . . would never reach.
CMD ["frankenphp", "run", "--config", "docker/Caddyfile"]
