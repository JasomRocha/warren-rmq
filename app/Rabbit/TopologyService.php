<?php

namespace App\Rabbit;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Builds one snapshot of the broker: exchanges, queues, bindings and workers.
 */
final class TopologyService
{
    public function __construct(
        private readonly ManagementClient $client,
        private readonly ConsumerCollector $consumers,
        private readonly WorkerGrouper $grouper,
        private readonly Cache $cache,
        private readonly int $ttl,
        private readonly ?TapStatus $tap = null,
        private readonly ?Catalog $catalog = null,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws BrokerUnavailable
     */
    public function snapshot(): array
    {
        $key = 'warren.topology.'.md5($this->client->scope().'|'.$this->client->vhost().'|'.($this->catalog?->version() ?? 'none'));

        return $this->cache->remember($key, $this->ttl, function () {
            try {
                $snapshot = $this->build();
            } catch (BrokerUnavailable $e) {
                $this->cache->put($this->healthKey(), ['ok' => false, 'message' => $e->getMessage(), 'at' => time()], 86400);
                throw $e;
            }
            $this->cache->put($this->healthKey(), [
                'ok' => true, 'message' => null, 'at' => time(),
                'version' => $snapshot['broker']['version'], 'queues' => count($snapshot['queues']),
                'ready' => array_sum(array_column($snapshot['queues'], 'ready')), 'workers' => count($snapshot['workers']),
            ], 86400);

            return $snapshot;
        });
    }

    /** What the last attempt to reach this broker found: the connection list shows it without calling the broker. */
    public function health(): array
    {
        return $this->cache->get($this->healthKey()) ?? ['ok' => null, 'message' => null, 'at' => null];
    }

    private function healthKey(): string
    {
        return 'warren.health.'.$this->client->scope();
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $v = $this->client->encodedVhost();

        $overview = $this->client->get('/overview');
        $exchanges = $this->client->get("/exchanges/{$v}");
        // Queues the tap created for itself are plumbing, not part of the system being observed.
        $own = array_flip($this->tap?->ownQueues() ?? []);
        $queues = array_values(array_filter($this->client->get("/queues/{$v}"), fn ($q) => ! isset($own[$q['name']])));
        $bindings = array_values(array_filter($this->client->get("/bindings/{$v}"), fn ($b) => ! isset($own[$b['destination']])));
        $listed = array_values(array_filter($this->client->get("/consumers/{$v}"), fn ($c) => ! isset($own[$c['queue']['name'] ?? ''])));
        $connections = $this->optional('/connections');

        $consumers = array_map(
            fn (array $c) => $this->consumer($c, $connections),
            $this->consumers->collect($queues, $listed),
        );

        $catalogInfo = $this->catalog?->describeMany(array_column($queues, 'name')) ?? [];
        $unacked = [];
        foreach ($queues as $q) {
            $unacked[$q['name']] = (int) ($q['messages_unacknowledged'] ?? 0);
        }

        return [
            'broker' => [
                'version' => $overview['rabbitmq_version'] ?? null,
                'cluster' => $overview['cluster_name'] ?? null,
                'vhost' => $this->client->vhost(),
            ],
            'exchanges' => array_map(fn (array $e) => [
                'name' => $e['name'],
                'type' => $e['type'],
                'durable' => (bool) ($e['durable'] ?? false),
                'internal' => (bool) ($e['internal'] ?? false),
                'arguments' => (object) ($e['arguments'] ?? []),
                'rateIn' => $this->rate($e, 'publish_in'),
                'rateOut' => $this->rate($e, 'publish_out'),
            ], $exchanges),
            'queues' => array_map(fn (array $q) => [
                'name' => $q['name'],
                'catalog' => $catalogInfo[$q['name']] ?? ['name' => null, 'description' => null, 'category' => null, 'role' => Catalog::guessRole($q['name']), 'instance' => null],
                'type' => $q['type'] ?? ($q['arguments']['x-queue-type'] ?? 'classic'),
                'state' => $q['state'] ?? null,
                'durable' => (bool) ($q['durable'] ?? false),
                'arguments' => (object) ($q['arguments'] ?? []),
                'ready' => (int) ($q['messages_ready'] ?? 0),
                'unacked' => (int) ($q['messages_unacknowledged'] ?? 0),
                'consumers' => (int) ($q['consumers'] ?? 0),
                'rateIn' => $this->rate($q, 'publish'),
                'rateDeliver' => $this->rate($q, 'deliver_get'),
                'rateAck' => $this->rate($q, 'ack'),
                'rateRedeliver' => $this->rate($q, 'redeliver'),
                // "consumer_capacity" since 3.12; older brokers call it "consumer_utilisation"
                'consumerCapacity' => isset($q['consumer_capacity']) || isset($q['consumer_utilisation'])
                    ? round((float) ($q['consumer_capacity'] ?? $q['consumer_utilisation']), 3) : null,
            ], $queues),
            // The default exchange ("") has an implicit binding per queue; those are noise here.
            'bindings' => array_values(array_map(fn (array $b) => [
                'source' => $b['source'],
                'destination' => $b['destination'],
                'destinationType' => $b['destination_type'],
                'routingKey' => $b['routing_key'],
                'arguments' => (object) ($b['arguments'] ?? []),
            ], array_filter($bindings, fn (array $b) => $b['source'] !== ''))),
            'workers' => array_map(fn (array $w) => $w + ['catalog' => $this->catalog?->describeWorker($w['name'], $w['user'], $w['host']) ?? ['name' => null, 'service' => null, 'description' => null]], $this->grouper->group($consumers, $unacked)),
            'categories' => $this->catalog?->categories() ?? [],
            'panels' => $this->catalog?->panelsMeta(array_column($queues, 'name')) ?? [],
            'fetchedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  list<array<string, mixed>>  $connections
     * @return array<string, mixed>
     */
    private function consumer(array $c, array $connections): array
    {
        $channel = $c['channel_details'] ?? [];
        $user = $channel['user'] ?? null;
        $host = $channel['peer_host'] ?? null;

        // The connection name the client chose, when the API lets us see it; otherwise user@host.
        $clientName = null;
        foreach ($connections as $conn) {
            if (($conn['name'] ?? null) === ($channel['connection_name'] ?? '')) {
                $clientName = $conn['client_properties']['connection_name'] ?? null;
                break;
            }
        }

        return [
            'queue' => $c['queue']['name'] ?? '',
            'tag' => $c['consumer_tag'] ?? '',
            'name' => $clientName ? (string) $clientName : (($user ?? '?').'@'.($host ?? '?')),
            'user' => $user,
            'host' => $host,
            'connection' => $channel['connection_name'] ?? null,
            'port' => $channel['peer_port'] ?? null,
            'node' => $channel['node'] ?? null,
            'prefetch' => (int) ($c['prefetch_count'] ?? 0),
            'manualAck' => (bool) ($c['ack_required'] ?? false),
            'active' => ($c['active'] ?? true) !== false,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function optional(string $path): array
    {
        try {
            return $this->client->get($path);
        } catch (BrokerUnavailable) {
            return [];
        }
    }

    /** @param array<string, mixed> $entity */
    private function rate(array $entity, string $stat): float
    {
        return (float) ($entity['message_stats'][$stat.'_details']['rate'] ?? 0);
    }
}
