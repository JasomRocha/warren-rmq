<?php

namespace Tests\Feature;

use App\Models\Broker;
use App\Models\MetricSample;
use App\Warren\Brokers;
use App\Warren\MetricSampler;
use App\Warren\Supervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\HasBroker;
use Tests\TestCase;

class SupervisorTest extends TestCase
{
    use HasBroker;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutEnvironmentBroker();
        Cache::flush();
        Http::fake([
            '*/api/overview' => Http::response(['rabbitmq_version' => '4']),
            '*/api/exchanges/*' => Http::response([]),
            '*/api/queues/*' => Http::response([['name' => 'q', 'messages_ready' => 5]]),
            '*/api/bindings/*' => Http::response([]),
            '*/api/consumers/*' => Http::response([]),
            '*/api/connections' => Http::response([]),
        ]);
    }

    private function supervisor(bool $tapEnabled): Supervisor
    {
        return new Supervisor(app(Brokers::class), new MetricSampler, $tapEnabled);
    }

    public function test_it_samples_every_connection_even_when_listening_is_switched_off(): void
    {
        $a = $this->makeBroker(['name' => 'A']);
        $b = $this->makeBroker(['name' => 'B']);

        $this->supervisor(false)->run(1);

        $this->assertSame([$a->id, $b->id], MetricSample::orderBy('broker_id')->pluck('broker_id')->all());
    }

    public function test_a_connection_that_cannot_be_listened_to_is_recorded_as_an_error_and_does_not_stop_the_others(): void
    {
        $broken = $this->makeBroker(['name' => 'Quebrada', 'amqp_host' => '127.0.0.1', 'amqp_port' => 1]);
        $quiet = $this->makeBroker(['name' => 'Quieta', 'tap_enabled' => false]);
        $log = [];

        $this->supervisor(true)->run(2, function (string $line) use (&$log) {
            $log[] = $line;
        });

        $status = app(Brokers::class)->for($broken)->status->get();
        $this->assertFalse($status['active']);
        $this->assertNotEmpty($status['error'], 'the reason is kept for the screen to show');
        $this->assertTrue(collect($log)->contains(fn ($l) => str_contains($l, 'Quebrada')), 'and it is logged with the connection name');

        $this->assertNull(app(Brokers::class)->for($quiet)->status->get()['error'], 'a connection with listening off is not an error');
        $this->assertSame(2, MetricSample::distinct('broker_id')->count(), 'both were still sampled');
    }

    public function test_the_connections_are_read_from_the_database_when_it_starts(): void
    {
        $this->assertSame(0, MetricSample::count());

        $this->supervisor(false)->run(1);
        $this->assertSame(0, MetricSample::count(), 'no connections, nothing to do');

        $this->makeBroker();
        $this->supervisor(false)->run(1);
        $this->assertSame(1, MetricSample::count());
    }

    public function test_the_command_runs_and_stops_by_itself_with_a_time_limit(): void
    {
        $this->makeBroker(['tap_enabled' => false]);

        $this->artisan('warren:tap', ['--seconds' => 1])->assertSuccessful();

        $this->assertSame(1, MetricSample::count());
    }

    public function test_the_broker_command_creates_the_first_connection_from_the_environment(): void
    {
        $this->artisan('warren:broker', ['--from-env' => true])->assertSuccessful();
        $this->assertSame(0, Broker::count(), 'tests run without RABBITMQ_URL, so there is nothing to create');
    }
}
