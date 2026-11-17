<?php

namespace App\Console\Commands;

use App\Rabbit\AmqpConnector;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class DemoCommand extends Command
{
    protected $signature = 'warren:demo
        {--rate=3 : Mensagens publicadas por segundo}
        {--seconds= : Parar depois desta quantidade de segundos (padrão: roda até ser interrompido)}
        {--yes : Não pedir confirmação}';

    protected $description = 'Gera tráfego de demonstração (produtores, filas e um worker lento) para o diagrama ter o que mostrar';

    private const TENANTS = ['acme', 'acme', 'acme', 'globex', 'globex', 'initech', 'hooli', 'umbrella'];

    public function handle(): int
    {
        $amqp = config('warren.amqp');
        $this->warn(trans('warren.demo.warning', ['host' => $amqp['host'], 'port' => $amqp['port'], 'vhost' => $amqp['vhost']]));
        if (! $this->option('yes') && ! $this->confirm(trans('warren.demo.confirm'), false)) {
            return self::FAILURE;
        }

        $connection = AmqpConnector::connect($amqp, 'warren (demo)');
        $channel = $connection->channel();

        foreach (['demo.events' => 'topic', 'demo.jobs' => 'direct', 'demo.edge' => 'topic'] as $name => $type) {
            $channel->exchange_declare($name, $type, false, true, false);
        }
        foreach (['demo.orders', 'demo.orders.created', 'demo.pdf', 'demo.audit'] as $queue) {
            $channel->queue_declare($queue, false, true, false, false);
        }
        $channel->queue_bind('demo.orders', 'demo.events', 'orders.#');
        $channel->queue_bind('demo.orders.created', 'demo.events', 'orders.*.created');
        $channel->queue_bind('demo.orders.created', 'demo.events', 'orders.created');
        $channel->queue_bind('demo.pdf', 'demo.jobs', 'pdf');
        $channel->exchange_bind('demo.events', 'demo.edge', 'orders.#');
        $channel->queue_bind('demo.audit', 'demo.edge', '#');

        // A worker that takes a while per message, acking later, so "processing" is visible.
        $pending = [];
        $channel->basic_qos(null, 3, null);
        foreach (['demo.orders', 'demo.pdf'] as $queue) {
            $channel->basic_consume($queue, '', false, false, false, false, function (AMQPMessage $m) use (&$pending) {
                $pending[] = [microtime(true) + mt_rand(500, 3000) / 1000, $m];
            });
        }

        $rate = max(1, (int) $this->option('rate'));
        $end = $this->option('seconds') === null ? PHP_INT_MAX : time() + (int) $this->option('seconds');
        $nextPublish = microtime(true);
        $this->info(trans('warren.demo.publishing', ['rate' => $rate]));

        while (time() < $end) {
            $now = microtime(true);
            if ($now >= $nextPublish) {
                $this->publish($channel);
                $nextPublish += 1 / $rate;
            }
            foreach ($pending as $i => [$due, $message]) {
                if ($due <= $now) {
                    $message->ack();
                    unset($pending[$i]);
                }
            }
            try {
                $channel->wait(null, false, 0.05);
            } catch (Throwable) {
                // nothing to read right now
            }
        }

        $connection->close();

        return self::SUCCESS;
    }

    private function publish($channel): void
    {
        $tenant = self::TENANTS[array_rand(self::TENANTS)];
        $body = fn (array $extra = []) => new AMQPMessage(
            json_encode(['tenant' => $tenant, 'uuid' => bin2hex(random_bytes(6)), ...$extra]),
            ['content_type' => 'application/json', 'delivery_mode' => 2],
        );

        match (random_int(1, 10)) {
            1, 2, 3, 4, 5 => $channel->basic_publish($body(['job' => 'ship']), 'demo.events', 'orders.'.['eu', 'us', 'br'][array_rand(['eu', 'us', 'br'])].'.created'),
            6, 7 => $channel->basic_publish($body(['job' => 'render']), 'demo.jobs', 'pdf'),
            8 => $channel->basic_publish($body(['job' => 'audit']), 'demo.edge', 'orders.us.created'),
            9 => $channel->basic_publish($body(), 'demo.events', 'billing.unrouted'), // goes nowhere on purpose
            default => $channel->basic_publish($body(), '', 'demo.orders'),           // default exchange (needs trace)
        };
    }
}
