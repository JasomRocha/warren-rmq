<?php

namespace App\Rabbit;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Listens to one broker and records what is published (see config/warren.php, "Message tap"). It is driven by the
 * Supervisor: open(), then poll() over and over, then close(). One instance per broker.
 */
final class MessageTap
{
    private const TRACE_EXCHANGE = 'amq.rabbitmq.trace';

    private ?AbstractConnection $connection = null;

    private ?AMQPChannel $channel = null;

    private string $copyQueue = '';

    private int $nextRebind = 0;

    private int $nextBeat = 0;

    private int $received = 0;

    /** @var array<string, true> */
    private array $bound = [];

    /** @var list<string> */
    private array $own = [];

    /**
     * @param  array<string, mixed>  $amqp
     * @param  array<string, mixed>  $tap
     */
    public function __construct(
        private readonly array $amqp,
        private readonly array $tap,
        private readonly MessageRecorder $recorder,
        private readonly TopologyService $topology,
        private readonly TapStatus $status,
    ) {}

    public function isOpen(): bool
    {
        return $this->connection !== null;
    }

    public function bindingCount(): int
    {
        return count($this->bound);
    }

    /**
     * Connects, creates the two temporary queues, starts consuming and binds to what is bound to the broker's
     * exchanges. A supervisor drives it with poll(); any failure surfaces as an exception for the supervisor to handle.
     */
    public function open(): void
    {
        $this->connection = $this->connect();
        $this->channel = $this->connection->channel();

        $options = new AMQPTable(['x-max-length' => $this->tap['queue_max_length'], 'x-overflow' => 'drop-head']);
        $copy = $this->channel->queue_declare('', false, false, true, true, false, $options)[0];
        $trace = $this->channel->queue_declare('', false, false, true, true, false, $options)[0];
        $this->own = [$copy, $trace];
        $this->bound = [];
        $this->copyQueue = $copy;
        $this->status->setOwnQueues($this->own);

        $this->channel->basic_consume($copy, '', false, true, false, false, fn (AMQPMessage $m) => $this->onMessage($m));
        $this->channel->basic_consume($trace, '', false, true, false, false, fn (AMQPMessage $m) => $this->onMessage($m));
        $this->bind($this->connection, $trace, self::TRACE_EXCHANGE, '#');
        $this->rebind($this->connection, $copy);

        $this->status->update(['connected' => true, 'error' => null, 'since' => time(), 'received' => $this->received]);
        $this->nextRebind = time() + $this->tap['rebind_seconds'];
        $this->nextBeat = 0;
    }

    /** Waits up to $timeout seconds for messages, then does the periodic chores (heartbeat, new bindings, status). */
    public function poll(float $timeout): void
    {
        if ($this->connection === null || $this->channel === null) {
            return;
        }

        try {
            $this->channel->wait(null, false, $timeout);
        } catch (AMQPTimeoutException) {
            // nothing arrived in this slice
        }
        $this->connection->checkHeartBeat();

        if (time() >= $this->nextRebind) {
            $this->rebind($this->connection, $this->copyQueue);
            $this->nextRebind = time() + $this->tap['rebind_seconds'];
        }
        if (time() >= $this->nextBeat) {
            $this->status->update(['received' => $this->received]);
            $this->nextBeat = time() + 5;
        }
    }

    public function close(): void
    {
        try {
            $this->connection?->close();
        } catch (Throwable) {
            // already gone
        }
        $this->connection = null;
        $this->channel = null;
        $this->status->update(['connected' => false, 'tracing' => false]);
    }

    private function connect(): AbstractConnection
    {
        return AmqpConnector::connect($this->amqp, (string) $this->tap['connection_name']);
    }

    /** Bind the copy queue to every exchange we can: "#" on topic/fanout, each distinct key on direct. */
    private function rebind(AbstractConnection $connection, string $copy): void
    {
        try {
            $topology = $this->topology->snapshot();
        } catch (BrokerUnavailable) {
            return;
        }

        foreach ($topology['exchanges'] as $exchange) {
            $name = $exchange['name'];
            if ($name === '' || str_starts_with($name, 'amq.') || $exchange['type'] === 'headers') {
                continue;
            }
            $keys = $exchange['type'] === 'direct'
                ? array_values(array_unique(array_column(array_filter($topology['bindings'], fn ($b) => $b['source'] === $name), 'routingKey')))
                : ['#'];

            foreach ($keys as $key) {
                $id = $name.'|'.$key;
                if (! isset($this->bound[$id]) && $this->bind($connection, $copy, $name, $key)) {
                    $this->bound[$id] = true;
                }
            }
        }
    }

    /** A refused bind closes its channel, so each attempt gets a disposable one. */
    private function bind(AbstractConnection $connection, string $queue, string $exchange, string $key): bool
    {
        $channel = $connection->channel();
        try {
            $channel->queue_bind($queue, $exchange, $key);

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            try {
                $channel->close();
            } catch (Throwable) {
                // closed by the broker already
            }
        }
    }

    private function onMessage(AMQPMessage $message): void
    {
        $this->received++;
        $exchange = (string) $message->getExchange();
        $routingKey = (string) $message->getRoutingKey();

        if ($exchange === self::TRACE_EXCHANGE) {
            $this->status->update(['tracing' => true]);
            $this->onTrace($routingKey, $message);

            return;
        }

        [$properties, $headers] = $this->normalize($this->properties($message));
        $this->recorder->record($exchange, $routingKey, $properties, $headers, $message->getBody(), 'copy');
    }

    /** Trace routing keys are "publish.<exchange>" and "deliver.<queue>". */
    private function onTrace(string $routingKey, AMQPMessage $message): void
    {
        $dot = strpos($routingKey, '.');
        if ($dot === false) {
            return;
        }
        $kind = substr($routingKey, 0, $dot);
        $target = substr($routingKey, $dot + 1);
        $traceHeaders = $this->properties($message)['application_headers'] ?? [];

        if ($kind === 'deliver' && ! in_array($target, $this->own, true)) {
            $this->recorder->recordDelivery($message->getBody(), $target, (bool) ($traceHeaders['redelivered'] ?? false));
        } elseif ($kind === 'publish' && $target === '') {
            // The default exchange accepts no bindings, so only the trace can see what goes through it.
            $key = (string) (($traceHeaders['routing_keys'][0] ?? ''));
            if (! in_array($key, $this->own, true)) {
                [$properties, $headers] = $this->normalize($traceHeaders['properties'] ?? []);
                $this->recorder->record('', $key, $properties, $headers, $message->getBody(), 'trace');
            }
        }
    }

    /** @return array<string, mixed> */
    private function properties(AMQPMessage $message): array
    {
        $out = [];
        foreach ($message->get_properties() as $name => $value) {
            $out[$name] = $value instanceof AMQPTable ? $value->getNativeData() : $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $p  AMQP properties (snake_case)
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [properties, headers]
     */
    private function normalize(array $p): array
    {
        return [[
            'priority' => isset($p['priority']) ? (int) $p['priority'] : null,
            'replyTo' => $p['reply_to'] ?? null,
            'correlationId' => $p['correlation_id'] ?? null,
            'expiration' => $p['expiration'] ?? null,
            'persistent' => (int) ($p['delivery_mode'] ?? 1) === 2,
            'contentType' => $p['content_type'] ?? null,
        ], (array) ($p['application_headers'] ?? $p['headers'] ?? [])];
    }
}
