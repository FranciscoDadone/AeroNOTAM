# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1-php8.3-bookworm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends gosu \
    && rm -rf /var/lib/apt/lists/*

# La imagen ya trae pdo_sqlite, curl, dom, mbstring y opcache. pcntl es lo que
# el worker necesita para atender SIGTERM y terminar el job en curso.
RUN install-php-extensions pcntl zip

# La capacidad sobre el binario es lo que le permite tomar el puerto 80 sin
# correr como root.
RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R www-data:www-data /data/caddy /config/caddy

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app

# ---------------------------------------------------------------------------

FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

# Sólo los manifiestos primero: mientras composer.lock no cambie, esta capa
# sale de caché aunque haya cambiado todo el código.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------------------------------------------------------------------------

# El CSS de la landing lo compila Vite, así que la imagen necesita Node una
# sola vez —acá— y no en la que termina corriendo.
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources

RUN npm run build

# ---------------------------------------------------------------------------

FROM base AS app

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY --from=vendor --chown=www-data:www-data /app/bootstrap/cache ./bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Sin nombre de host Caddy no emite certificado: el TLS lo pone el proxy de
# adelante. Un dominio real acá alcanza para que se encargue él.
ENV SERVER_NAME=:80

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost/up > /dev/null || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
