# syntax=docker/dockerfile:1

# ---- base: FrankenPHP (Caddy + PHP) com as extensões que o Warren precisa
FROM dunglas/frankenphp:1-php8.3 AS base
RUN install-php-extensions pdo_sqlite sockets pcntl opcache zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
WORKDIR /app

# ---- vendor: dependências PHP de produção
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---- assets: o front-end em Vue
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- final
FROM base AS final
ENV SERVER_NAME=":8080" \
    APP_ENV=production \
    LOG_CHANNEL=stderr \
    DB_DATABASE=/data/database.sqlite

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/entrypoint.sh /usr/local/bin/warren-entrypoint

# Roda como usuário comum: a porta 8080 não exige privilégios, e o Caddy só precisa de pastas de estado graváveis.
RUN useradd --system --create-home --uid 1000 app \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /data /config/caddy /data/caddy \
    && chown -R app:app /data /config /app/storage /app/bootstrap/cache

USER app
VOLUME /data
EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=5s --start-period=20s --retries=5 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === false ? 1 : 0);'

ENTRYPOINT ["warren-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
