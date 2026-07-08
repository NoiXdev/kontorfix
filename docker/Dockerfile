# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress --no-autoloader

FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM dunglas/frankenphp:1-php8.4 AS app
RUN install-php-extensions pcntl pdo_pgsql redis zip intl opcache
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/entrypoint.sh \
    && mkdir -p storage/app/artifacts storage/app/vcs \
    && chown -R www-data:www-data storage bootstrap/cache
ENV SERVER_NAME=:8080
EXPOSE 8080
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["frankenphp", "php-server", "-r", "public/", "--listen", ":8080"]
