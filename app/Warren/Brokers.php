<?php

namespace App\Warren;

use App\Models\Broker;
use App\Rabbit\Catalog;
use App\Rabbit\ConsumerCollector;
use App\Rabbit\InstanceResolver;
use App\Rabbit\ManagementClient;
use App\Rabbit\MessageRecorder;
use App\Rabbit\MessageTap;
use App\Rabbit\TapStatus;
use App\Rabbit\TopologyService;
use App\Rabbit\WorkerGrouper;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Builds (and remembers) the services of each broker. A context is rebuilt when its row changes, so editing a
 * password in the UI takes effect without restarting anything.
 */
final class Brokers
{
    /** @var array<int, array{stamp: string, context: BrokerContext}> */
    private array $contexts = [];

    public function __construct(
        private readonly Catalog $catalog,
        private readonly InstanceResolver $resolver,
        private readonly Cache $cache,
    ) {}

    public function for(Broker $broker): BrokerContext
    {
        $stamp = (string) $broker->updated_at?->getTimestamp().'|'.$broker->getRawOriginal('password');
        $known = $this->contexts[$broker->id] ?? null;
        if ($known !== null && $known['stamp'] === $stamp) {
            return $known['context'];
        }

        $client = new ManagementClient($broker->managementConfig());
        $status = new TapStatus($this->cache, $broker->tap_enabled, (string) $broker->id);
        $topology = new TopologyService(
            $client,
            new ConsumerCollector($client, $this->cache, config('warren.cache.consumer_details_ttl'), config('warren.consumer_details_concurrency')),
            new WorkerGrouper,
            $this->cache,
            config('warren.cache.topology_ttl'),
            $status,
            $this->catalog,
        );

        return ($this->contexts[$broker->id] = ['stamp' => $stamp, 'context' => new BrokerContext($broker, $client, $topology, $status, $this)])['context'];
    }

    public function makeRecorder(BrokerContext $context): MessageRecorder
    {
        return new MessageRecorder(
            $context->broker->id,
            $context->topology,
            $this->resolver,
            ['job_paths' => config('warren.identify.job_paths'), 'uuid_paths' => config('warren.identify.uuid_paths')],
            config('warren.tap.history_limit'),
        );
    }

    public function makeTap(BrokerContext $context): MessageTap
    {
        return new MessageTap(
            $context->broker->amqpConfig(),
            config('warren.tap'),
            $context->recorder(),
            $context->topology,
            $context->status,
        );
    }

    public function forget(int $brokerId): void
    {
        unset($this->contexts[$brokerId]);
    }
}
