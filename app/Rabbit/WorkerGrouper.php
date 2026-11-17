<?php

namespace App\Rabbit;

/**
 * Turns the broker's flat list of consumers into workers.
 *
 * A worker is one client process (user@host), not one consumer: dozens of consumers
 * from the same process collapse into a single entry with one row per queue.
 */
final class WorkerGrouper
{
    /**
     * @param  list<array{queue: string, tag: string, name: string, user: ?string, host: ?string, prefetch: int, manualAck: bool, active: bool, connection?: ?string, port?: ?int, node?: ?string}>  $consumers
     * @param  array<string, int>  $unackedByQueue  messages delivered and not yet acked, per queue
     * @return list<array<string, mixed>>
     */
    public function group(array $consumers, array $unackedByQueue = []): array
    {
        $workers = [];
        $consumersPerQueue = [];

        foreach ($consumers as $c) {
            $consumersPerQueue[$c['queue']] = ($consumersPerQueue[$c['queue']] ?? 0) + 1;
        }

        foreach ($consumers as $c) {
            $w = &$workers[$c['name']];
            $w ??= [
                'id' => $c['name'],
                'name' => $c['name'],
                'user' => $c['user'],
                'host' => $c['host'],
                'consumers' => 0,
                'activeConsumers' => 0,
                'prefetchMin' => PHP_INT_MAX,
                'prefetchMax' => 0,
                'manualAck' => false,
                'queues' => [],
                'connections' => [],
            ];

            $w['consumers']++;
            $w['activeConsumers'] += $c['active'] ? 1 : 0;
            $w['prefetchMin'] = min($w['prefetchMin'], $c['prefetch']);
            $w['prefetchMax'] = max($w['prefetchMax'], $c['prefetch']);
            $w['manualAck'] = $w['manualAck'] || $c['manualAck'];

            // one TCP connection of this process to the broker: where it comes from and which RabbitMQ node holds it
            $connection = $c['connection'] ?? ($c['host'] ?? '?').':'.($c['port'] ?? '?');
            $k = &$w['connections'][$connection];
            $k ??= ['name' => $connection, 'host' => $c['host'], 'port' => $c['port'] ?? null, 'node' => $c['node'] ?? null, 'consumers' => 0];
            $k['consumers']++;
            unset($k);

            $q = &$w['queues'][$c['queue']];
            $q ??= ['consumers' => 0, 'activeConsumers' => 0, 'prefetchTotal' => 0, 'manualAck' => false, 'tags' => []];
            $q['consumers']++;
            $q['activeConsumers'] += $c['active'] ? 1 : 0;
            $q['prefetchTotal'] += $c['prefetch'];
            $q['manualAck'] = $q['manualAck'] || $c['manualAck'];
            $q['tags'][] = $c['tag'];
            unset($w, $q);
        }

        foreach ($workers as &$w) {
            // The broker only reports "unacked" per queue, so each worker gets a share
            // proportional to its consumers on that queue. It is an estimate.
            $processing = 0.0;
            foreach ($w['queues'] as $queue => $q) {
                $processing += ($unackedByQueue[$queue] ?? 0) * $q['consumers'] / max(1, $consumersPerQueue[$queue]);
            }
            $w['processingEstimate'] = (int) round($processing);
            $w['queues'] = $this->queueList($w['queues']);
            $w['connections'] = array_values($w['connections']);
            $w['nodes'] = array_values(array_unique(array_filter(array_column($w['connections'], 'node'))));
        }
        unset($w);

        $list = array_values($workers);
        usort($list, fn ($a, $b) => $b['processingEstimate'] <=> $a['processingEstimate'] ?: strcmp($a['name'], $b['name']));

        return $list;
    }

    /**
     * @param  array<string, array<string, mixed>>  $queues
     * @return list<array<string, mixed>>
     */
    private function queueList(array $queues): array
    {
        $out = [];
        foreach ($queues as $name => $q) {
            $out[] = ['queue' => $name, ...$q];
        }

        return $out;
    }
}
