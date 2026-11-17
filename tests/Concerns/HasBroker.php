<?php

namespace Tests\Concerns;

use App\Models\Broker;
use App\Warren\BrokerContext;
use App\Warren\Brokers;

/** A connection to a fake RabbitMQ (the tests answer for it with Http::fake). */
trait HasBroker
{
    protected Broker $broker;

    protected function makeBroker(array $overrides = []): Broker
    {
        return $this->broker = Broker::create($overrides + [
            'name' => 'Teste', 'url' => 'http://broker.test:15672', 'username' => 'guest', 'password' => 'guest',
            'vhost' => '/', 'amqp_port' => 5672, 'amqp_ssl' => false, 'tap_enabled' => true,
        ]);
    }

    /** Makes sure no connection is created from the environment, whatever an earlier test did to RABBITMQ_URL. */
    protected function withoutEnvironmentBroker(): void
    {
        putenv('RABBITMQ_URL=');
        $_ENV['RABBITMQ_URL'] = $_SERVER['RABBITMQ_URL'] = '';
    }

    protected function api(string $path, ?Broker $broker = null): string
    {
        return '/api/b/'.($broker ?? $this->broker)->id.$path;
    }

    protected function context(?Broker $broker = null): BrokerContext
    {
        return app(Brokers::class)->for($broker ?? $this->broker);
    }
}
