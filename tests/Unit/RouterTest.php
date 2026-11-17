<?php

namespace Tests\Unit;

use App\Rabbit\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private function topology(): array
    {
        $bind = fn ($s, $d, $k, $t = 'queue') => ['source' => $s, 'destination' => $d, 'destinationType' => $t, 'routingKey' => $k, 'arguments' => []];

        return [
            'exchanges' => [
                ['name' => 'events', 'type' => 'topic'], ['name' => 'jobs', 'type' => 'direct'],
                ['name' => 'broadcast', 'type' => 'fanout'], ['name' => 'edge', 'type' => 'topic'],
            ],
            'bindings' => [
                $bind('events', 'all-orders', 'orders.#'), $bind('events', 'created-only', 'orders.*.created'),
                $bind('jobs', 'pdf', 'pdf'), $bind('jobs', 'mail', 'mail'),
                $bind('broadcast', 'a', ''), $bind('broadcast', 'b', ''),
                $bind('edge', 'events', 'orders.#', 'exchange'), $bind('edge', 'edge-audit', '#'),
            ],
        ];
    }

    #[DataProvider('cases')]
    public function test_routes_like_the_broker(string $exchange, string $key, array $expected): void
    {
        $got = Router::queuesFor($this->topology(), $exchange, $key);
        sort($got);
        sort($expected);
        $this->assertSame($expected, $got);
    }

    public static function cases(): array
    {
        return [
            'topic hash matches zero words' => ['events', 'orders', ['all-orders']],
            'topic hash and star' => ['events', 'orders.eu.created', ['all-orders', 'created-only']],
            'topic star needs exactly one word' => ['events', 'orders.created', ['all-orders']],
            'topic no match' => ['events', 'billing.created', []],
            'direct exact' => ['jobs', 'pdf', ['pdf']],
            'direct no match' => ['jobs', 'zip', []],
            'fanout ignores key' => ['broadcast', 'whatever', ['a', 'b']],
            'exchange to exchange chain' => ['edge', 'orders.eu.created', ['all-orders', 'created-only', 'edge-audit']],
            'default exchange goes to the queue named by the key' => ['', 'my-queue', ['my-queue']],
        ];
    }

    public function test_exchange_cycles_do_not_loop_forever(): void
    {
        $t = ['exchanges' => [['name' => 'x', 'type' => 'fanout'], ['name' => 'y', 'type' => 'fanout']], 'bindings' => [
            ['source' => 'x', 'destination' => 'y', 'destinationType' => 'exchange', 'routingKey' => '', 'arguments' => []],
            ['source' => 'y', 'destination' => 'x', 'destinationType' => 'exchange', 'routingKey' => '', 'arguments' => []],
            ['source' => 'y', 'destination' => 'q', 'destinationType' => 'queue', 'routingKey' => '', 'arguments' => []],
        ]];

        $this->assertSame(['q'], Router::queuesFor($t, 'x', 'k'));
    }
}
