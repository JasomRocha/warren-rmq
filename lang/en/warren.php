<?php

return [
    'broker' => [
        'created' => 'Connection :name created from the environment.',
        'unknown' => 'This connection does not exist.',
        'no_vhost' => 'The vhost “:vhost” does not exist on this RabbitMQ.',
        'unauthorized' => 'RabbitMQ did not accept the user or password (answer :status)',
        'status' => 'RabbitMQ answered :status for /api:path',
        'unreachable' => 'Could not reach RabbitMQ at :url (/api:path)',
        'password_again' => 'Enter the password again: the address, user or vhost changed.',
        'address_invalid' => 'Invalid address.',
        'address_extra' => 'Use only the management API address, with no user, password, query or fragment.',
        'address_blocked' => 'This address is not allowed (link-local or cloud metadata).',
    ],
    'message_gone' => 'This message already left the history.',
    'panel' => ['unknown' => 'This panel is not in the catalog.'],
    'tap' => [
        'removed' => 'Connection :name removed; tap closed.',
        'disabled' => 'The tap is disabled (WARREN_TAP_ENABLED=false).',
        'connected' => 'Tap connected, :count {count|copy binding|copy bindings}',
        'stopped' => 'Tap stopped: :error',
    ],
    'user' => [
        'no_users' => 'Login is on but there are no users. Create one with: php artisan warren:user you@example.com',
        'ask_email' => 'E-mail',
        'ask_password' => 'Password (min 8 characters)',
        'ask_repeat' => 'Repeat the password',
        'mismatch' => 'The passwords do not match.',
        'invalid_email' => 'Invalid e-mail.',
        'short_password' => 'The password needs at least 8 characters.',
        'created' => 'Created user :email.',
        'updated' => 'Updated the password of :email.',
    ],
    'demo' => [
        'warning' => 'This declares demo.* exchanges and queues and publishes test messages on :host:::port (vhost :vhost).',
        'confirm' => 'Continue? Never point this at a production broker.',
        'publishing' => 'Publishing :rate/s. Ctrl+C to stop.',
    ],
];
