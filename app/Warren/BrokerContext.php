<?php

namespace App\Warren;

use App\Models\Broker;
use App\Rabbit\ManagementClient;
use App\Rabbit\MessageRecorder;
use App\Rabbit\MessageTap;
use App\Rabbit\TapStatus;
use App\Rabbit\TopologyService;

/**
 * Everything that talks to ONE broker, built from its row: the management client, the topology snapshot, the
 * recorder and the tap. Each broker has its own cache keys, its own captured messages and its own tap status.
 */
final class BrokerContext
{
    private ?MessageRecorder $recorder = null;

    private ?MessageTap $tap = null;

    public function __construct(
        public readonly Broker $broker,
        public readonly ManagementClient $client,
        public readonly TopologyService $topology,
        public readonly TapStatus $status,
        private readonly Brokers $registry,
    ) {}

    public function recorder(): MessageRecorder
    {
        return $this->recorder ??= $this->registry->makeRecorder($this);
    }

    public function tap(): MessageTap
    {
        return $this->tap ??= $this->registry->makeTap($this);
    }
}
