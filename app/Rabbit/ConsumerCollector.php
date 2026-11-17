<?php

namespace App\Rabbit;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Lists every consumer of the vhost.
 *
 * With the "monitoring" tag, GET /consumers/{vhost} returns all of them. With only
 * "management" it returns just the current user's own, so for queues that report more
 * consumers than were listed we read GET /queues/{vhost}/{queue}, whose consumer_details
 * is complete. Those per-queue reads are cached and fetched concurrently.
 */
final class ConsumerCollector
{
    public function __construct(
        private readonly ManagementClient $client,
        private readonly Cache $cache,
        private readonly int $detailsTtl,
        private readonly int $concurrency,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $queues  raw /queues entries
     * @param  list<array<string, mixed>>  $listed  raw /consumers entries
     * @return list<array<string, mixed>> raw consumer entries
     */
    public function collect(array $queues, array $listed): array
    {
        $listedPerQueue = [];
        foreach ($listed as $c) {
            $name = $c['queue']['name'] ?? '';
            $listedPerQueue[$name] = ($listedPerQueue[$name] ?? 0) + 1;
        }

        $missing = [];
        foreach ($queues as $q) {
            if (($q['consumers'] ?? 0) > ($listedPerQueue[$q['name']] ?? 0)) {
                $missing[] = $q['name'];
            }
        }
        if ($missing === []) {
            return $listed;
        }

        $details = $this->details($missing);
        $missingSet = array_flip($missing);

        return [
            ...array_values(array_filter($listed, fn ($c) => ! isset($missingSet[$c['queue']['name'] ?? '']))),
            ...array_merge(...array_values($details)),
        ];
    }

    /**
     * @param  list<string>  $queueNames
     * @return array<string, list<array<string, mixed>>>
     */
    private function details(array $queueNames): array
    {
        $result = [];
        $toFetch = [];

        foreach ($queueNames as $name) {
            $cached = $this->cache->get($this->key($name));
            if (is_array($cached)) {
                $result[$name] = $cached;
            } else {
                $toFetch[$name] = "/queues/{$this->client->encodedVhost()}/".rawurlencode($name);
            }
        }

        foreach ($this->client->getMany($toFetch, $this->concurrency) as $name => $queue) {
            $name = (string) $name;
            // A failed read keeps whatever we last knew instead of making consumers vanish.
            $consumers = is_array($queue)
                ? ($queue['consumer_details'] ?? [])
                : ($this->cache->get($this->key($name.':stale')) ?? []);

            $result[$name] = $consumers;
            if (is_array($queue)) {
                $this->cache->put($this->key($name), $consumers, $this->detailsTtl);
                $this->cache->put($this->key($name.':stale'), $consumers, 3600);
            }
        }

        return $result;
    }

    private function key(string $name): string
    {
        return 'warren.consumers.'.md5($this->client->scope().'|'.$this->client->vhost().'|'.$name);
    }
}
