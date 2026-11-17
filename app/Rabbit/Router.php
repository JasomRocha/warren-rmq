<?php

namespace App\Rabbit;

/**
 * Works out which queues a published message ends up in, from the topology alone.
 *
 * The broker does not say where it routed a message, so this mirrors its rules:
 * direct (exact key), topic ("*" one word, "#" zero or more), fanout (everything),
 * following exchange-to-exchange bindings. Headers exchanges are not evaluated.
 */
final class Router
{
    /**
     * @param  array{exchanges: list<array<string, mixed>>, bindings: list<array<string, mixed>>}  $topology
     * @return list<string>
     */
    public static function queuesFor(array $topology, string $exchange, string $routingKey): array
    {
        if ($exchange === '') {
            return [$routingKey]; // the default exchange routes straight to the queue named by the key
        }

        $types = [];
        foreach ($topology['exchanges'] as $e) {
            $types[$e['name']] = $e['type'];
        }

        $bySource = [];
        foreach ($topology['bindings'] as $b) {
            $bySource[$b['source']][] = $b;
        }

        $queues = [];
        $visited = [];
        $walk = function (string $name) use (&$walk, &$queues, &$visited, $types, $bySource, $routingKey): void {
            if (isset($visited[$name])) {
                return;
            }
            $visited[$name] = true;
            $type = $types[$name] ?? 'direct';

            foreach ($bySource[$name] ?? [] as $b) {
                $matches = match ($type) {
                    'fanout' => true,
                    'topic' => self::topicMatches($b['routingKey'], $routingKey),
                    'direct' => $b['routingKey'] === $routingKey,
                    default => false,
                };
                if (! $matches) {
                    continue;
                }
                if ($b['destinationType'] === 'queue') {
                    $queues[$b['destination']] = true;
                } else {
                    $walk($b['destination']);
                }
            }
        };
        $walk($exchange);

        return array_keys($queues);
    }

    public static function topicMatches(string $pattern, string $key): bool
    {
        $p = explode('.', $pattern);
        $k = $key === '' ? [] : explode('.', $key);
        $memo = [];

        $match = function (int $i, int $j) use (&$match, &$memo, $p, $k): bool {
            $id = $i.':'.$j;
            if (isset($memo[$id])) {
                return $memo[$id];
            }
            if ($i === count($p)) {
                return $memo[$id] = ($j === count($k));
            }
            if ($p[$i] === '#') {
                return $memo[$id] = $match($i + 1, $j) || ($j < count($k) && $match($i, $j + 1));
            }
            if ($j === count($k)) {
                return $memo[$id] = false;
            }

            return $memo[$id] = ($p[$i] === '*' || $p[$i] === $k[$j]) && $match($i + 1, $j + 1);
        };

        return $match(0, 0);
    }
}
