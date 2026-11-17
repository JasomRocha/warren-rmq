<?php

namespace App\Rabbit;

use App\Models\CapturedMessage;

/**
 * Stores what the tap sees: each published message with the queues it was routed to, and later
 * the deliveries reported by the broker's firehose trace.
 */
final class MessageRecorder
{
    private ?int $rev = null;

    private int $sincePrune = 0;

    private readonly int $pruneEvery;

    /**
     * @param  array{job_paths: list<string>, uuid_paths: list<string>}  $identify
     */
    public function __construct(
        private readonly int $brokerId,
        private readonly TopologyService $topology,
        private readonly InstanceResolver $resolver,
        private readonly array $identify,
        private readonly int $historyLimit,
        private readonly int $bodyLimit = 65536,
    ) {
        // Pruning costs a query, so it runs in batches; the history overshoots the limit by at most ~10%.
        $this->pruneEvery = max(1, min(100, intdiv($historyLimit, 10)));
    }

    /**
     * @param  array{replyTo?: ?string, correlationId?: ?string, expiration?: ?string, persistent?: bool, contentType?: ?string, priority?: ?int}  $properties
     * @param  array<string, mixed>  $headers
     */
    public function record(string $exchange, string $routingKey, array $properties, array $headers, string $body, string $origin): CapturedMessage
    {
        $body = mb_scrub($body, 'UTF-8');
        $decoded = json_decode($body, true);
        $json = is_array($decoded) ? $decoded : null;

        $message = CapturedMessage::create([
            'broker_id' => $this->brokerId,
            'rev' => $this->nextRev(),
            'ts' => (int) (microtime(true) * 1000),
            'origin' => $origin,
            'exchange' => $exchange,
            'routing_key' => $routingKey,
            'instance' => $this->resolver->instance($json, $exchange, $routingKey, $headers),
            'job' => $this->resolver->firstOf($json, $this->identify['job_paths']),
            'uuid' => $this->resolver->firstOf($json, $this->identify['uuid_paths']),
            'priority' => $properties['priority'] ?? null,
            'size' => strlen($body),
            'queues' => $this->route($exchange, $routingKey),
            'deliveries' => [],
            'properties' => array_diff_key($properties, ['priority' => 1]),
            'headers' => $this->capHeaders($headers),
            'body' => mb_strcut($body, 0, $this->bodyLimit, 'UTF-8'),
            'body_truncated' => strlen($body) > $this->bodyLimit,
            'hash' => sha1($body),
        ]);

        $this->prune();

        return $message;
    }

    /** Attach a delivery to the best-matching recorded message (same body, not yet delivered to that queue). */
    public function recordDelivery(string $body, string $queue, bool $redelivered): void
    {
        $candidates = CapturedMessage::where('broker_id', $this->brokerId)->where('hash', sha1(mb_scrub($body, 'UTF-8')))->orderBy('id')->get();
        $target = $candidates->first(fn (CapturedMessage $m) => ! collect($m->deliveries)->contains('queue', $queue)) ?? $candidates->last();
        if (! $target) {
            return;
        }

        $queues = $target->queues;
        if (! in_array($queue, $queues, true)) {
            $queues[] = $queue;
        }
        $target->update([
            'deliveries' => [...$target->deliveries, ['queue' => $queue, 'ts' => (int) (microtime(true) * 1000), 'redelivered' => $redelivered]],
            'queues' => $queues,
            'rev' => $this->nextRev(),
        ]);
    }

    /** @return list<string> */
    private function route(string $exchange, string $routingKey): array
    {
        try {
            return Router::queuesFor($this->topology->snapshot(), $exchange, $routingKey);
        } catch (BrokerUnavailable) {
            return $exchange === '' ? [$routingKey] : [];
        }
    }

    private function nextRev(): int
    {
        $this->rev ??= (int) CapturedMessage::where('broker_id', $this->brokerId)->max('rev');

        return ++$this->rev;
    }

    /** Headers are kept whole unless they are huge: the body has its own limit, headers had none. */
    private function capHeaders(array $headers): array
    {
        $json = json_encode($headers, JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
        if (strlen($json) <= $this->bodyLimit) {
            return $headers;
        }

        return ['_warren' => 'headers omitted: '.strlen($json).' bytes'];
    }

    private function prune(): void
    {
        if (++$this->sincePrune < $this->pruneEvery) {
            return;
        }
        $this->sincePrune = 0;
        // ids are shared by every connection, so the cut is the id of this connection's N-th newest message
        $cut = CapturedMessage::where('broker_id', $this->brokerId)->orderByDesc('id')->skip($this->historyLimit)->value('id');
        if ($cut !== null) {
            CapturedMessage::where('broker_id', $this->brokerId)->where('id', '<=', $cut)->delete();
        }
    }
}
