<?php

namespace Tests\Feature;

use App\Models\CapturedMessage;
use App\Models\MetricSample;
use App\Rabbit\Catalog;
use App\Warren\Brokers;
use App\Warren\MetricSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\HasBroker;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use HasBroker;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->makeBroker();
    }

    private function fakeBroker(array $queues): void
    {
        Http::fake([
            '*/api/overview' => Http::response(['rabbitmq_version' => '4']),
            '*/api/exchanges/%2F' => Http::response([]),
            '*/api/queues/%2F' => Http::response($queues),
            '*/api/bindings/%2F' => Http::response([]),
            '*/api/consumers/%2F' => Http::response([]),
            '*/api/connections' => Http::response([]),
        ]);
    }

    private function stats(float $in, float $deliver, float $ack): array
    {
        return ['publish_details' => ['rate' => $in], 'deliver_get_details' => ['rate' => $deliver], 'ack_details' => ['rate' => $ack]];
    }

    public function test_a_sample_adds_up_the_working_queues_and_leaves_retries_and_failures_out(): void
    {
        $this->fakeBroker([
            ['name' => 'trabalho', 'messages_ready' => 10, 'messages_unacknowledged' => 3, 'consumers' => 2, 'message_stats' => $this->stats(1.5, 1, 1)],
            ['name' => 'outro', 'messages_ready' => 5, 'messages_unacknowledged' => 1, 'consumers' => 1, 'message_stats' => $this->stats(0.5, 0.5, 0.5)],
            ['name' => 'x.aguardando', 'messages_ready' => 99, 'consumers' => 0],
            ['name' => 'x.falhas', 'messages_ready' => 77, 'consumers' => 0],
        ]);

        $sample = app(MetricSampler::class)->sample($this->context());

        $this->assertSame(15, $sample->ready);
        $this->assertSame(4, $sample->unacked);
        $this->assertSame(3, $sample->consumers);
        $this->assertEquals(2.0, $sample->rate_in);
        $this->assertEquals(1.5, $sample->rate_deliver);
        $this->assertSame($this->broker->id, $sample->broker_id);
    }

    public function test_queues_nobody_consumes_are_left_out_so_result_queues_do_not_look_like_work_piling_up(): void
    {
        $this->fakeBroker([
            ['name' => 'trabalho', 'messages_ready' => 10, 'messages_unacknowledged' => 2, 'consumers' => 4, 'consumer_capacity' => 0.5,
                'message_stats' => $this->stats(2, 2, 2) + ['redeliver_details' => ['rate' => 0.2]]],
            ['name' => 'outro', 'messages_ready' => 0, 'consumers' => 1, 'consumer_capacity' => 1.0, 'message_stats' => $this->stats(1, 1, 1)],
            ['name' => 'resultados_faturamento', 'messages_ready' => 1420, 'consumers' => 0, 'message_stats' => $this->stats(3, 0, 0)],
        ]);

        $sample = app(MetricSampler::class)->sample($this->context());

        $this->assertSame(10, $sample->ready, 'the 1420 results waiting for nobody are not backlog');
        $this->assertSame(2, $sample->queues);
        $this->assertSame(5, $sample->consumers);
        $this->assertEquals(3.0, $sample->rate_in, 'publishing into the result queue is not work coming in');
        $this->assertEquals(0.2, $sample->rate_redeliver);
        $this->assertEquals(0.6, $sample->capacity, 'weighted by consumers: (0.5 × 4 + 1 × 1) / 5');
    }

    public function test_waiting_messages_are_counted_per_business_area(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalogo');
        file_put_contents($path, json_encode(['filas' => [['padrao' => '^a', 'categoria' => 'pedidos'], ['padrao' => '^b', 'categoria' => 'docs']]]));
        $this->app->singleton(Catalog::class, fn () => new Catalog($path));
        $this->app->forgetInstance(Brokers::class);
        $this->fakeBroker([
            ['name' => 'a1', 'messages_ready' => 4, 'consumers' => 1], ['name' => 'a2', 'messages_ready' => 6, 'consumers' => 1],
            ['name' => 'b1', 'messages_ready' => 3, 'consumers' => 1], ['name' => 'c1', 'messages_ready' => 8, 'consumers' => 1],
        ]);

        $sample = app(MetricSampler::class)->sample($this->context());
        @unlink($path);

        $this->assertSame(['pedidos' => 10, 'docs' => 3], $sample->areas, 'queues without an area are only in the total');
    }

    public function test_it_does_not_sample_twice_in_a_row(): void
    {
        $this->fakeBroker([['name' => 'q', 'messages_ready' => 1]]);
        $sampler = app(MetricSampler::class);

        $this->assertNotNull($sampler->sample($this->context()));
        $this->assertNull($sampler->sample($this->context()), 'too soon after the last one');
        $this->assertSame(1, MetricSample::count());
    }

    public function test_a_broker_that_is_down_adds_no_sample_and_shows_as_unhealthy(): void
    {
        Http::fake(['*' => Http::response('x', 503)]);

        $this->assertNull(app(MetricSampler::class)->sample($this->context()));
        $this->assertSame(0, MetricSample::count());
        $this->assertFalse($this->context()->topology->health()['ok'], 'the failure is shown as the connection health');
    }

    public function test_the_endpoint_returns_the_history_of_the_chosen_range(): void
    {
        $now = time();
        foreach ([[$now - 7200, 100], [$now - 1800, 40], [$now - 600, 20]] as [$t, $ready]) {
            MetricSample::create(['queues' => 1, 'broker_id' => $this->broker->id, 'ts' => $t, 'ready' => $ready, 'unacked' => 1, 'consumers' => 2, 'rate_in' => 1, 'rate_deliver' => 1, 'rate_ack' => 1]);
        }
        MetricSample::create(['queues' => 1, 'broker_id' => $this->broker->id + 1, 'ts' => $now - 60, 'ready' => 9999, 'unacked' => 0, 'consumers' => 0, 'rate_in' => 0, 'rate_deliver' => 0, 'rate_ack' => 0]);

        $hour = $this->getJson($this->api('/metrics?range=1h'))->assertOk()->json();
        $this->assertSame([40, 20], array_column($hour['samples'], 'ready'), 'the sample from 2 h ago and the other connection are not here');

        $six = $this->getJson($this->api('/metrics?range=6h'))->json();
        $this->assertSame([100, 40, 20], array_column($six['samples'], 'ready'));
    }

    public function test_readings_from_before_the_work_queue_rule_are_left_out_of_the_history(): void
    {
        $now = time();
        MetricSample::create(['broker_id' => $this->broker->id, 'ts' => $now - 300, 'ready' => 1420, 'unacked' => 0, 'consumers' => 0, 'rate_in' => 0, 'rate_deliver' => 0, 'rate_ack' => 0]);
        MetricSample::create(['queues' => 2, 'broker_id' => $this->broker->id, 'ts' => $now - 60, 'ready' => 5, 'unacked' => 1, 'consumers' => 3, 'rate_in' => 1, 'rate_deliver' => 1, 'rate_ack' => 1]);

        $this->assertSame([5], array_column($this->getJson($this->api('/metrics?range=1h'))->json('samples'), 'ready'));
    }

    public function test_an_unknown_range_falls_back_to_an_hour(): void
    {
        $this->assertSame('1h', $this->getJson($this->api('/metrics?range=banana'))->json('range'));
    }

    public function test_long_histories_are_averaged_into_a_light_number_of_points(): void
    {
        $now = time();
        foreach (range(0, 2000) as $i) {
            MetricSample::create(['queues' => 1, 'broker_id' => $this->broker->id, 'ts' => $now - 86400 + $i * 40, 'ready' => $i, 'unacked' => 0, 'consumers' => 0, 'rate_in' => 0, 'rate_deliver' => 0, 'rate_ack' => 0]);
        }

        $samples = $this->getJson($this->api('/metrics?range=24h'))->json('samples');

        $this->assertLessThanOrEqual(240, count($samples));
        $this->assertGreaterThan(100, count($samples));
    }

    public function test_it_ranks_who_sent_the_most_with_their_display_name_and_counts_per_time_slice(): void
    {
        $now = time();
        $row = fn (?string $instance, int $secondsAgo) => CapturedMessage::create([
            'broker_id' => $this->broker->id, 'rev' => 1, 'ts' => ($now - $secondsAgo) * 1000, 'origin' => 'copy', 'exchange' => 'x', 'routing_key' => 'k',
            'instance' => $instance, 'size' => 1, 'queues' => [], 'deliveries' => [], 'properties' => [], 'headers' => [], 'body' => '', 'body_truncated' => false, 'hash' => uniqid(),
        ]);
        foreach ([60, 120, 180] as $s) {
            $row('acme.example.com', $s);
        }
        $row('globex.example.com', 90);
        $row(null, 30);
        $row('antiga', 7200); // outside the hour

        $json = $this->getJson($this->api('/metrics?range=1h'))->json();

        $this->assertSame(['acme.example.com', 'globex.example.com', ''], array_column($json['instances'], 'key'));
        $this->assertSame([3, 1, 1], array_column($json['instances'], 'count'));
        $this->assertSame(5, array_sum(array_column($json['published'], 'count')));
    }
}
