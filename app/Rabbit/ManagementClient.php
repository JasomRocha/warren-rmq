<?php

namespace App\Rabbit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin, read-only client for the RabbitMQ management HTTP API.
 */
final class ManagementClient
{
    /**
     * @param  array{url: string, username: string, password: string, vhost: string, timeout: int, scope?: string}  $config
     */
    public function __construct(private readonly array $config) {}

    /** Tells apart what is cached for different brokers (their ids). */
    public function scope(): string
    {
        return $this->config['scope'] ?? 'default';
    }

    public function vhost(): string
    {
        return $this->config['vhost'];
    }

    /** URL-encoded vhost, as the API expects it in paths ("/" becomes "%2F"). */
    public function encodedVhost(): string
    {
        return rawurlencode($this->config['vhost']);
    }

    /**
     * @return array<mixed>
     *
     * @throws BrokerUnavailable
     */
    public function get(string $path): array
    {
        try {
            return $this->request()->get($this->url($path))->throw()->json() ?? [];
        } catch (ConnectionException|RequestException $e) {
            throw new BrokerUnavailable($this->describe($path, $e), previous: $e);
        }
    }

    /**
     * Fetch several paths concurrently. Failed paths are returned as null so one bad
     * queue does not break the whole snapshot.
     *
     * @param  array<string, string>  $paths  keyed by caller-chosen id
     * @return array<string, array<mixed>|null>
     */
    public function getMany(array $paths, int $concurrency = 6): array
    {
        $results = [];

        foreach (array_chunk($paths, max(1, $concurrency), preserve_keys: true) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk) {
                foreach ($chunk as $id => $path) {
                    $pool->as((string) $id)
                        ->withBasicAuth($this->config['username'], $this->config['password'])
                        ->timeout($this->config['timeout'])
                        ->withoutRedirecting()
                        ->acceptJson()
                        ->get($this->url($path));
                }
            });

            foreach ($chunk as $id => $_) {
                $response = $responses[(string) $id] ?? null;
                $ok = $response instanceof Response && $response->successful();
                $results[$id] = $ok ? $response->json() : null;
            }
        }

        return $results;
    }

    private function request(): PendingRequest
    {
        return Http::withBasicAuth($this->config['username'], $this->config['password'])
            ->timeout($this->config['timeout'])
            ->withoutRedirecting()
            ->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim($this->config['url'], '/').'/api'.$path;
    }

    private function describe(string $path, \Throwable $e): string
    {
        $status = $e instanceof RequestException ? $e->response->status() : null;

        if ($status === 401 || $status === 403) {
            return trans('warren.broker.unauthorized', ['status' => $status]);
        }

        return $status
            ? trans('warren.broker.status', ['status' => $status, 'path' => $path])
            : trans('warren.broker.unreachable', ['url' => $this->config['url'], 'path' => $path]);
    }
}
