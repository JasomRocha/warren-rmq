<?php

namespace App\Rabbit;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Shared state between the tap daemon and the web app (they are separate processes).
 */
final class TapStatus
{
    private const STALE_AFTER = 20;

    public function __construct(private readonly Cache $cache, private readonly bool $enabled, private readonly string $scope = 'default') {}

    private function statusKey(): string
    {
        return "warren.tap.{$this->scope}.status";
    }

    private function queuesKey(): string
    {
        return "warren.tap.{$this->scope}.queues";
    }

    /** @param array<string, mixed> $patch */
    public function update(array $patch): void
    {
        $this->cache->forever($this->statusKey(), [...($this->cache->get($this->statusKey()) ?? []), ...$patch, 'heartbeat' => now()->getTimestamp()]);
    }

    public function beat(): void
    {
        $this->update([]);
    }

    /** @return array{enabled: bool, active: bool, error: ?string, since: ?int, tracing: bool, received: int} */
    public function get(): array
    {
        $s = $this->cache->get($this->statusKey()) ?? [];
        $alive = isset($s['heartbeat']) && now()->getTimestamp() - $s['heartbeat'] <= self::STALE_AFTER;

        return [
            'enabled' => $this->enabled,
            'active' => $alive && ($s['connected'] ?? false),
            'error' => $s['error'] ?? null,
            'since' => $s['since'] ?? null,
            'tracing' => $alive && ($s['tracing'] ?? false),
            'received' => (int) ($s['received'] ?? 0),
        ];
    }

    /** @param list<string> $names */
    public function setOwnQueues(array $names): void
    {
        $this->cache->forever($this->queuesKey(), $names);
    }

    /** Queues created by the tap itself; hidden from the topology so they do not show up as real ones. */
    public function ownQueues(): array
    {
        return $this->cache->get($this->queuesKey()) ?? [];
    }
}
