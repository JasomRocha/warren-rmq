<?php

namespace App\Warren;

use App\Models\MetricSample;
use App\Rabbit\BrokerUnavailable;

/**
 * Takes one reading of a broker's totals so the dashboard can draw how things moved over time.
 * Only queues something consumes count: waiting-for-retry, failure and result queues (no consumer by
 * design) would otherwise read as work piling up that nobody does.
 */
final class MetricSampler
{
    private const EVERY = 25; // seconds: do not sample the same broker twice in a row

    private const KEEP_DAYS = 3;

    /** @var array<int, int> */
    private array $lastPrune = [];

    public function sample(BrokerContext $context): ?MetricSample
    {
        $id = $context->broker->id;

        try {
            $snapshot = $context->topology->snapshot();
        } catch (BrokerUnavailable) {
            return null; // the failure is already recorded as the broker's health
        }

        $last = MetricSample::where('broker_id', $id)->max('ts');
        if ($last !== null && time() - (int) $last < self::EVERY) {
            return null;
        }

        $work = array_filter(
            $snapshot['queues'],
            fn ($q) => ($q['catalog']['role'] ?? 'principal') === 'principal' && $q['consumers'] > 0,
        );
        $areas = [];
        foreach ($work as $q) {
            $area = $q['catalog']['category'] ?? null;
            if ($area !== null && $q['ready'] > 0) {
                $areas[$area] = ($areas[$area] ?? 0) + $q['ready'];
            }
        }

        $sample = MetricSample::create([
            'broker_id' => $id,
            'ts' => time(),
            'ready' => array_sum(array_column($work, 'ready')),
            'unacked' => array_sum(array_column($work, 'unacked')),
            'consumers' => array_sum(array_column($work, 'consumers')),
            'queues' => count($work),
            'rate_redeliver' => array_sum(array_column($work, 'rateRedeliver')),
            'capacity' => $this->capacity($work),
            'rate_in' => array_sum(array_column($work, 'rateIn')),
            'rate_deliver' => array_sum(array_column($work, 'rateDeliver')),
            'rate_ack' => array_sum(array_column($work, 'rateAck')),
            'areas' => $areas ?: null,
        ]);

        $this->prune($id);

        return $sample;
    }

    /** How busy the consumers were, weighted by how many each queue has (a queue with 20 consumers says more than one with 1). */
    private function capacity(array $queues): ?float
    {
        $weight = 0;
        $sum = 0.0;
        foreach ($queues as $q) {
            if ($q['consumerCapacity'] !== null) {
                $weight += $q['consumers'];
                $sum += $q['consumerCapacity'] * $q['consumers'];
            }
        }

        return $weight > 0 ? round($sum / $weight, 3) : null;
    }

    private function prune(int $brokerId): void
    {
        if (time() - ($this->lastPrune[$brokerId] ?? 0) < 3600) {
            return;
        }
        $this->lastPrune[$brokerId] = time();
        MetricSample::where('broker_id', $brokerId)->where('ts', '<', time() - self::KEEP_DAYS * 86400)->delete();
    }
}
