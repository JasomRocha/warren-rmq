<?php

namespace App\Rabbit;

/**
 * Decides which "instance" (tenant, customer, service...) a message belongs to.
 *
 * RabbitMQ has no such concept, so it is configured: dot paths into the JSON body, header
 * names, and regexes with an (?<instance>...) group matched against "exchange|routing_key".
 * The first rule that yields a value wins. Then the result is normalized, so that the same instance written
 * two ways ("acme_corp_com" in a routing key, "acme.corp.com" in the body) is counted once. See config/warren.php.
 */
final class InstanceResolver
{
    /**
     * @param  list<string>  $bodyPaths
     * @param  list<string>  $headerNames
     * @param  list<string>  $keyRegexes
     */
    public function __construct(
        private readonly array $bodyPaths,
        private readonly array $headerNames,
        private readonly array $keyRegexes,
        /** @var list<array{0: string, 1: string}> regex => replacement pairs applied to the result */
        private readonly array $normalize = [],
    ) {}

    /**
     * @param  array<mixed>|null  $body  decoded JSON body, if it was JSON
     * @param  array<string, mixed>  $headers
     */
    public function instance(?array $body, string $exchange, string $routingKey, array $headers = []): ?string
    {
        return $this->normalized($this->find($body, $exchange, $routingKey, $headers));
    }

    /** @param  array<string, mixed>  $headers */
    private function find(?array $body, string $exchange, string $routingKey, array $headers): ?string
    {
        if ($found = $this->firstOf($body, $this->bodyPaths)) {
            return $found;
        }
        foreach ($this->headerNames as $name) {
            if ($found = self::text($headers[$name] ?? null)) {
                return $found;
            }
        }
        foreach ($this->keyRegexes as $regex) {
            if (($m = self::match($regex, $exchange.'|'.$routingKey)) && ($found = self::text($m['instance'] ?? null))) {
                return $found;
            }
        }

        return null;
    }

    private function normalized(?string $instance): ?string
    {
        if ($instance === null) {
            return null;
        }
        foreach ($this->normalize as $pair) {
            if (is_array($pair) && isset($pair[0], $pair[1])) {
                $instance = self::replace((string) $pair[0], (string) $pair[1], $instance);
            }
        }

        return $instance;
    }

    private static function replace(string $regex, string $replacement, string $subject): string
    {
        set_error_handler(static fn () => true); // a bad regex in the config must not take the tap down
        try {
            return preg_replace($regex, $replacement, $subject) ?? $subject;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param  array<mixed>|null  $body
     * @param  list<string>  $paths
     */
    public function firstOf(?array $body, array $paths): ?string
    {
        if ($body === null) {
            return null;
        }
        foreach ($paths as $path) {
            if ($found = self::text(data_get($body, $path))) {
                return $found;
            }
        }

        return null;
    }

    /** @return array<string|int, string>|null null when it does not match or the regex is invalid */
    private static function match(string $regex, string $subject): ?array
    {
        set_error_handler(static fn () => true); // a bad user regex must not take the tap down
        try {
            return preg_match($regex, $subject, $m) === 1 ? $m : null;
        } finally {
            restore_error_handler();
        }
    }

    private static function text(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
