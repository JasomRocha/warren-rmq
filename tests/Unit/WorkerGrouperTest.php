<?php

namespace Tests\Unit;

use App\Rabbit\WorkerGrouper;
use PHPUnit\Framework\TestCase;

class WorkerGrouperTest extends TestCase
{
    private function consumer(string $worker, string $queue, int $prefetch = 1, bool $ack = true, ?string $connection = null, string $node = 'rabbit@n1'): array
    {
        return ['queue' => $queue, 'tag' => uniqid(), 'name' => $worker, 'user' => 'app', 'host' => '10.0.0.1',
            'prefetch' => $prefetch, 'manualAck' => $ack, 'active' => true, 'connection' => $connection ?? '10.0.0.1:5000 -> broker:5672', 'port' => 5000, 'node' => $node];
    }

    public function test_many_consumers_of_one_process_become_one_worker(): void
    {
        $consumers = [
            ...array_fill(0, 5, $this->consumer('app@10.0.0.1', 'orders')),
            ...array_fill(0, 3, $this->consumer('app@10.0.0.1', 'mails')),
            $this->consumer('app@10.0.0.2', 'orders'),
        ];

        $workers = (new WorkerGrouper)->group($consumers);

        $this->assertCount(2, $workers);
        $first = collect($workers)->firstWhere('name', 'app@10.0.0.1');
        $this->assertSame(8, $first['consumers']);
        $this->assertCount(2, $first['queues']);
        $this->assertSame(5, collect($first['queues'])->firstWhere('queue', 'orders')['consumers']);
    }

    public function test_processing_is_split_by_consumers_per_queue(): void
    {
        $consumers = [
            $this->consumer('a', 'orders'), $this->consumer('a', 'orders'), $this->consumer('a', 'orders'),
            $this->consumer('b', 'orders'),
        ];

        $workers = (new WorkerGrouper)->group($consumers, ['orders' => 8]);
        $byName = collect($workers)->keyBy('name');

        $this->assertSame(6, $byName['a']['processingEstimate']);
        $this->assertSame(2, $byName['b']['processingEstimate']);
        $this->assertSame('a', $workers[0]['name'], 'busiest worker comes first');
    }

    public function test_prefetch_range_and_ack_mode(): void
    {
        $workers = (new WorkerGrouper)->group([
            $this->consumer('a', 'q1', 1, false),
            $this->consumer('a', 'q2', 10, true),
        ]);

        $this->assertSame(1, $workers[0]['prefetchMin']);
        $this->assertSame(10, $workers[0]['prefetchMax']);
        $this->assertTrue($workers[0]['manualAck']);
    }

    public function test_no_consumers_means_no_workers(): void
    {
        $this->assertSame([], (new WorkerGrouper)->group([]));
    }

    public function test_a_worker_lists_its_connections_and_the_rabbitmq_nodes_that_hold_them(): void
    {
        $consumers = [
            $this->consumer('app@10.0.0.1', 'orders', connection: '10.0.0.1:5001 -> broker'),
            $this->consumer('app@10.0.0.1', 'mails', connection: '10.0.0.1:5001 -> broker'),
            $this->consumer('app@10.0.0.1', 'mails', connection: '10.0.0.1:5002 -> broker', node: 'rabbit@n2'),
        ];

        $worker = (new WorkerGrouper)->group($consumers)[0];

        $this->assertCount(2, $worker['connections']);
        $first = collect($worker['connections'])->firstWhere('name', '10.0.0.1:5001 -> broker');
        $this->assertSame(2, $first['consumers']);
        $this->assertSame('10.0.0.1', $first['host']);
        $this->assertEqualsCanonicalizing(['rabbit@n1', 'rabbit@n2'], $worker['nodes']);
    }

    public function test_the_same_process_consuming_many_queues_is_still_one_worker(): void
    {
        // 3 institutions x 3 kinds of queue, all read by the same 2 processes: 2 workers, not 18
        $consumers = [];
        foreach (['a', 'b', 'c'] as $institution) {
            foreach (['export', 'import', 'integration'] as $kind) {
                foreach (['app@10.0.0.1', 'app@10.0.0.2'] as $process) {
                    $consumers[] = $this->consumer($process, "{$kind}_{$institution}");
                }
            }
        }

        $workers = (new WorkerGrouper)->group($consumers);

        $this->assertCount(2, $workers);
        $this->assertSame([9, 9], array_column($workers, 'queues') === [] ? [] : array_map('count', array_column($workers, 'queues')));
        $this->assertSame([9, 9], array_column($workers, 'consumers'));
    }
}
