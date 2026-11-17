<?php

namespace App\Warren;

use App\Models\Broker;
use App\Support\Plural;
use Throwable;

/**
 * The long-running process behind `warren:tap`. For every broker in the database it keeps the tap connected
 * (reconnecting after failures, independently of the others) and samples the dashboard metrics. Brokers added,
 * edited or removed in the UI are picked up within a few seconds.
 */
final class Supervisor
{
    private const SYNC_EVERY = 8;      // seconds between re-reading the broker list

    private const SAMPLE_EVERY = 30;   // seconds between metric samples

    private const RETRY_AFTER = 5;     // seconds before reconnecting a failed tap

    private const SLICE = 0.15;        // seconds each tap listens before the next one gets a turn

    private bool $stop = false;

    /** @var array<int, array{broker: Broker, stamp: string, retryAt: int, sampleAt: int}> */
    private array $slots = [];

    public function __construct(
        private readonly Brokers $brokers,
        private readonly MetricSampler $sampler,
        private readonly bool $tapEnabled,
    ) {}

    public function stop(): void
    {
        $this->stop = true;
    }

    public function run(?int $seconds = null, ?callable $log = null): void
    {
        $log ??= static fn (string $line) => null;
        $deadline = $seconds === null ? null : time() + $seconds;
        $nextSync = 0;

        while (! $this->stop && ($deadline === null || time() < $deadline)) {
            if (time() >= $nextSync) {
                $this->sync($log);
                $nextSync = time() + self::SYNC_EVERY;
            }

            $worked = false;
            foreach (array_keys($this->slots) as $id) {
                $worked = $this->tick($id, $log) || $worked;
            }
            if (! $worked) {
                usleep(250_000); // nothing to listen to (all disabled or failing): do not spin
            }
        }

        foreach ($this->slots as $id => $slot) {
            $this->closeTap($slot['broker']);
        }
    }

    /** @param  callable(string): void  $log */
    private function sync(callable $log): void
    {
        Broker::seedFromEnvironment();
        $current = Broker::all()->keyBy('id');

        foreach (array_diff_key($this->slots, $current->all()) as $id => $slot) {
            $this->closeTap($slot['broker']);
            unset($this->slots[$id]);
            $this->brokers->forget($id);
            $log(trans('warren.tap.removed', ['name' => $slot['broker']->name]));
        }

        foreach ($current as $id => $broker) {
            $stamp = (string) $broker->updated_at?->getTimestamp().'|'.$broker->getRawOriginal('password');
            $slot = $this->slots[$id] ?? null;

            if ($slot !== null && $slot['stamp'] !== $stamp) {
                $this->closeTap($slot['broker']); // credentials or settings changed: reconnect with the new ones
                $slot = null;
            }
            $this->slots[$id] = [
                'broker' => $broker,
                'stamp' => $stamp,
                'retryAt' => $slot['retryAt'] ?? 0,
                'sampleAt' => $slot['sampleAt'] ?? 0,
            ];
        }
    }

    /** @param  callable(string): void  $log */
    private function tick(int $id, callable $log): bool
    {
        $slot = &$this->slots[$id];
        $broker = $slot['broker'];
        $context = $this->brokers->for($broker);

        if (time() >= $slot['sampleAt']) {
            $slot['sampleAt'] = time() + self::SAMPLE_EVERY;
            try {
                $this->sampler->sample($context);
            } catch (Throwable $e) {
                $log("[{$broker->name}] ".$e->getMessage());
            }
        }

        if (! $this->tapEnabled || ! $broker->tap_enabled) {
            $context->status->update(['connected' => false, 'tracing' => false, 'error' => null]);

            return false;
        }

        $tap = $context->tap();
        try {
            if (! $tap->isOpen()) {
                if (time() < $slot['retryAt']) {
                    return false;
                }
                $tap->open();
                $log("[{$broker->name}] ".Plural::fill(trans('warren.tap.connected', ['count' => $tap->bindingCount()]), fn () => $tap->bindingCount()));
            }
            $tap->poll(self::SLICE);

            return true;
        } catch (Throwable $e) {
            $tap->close();
            $context->status->update(['connected' => false, 'tracing' => false, 'error' => $e->getMessage()]);
            $slot['retryAt'] = time() + self::RETRY_AFTER;
            $log("[{$broker->name}] ".trans('warren.tap.stopped', ['error' => $e->getMessage()]));

            return false;
        }
    }

    private function closeTap(Broker $broker): void
    {
        $context = $this->brokers->for($broker);
        if ($context->tap()->isOpen()) {
            $context->tap()->close();
        }
    }
}
