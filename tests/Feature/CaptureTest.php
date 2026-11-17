<?php

namespace Tests\Feature;

use App\Models\CapturedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\HasBroker;
use Tests\TestCase;

class CaptureTest extends TestCase
{
    use HasBroker;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->makeBroker();
        config([
            'warren.identify.instance_paths' => ['tenant'],
            'warren.tap.history_limit' => 3,
        ]);
        Http::fake([
            '*/api/overview' => Http::response(['rabbitmq_version' => '4']),
            '*/api/exchanges/%2F' => Http::response([['name' => 'events', 'type' => 'topic', 'durable' => true]]),
            '*/api/queues/%2F' => Http::response([['name' => 'orders', 'consumers' => 0], ['name' => 'amq.gen-tap', 'consumers' => 1]]),
            '*/api/bindings/%2F' => Http::response([
                ['source' => 'events', 'destination' => 'orders', 'destination_type' => 'queue', 'routing_key' => 'orders.#'],
                ['source' => 'events', 'destination' => 'amq.gen-tap', 'destination_type' => 'queue', 'routing_key' => '#'],
            ]),
            '*/api/consumers/%2F' => Http::response([]),
            '*/api/connections' => Http::response([]),
        ]);
    }

    private function record(string $key = 'orders.created', string $body = '{"tenant":"acme","uuid":"u-1","job":"ship"}'): CapturedMessage
    {
        return $this->context()->recorder()->record('events', $key, ['priority' => 5, 'persistent' => true], ['h' => 'v'], $body, 'copy');
    }

    public function test_records_instance_routing_and_identifiers(): void
    {
        $m = $this->record();

        $this->assertSame('acme', $m->instance);
        $this->assertSame('ship', $m->job);
        $this->assertSame('u-1', $m->uuid);
        $this->assertSame(5, $m->priority);
        $this->assertEqualsCanonicalizing(['orders', 'amq.gen-tap'], $m->queues);
    }

    public function test_keeps_only_the_most_recent_messages(): void
    {
        for ($i = 0; $i < 105; $i++) {
            $this->record("orders.n$i");
        }

        $this->assertSame(3, CapturedMessage::count());
        $this->assertSame('orders.n104', CapturedMessage::orderByDesc('id')->first()->routing_key);
    }

    public function test_delivery_attaches_to_a_message_not_yet_delivered_to_that_queue(): void
    {
        $first = $this->record();
        $second = $this->record(); // identical body
        $recorder = $this->context()->recorder();

        $recorder->recordDelivery($first->body, 'orders', false);
        $recorder->recordDelivery($first->body, 'orders', true);

        $this->assertCount(1, $first->fresh()->deliveries);
        $this->assertCount(1, $second->fresh()->deliveries);
        $this->assertTrue($second->fresh()->deliveries[0]['redelivered']);
        $this->assertGreaterThan($first->rev, $second->fresh()->rev);
    }

    public function test_events_endpoint_returns_only_what_changed_and_the_tap_status(): void
    {
        $a = $this->record('orders.a');
        $this->record('orders.b');
        $this->context()->status->update(['connected' => true, 'since' => time(), 'received' => 2]);

        $all = $this->getJson($this->api('/events'))->assertOk();
        $this->assertCount(2, $all->json('messages'));
        $this->assertTrue($all->json('tap.active'));
        $this->assertArrayNotHasKey('body', $all->json('messages.0'));
        $this->assertArrayHasKey('instanceName', $all->json('messages.0'));

        $changes = $this->getJson($this->api('/events').'?since='.$a->rev)->json('messages');
        $this->assertSame(['orders.b'], array_column($changes, 'routingKey'));
    }

    public function test_message_detail_has_the_body_and_a_missing_one_is_404(): void
    {
        $m = $this->record();

        $this->getJson($this->api("/messages/{$m->id}"))->assertOk()->assertJsonPath('body', $m->body)->assertJsonPath('headers.h', 'v');
        $this->getJson($this->api('/messages/999999'))->assertNotFound();
    }

    public function test_the_taps_own_queues_are_hidden_from_the_topology(): void
    {
        $this->context()->status->setOwnQueues(['amq.gen-tap']);

        $topology = $this->getJson($this->api('/topology'))->assertOk()->json();

        $this->assertSame(['orders'], array_column($topology['queues'], 'name'));
        $this->assertSame(['orders'], array_column($topology['bindings'], 'destination'));
    }

    public function test_a_dead_tap_is_reported_inactive(): void
    {
        $this->travel(-1)->minutes();
        $this->context()->status->update(['connected' => true]);
        $this->travelBack();

        $this->assertFalse($this->context()->status->get()['active']);
    }
}
