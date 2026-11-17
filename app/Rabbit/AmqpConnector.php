<?php

namespace App\Rabbit;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;

final class AmqpConnector
{
    /** @param array<string, mixed> $amqp config('warren.amqp') */
    public static function connect(array $amqp, string $connectionName): AbstractConnection
    {
        $config = new AMQPConnectionConfig;
        $config->setHost((string) $amqp['host']);
        $config->setPort((int) $amqp['port']);
        $config->setUser((string) $amqp['username']);
        $config->setPassword((string) $amqp['password']);
        $config->setVhost((string) $amqp['vhost']);
        $config->setConnectionName($connectionName);
        $config->setHeartbeat(30);
        $config->setIsSecure((bool) $amqp['ssl']);

        return AMQPConnectionFactory::create($config);
    }
}
