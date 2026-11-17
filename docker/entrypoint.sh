#!/bin/sh
set -e

cd /app

# Sem configuração: se não houver APP_KEY, cria uma vez e guarda no volume de dados.
if [ -z "${APP_KEY:-}" ]; then
    keyfile=/data/app.key
    [ -s "$keyfile" ] || php artisan key:generate --show > "$keyfile"
    APP_KEY="$(cat "$keyfile")"
    export APP_KEY
fi

# Só o contêiner web migra; a escuta espera por ele (veja docker-compose.yml).
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    [ -f "${DB_DATABASE:-/data/database.sqlite}" ] || touch "${DB_DATABASE:-/data/database.sqlite}"
    php artisan migrate --force
    # Cria WARREN_ADMIN_EMAIL só na primeira subida; avisa no log se o login está ligado e não há usuários.
    php artisan warren:user --from-env
    # A primeira conexão com o RabbitMQ vem das variáveis RABBITMQ_*; as demais se cadastram pela tela.
    php artisan warren:broker --from-env
fi

exec "$@"
