<?php

namespace Tests\Feature;

use App\Rabbit\Catalog;
use App\Warren\Brokers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\HasBroker;
use Tests\TestCase;

class TopologyTest extends TestCase
{
    use HasBroker;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->makeBroker(['vhost' => '/prod']);
    }

    private function fakeBroker(array $overrides = []): void
    {
        $consumer = fn (string $queue, string $user, string $host, string $tag) => [
            'queue' => ['name' => $queue], 'consumer_tag' => $tag, 'prefetch_count' => 1, 'ack_required' => true, 'active' => true,
            'channel_details' => ['user' => $user, 'peer_host' => $host, 'connection_name' => "$host -> broker"],
        ];

        Http::fake($overrides + [
            '*/api/overview' => Http::response(['rabbitmq_version' => '4.0.1', 'cluster_name' => 'rabbit@test']),
            '*/api/exchanges/%2Fprod' => Http::response([
                ['name' => '', 'type' => 'direct', 'durable' => true],
                ['name' => 'orders.x', 'type' => 'topic', 'durable' => true, 'message_stats' => ['publish_in_details' => ['rate' => 2.5]]],
            ]),
            '*/api/queues/%2Fprod' => Http::response([
                ['name' => 'orders', 'messages_ready' => 4, 'messages_unacknowledged' => 6, 'consumers' => 3, 'durable' => true, 'type' => 'quorum'],
            ]),
            '*/api/bindings/%2Fprod' => Http::response([
                ['source' => '', 'destination' => 'orders', 'destination_type' => 'queue', 'routing_key' => 'orders'],
                ['source' => 'orders.x', 'destination' => 'orders', 'destination_type' => 'queue', 'routing_key' => 'orders.created.#'],
            ]),
            // "management"-only user: the general list shows just their own consumer...
            '*/api/consumers/%2Fprod' => Http::response([]),
            '*/api/connections' => Http::response([]),
            // ...so the per-queue detail is the source of truth.
            '*/api/queues/%2Fprod/orders' => Http::response(['consumer_details' => [
                $consumer('orders', 'svc', '10.0.0.1', 't1'),
                $consumer('orders', 'svc', '10.0.0.1', 't2'),
                $consumer('orders', 'svc', '10.0.0.2', 't3'),
            ]]),
        ]);
    }

    public function test_snapshot_groups_consumers_into_workers_using_per_queue_details(): void
    {
        $this->fakeBroker();

        $json = $this->getJson($this->api('/topology'))->assertOk()->json();

        $this->assertSame('4.0.1', $json['broker']['version']);
        $this->assertCount(2, $json['workers']);
        $busiest = $json['workers'][0];
        $this->assertSame('svc@10.0.0.1', $busiest['name']);
        $this->assertSame(2, $busiest['consumers']);
        $this->assertSame(4, $busiest['processingEstimate']); // 6 unacked * 2 of 3 consumers
        $this->assertSame(2.5, $json['exchanges'][1]['rateIn']);
        $this->assertSame('quorum', $json['queues'][0]['type']);
    }

    public function test_default_exchange_bindings_are_hidden_and_routing_keys_kept_whole(): void
    {
        $this->fakeBroker();

        $bindings = $this->getJson($this->api('/topology'))->json('bindings');

        $this->assertCount(1, $bindings);
        $this->assertSame('orders.created.#', $bindings[0]['routingKey']);
    }

    public function test_per_queue_details_are_cached_between_snapshots(): void
    {
        $this->fakeBroker();
        config(['warren.cache.topology_ttl' => 0]);

        $this->getJson($this->api('/topology'))->assertOk();
        $this->getJson($this->api('/topology'))->assertOk();

        $detailCalls = Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/queues/%2Fprod/orders'));
        $this->assertCount(1, $detailCalls, 'per-queue details must be read once and then served from cache');
    }

    public function test_broker_down_returns_502_with_a_message(): void
    {
        Http::fake(['*' => Http::response('nope', 503)]);

        $this->getJson($this->api('/topology'))
            ->assertStatus(502)
            ->assertJsonPath('message', trans('warren.broker.status', ['status' => 503, 'path' => '/overview']));
    }

    public function test_queues_carry_their_business_name_and_the_areas_are_listed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalogo');
        file_put_contents($path, json_encode([
            'categorias' => ['pedidos' => ['nome' => 'Pedidos']],
            'filas' => [['padrao' => '^orders$', 'nome' => 'Fila de pedidos', 'categoria' => 'pedidos']],
        ]));
        $this->app->singleton(Catalog::class, fn () => new Catalog($path));
        $this->app->forgetInstance(Brokers::class);
        $this->fakeBroker();

        $json = $this->getJson($this->api('/topology'))->assertOk()->json();
        @unlink($path);

        $this->assertSame('Fila de pedidos', $json['queues'][0]['catalog']['name']);
        $this->assertSame('pedidos', $json['queues'][0]['catalog']['category']);
        $this->assertSame('Pedidos', $json['categories'][0]['name']);
    }

    public function test_a_changed_catalog_is_not_hidden_behind_a_snapshot_cached_with_another_catalog(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalogo');
        $write = function (string $name, int $mtime) use ($path) {
            file_put_contents($path, json_encode(['filas' => [['padrao' => '^orders$', 'nome' => $name]]]));
            touch($path, $mtime);
        };
        $this->app->singleton(Catalog::class, fn () => new Catalog($path));
        config(['warren.cache.topology_ttl' => 600]); // long enough that only the key can separate the two snapshots
        $this->app->forgetInstance(Brokers::class);
        $this->fakeBroker();

        $write('Pedidos (versão 1)', time() - 100);
        $first = $this->getJson($this->api('/topology'))->json('queues.0.catalog.name');

        $write('Pedidos (versão 2)', time() - 50);
        $second = $this->getJson($this->api('/topology'))->json('queues.0.catalog.name');
        @unlink($path);

        $this->assertSame('Pedidos (versão 1)', $first);
        $this->assertSame('Pedidos (versão 2)', $second);
    }

    public function test_the_page_renders(): void
    {
        $this->withoutVite()->get('/')->assertOk()->assertSee('data-page', false);
    }
}
