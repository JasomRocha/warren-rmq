<?php

// WARREN_X, or the old RABBITMAP_X name that earlier installs used.
$env = fn (string $name, mixed $default = null) => env('WARREN_'.$name, env('RABBITMAP_'.$name, $default));

return [

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ broker
    |--------------------------------------------------------------------------
    |
    | Connection to the RabbitMQ management API (port 15672). The user needs the
    | "monitoring" tag to list every consumer in one call. With only "management"
    | the app falls back to one request per queue, cached (see below).
    |
    */

    'broker' => [
        'url' => env('RABBITMQ_URL', 'http://localhost:15672'),
        'username' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'timeout' => (int) env('RABBITMQ_TIMEOUT', 8),
    ],

    'cache' => [
        // Seconds a topology snapshot is reused, so many open tabs cost one broker round trip.
        'topology_ttl' => (int) $env('TOPOLOGY_TTL', 2),
        // Seconds the per-queue consumer details are reused (management-only users).
        'consumer_details_ttl' => (int) $env('CONSUMER_DETAILS_TTL', 10),
    ],

    // How many per-queue detail requests run at once.
    'consumer_details_concurrency' => (int) $env('CONSUMER_CONCURRENCY', 6),

    /*
    |--------------------------------------------------------------------------
    | Message tap (AMQP, port 5672)
    |--------------------------------------------------------------------------
    |
    | The management API cannot show messages without consuming them, so `php artisan
    | warren:tap` creates two temporary, exclusive queues: one bound to your exchanges
    | to receive a copy of everything published, one bound to the broker's trace exchange
    | (only fed when `rabbitmqctl trace_on` is enabled) to see deliveries and publishes to
    | the default exchange. Your real queues are never touched and the temporary ones
    | disappear when the daemon disconnects.
    |
    */

    'amqp' => [
        'enabled' => (bool) $env('TAP_ENABLED', true),
        'host' => env('RABBITMQ_AMQP_HOST') ?: parse_url((string) env('RABBITMQ_URL', 'http://localhost:15672'), PHP_URL_HOST),
        'port' => (int) env('RABBITMQ_AMQP_PORT', 5672),
        'username' => env('RABBITMQ_AMQP_USER', env('RABBITMQ_USER', 'guest')),
        'password' => env('RABBITMQ_AMQP_PASSWORD', env('RABBITMQ_PASSWORD', 'guest')),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'ssl' => (bool) env('RABBITMQ_AMQP_SSL', false),
    ],

    'tap' => [
        'connection_name' => 'warren (escuta)',
        // Most recent messages kept (older ones are deleted).
        'history_limit' => (int) $env('HISTORY_LIMIT', 3000),
        'queue_max_length' => 5000,
        'rebind_seconds' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Identifying instances
    |--------------------------------------------------------------------------
    |
    | "Instance" is whatever you group producers by: a tenant, customer, service... It is read
    | from the message, first match wins:
    |   1. dot paths into the JSON body        WARREN_INSTANCE_PATHS=tenant,data.tenantId
    |   2. message header names                WARREN_INSTANCE_HEADERS=x-tenant
    |   3. regexes over "exchange|routing_key" with an (?<instance>...) group, as a JSON array:
    |      WARREN_INSTANCE_REGEXES='["/\\|app\\.events\\.(?<instance>[^.]+)/"]'
    |      (single quotes in .env keep the backslashes; JSON needs them doubled)
    |
    */

    'identify' => [
        'instance_paths' => array_values(array_filter(array_map('trim', explode(',', (string) $env('INSTANCE_PATHS', 'instance,instanceId,tenant,data.instance,data.instanceId'))))),
        'instance_headers' => array_values(array_filter(array_map('trim', explode(',', (string) $env('INSTANCE_HEADERS', 'instance,x-instance'))))),
        'instance_regexes' => json_decode((string) $env('INSTANCE_REGEXES', '[]'), true) ?: [],
        // Pairs [regex, replacement] applied to the instance found, so one instance written two ways counts once.
        'instance_normalize' => json_decode((string) $env('INSTANCE_NORMALIZE', '[]'), true) ?: [],
        'job_paths' => array_values(array_filter(array_map('trim', explode(',', (string) $env('JOB_PATHS', 'displayName,job,type,event'))))),
        'uuid_paths' => array_values(array_filter(array_map('trim', explode(',', (string) $env('UUID_PATHS', 'uuid,id,data.uuid'))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Login
    |--------------------------------------------------------------------------
    |
    | The UI shows message bodies, so it asks for a login by default. There is no public sign-up:
    | create users with `php artisan warren:user`, or set ADMIN_EMAIL and ADMIN_PASSWORD and the
    | first user is created on start if it does not exist yet (the Docker image does this).
    |
    | Set WARREN_AUTH=false only when something in front already authenticates (VPN, SSO proxy).
    |
    */

    'auth' => [
        'enabled' => (bool) $env('AUTH', true),
        'admin_email' => $env('ADMIN_EMAIL'),
        'admin_password' => $env('ADMIN_PASSWORD'),
        'max_attempts' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Catálogo de filas
    |--------------------------------------------------------------------------
    |
    | JSON que dá nomes de negócio às filas ("Exportação de pedidos · acme") e as agrupa em áreas.
    | Veja catalogo/exemplo.json. Sem o arquivo, as filas aparecem com o nome técnico.
    |
    */

    'catalog' => [
        'path' => $env('CATALOG', base_path('catalogo/catalogo.json')),
    ],
];
