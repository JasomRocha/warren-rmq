<?php

namespace App\Rabbit;

use App\Models\CapturedMessage;
use App\Support\Plural;

/**
 * Turns a captured message into something a person who does not know the system can read:
 * a status, a sentence and a few labelled facts, with secrets hidden.
 *
 * The wording comes from the catalog (rules per panel, plus a vocabulary of plain-language words), because
 * only the owner of the system knows what "pdf.gerar" means. Without a matching rule it falls back to a
 * generic description built from the routing key.
 *
 * Templates use {placeholders}: {group} from the routing-key regex, {corpo:a.b} from the body (JSON inside a
 * JSON string is opened), {cab:name} from headers/properties, {fila}, {instancia}. Modifiers: {x|ultimo} keeps
 * what follows the last dot, {x|duracao_ms} turns milliseconds into "30 s", {x|humanizar} turns codes into words.
 * Plurals: {x|tentativa|tentativas} picks the word for the number in x (see App\Support\Plural).
 */
final class MessageFormatter
{
    private const MAX_VALUE = 220;

    public function __construct(private readonly Catalog $catalog) {}

    /**
     * @param  array{kind: string, rules: list<array<string, mixed>>}  $panel
     * @return array{id: int, queuedAt: int, status: string, title: string, text: string, facts: list<array{label: string, value: string}>, instance: ?string, routingKey: string, exchange: string, queue: ?string}
     */
    public function format(CapturedMessage $message, array $panel, ?string $queue = null): array
    {
        $body = json_decode($message->body, true);
        $context = [
            'body' => is_array($body) ? $body : [],
            'headers' => (array) ($message->headers ?? []),
            'properties' => (array) ($message->properties ?? []),
            'queue' => $queue,
            'instance' => $message->instance,
        ];

        [$rule, $groups] = $this->pick($panel['rules'], $message->routing_key, $context);

        $title = $rule && $rule['title'] ? $this->render($rule['title'], $groups, $context) : $this->humanize($this->lastSegments($message->routing_key));
        $text = $rule && $rule['text'] ? $this->render($rule['text'], $groups, $context) : $this->fallbackText($message, $queue);
        $facts = $rule && $rule['fields'] ? $this->facts($rule['fields'], $groups, $context) : $this->fallbackFacts($context['body']);

        return [
            'id' => $message->id,
            'queuedAt' => $message->ts,
            'status' => $this->status($rule['status'] ?? null, $message->routing_key, $panel['kind']),
            'title' => Redactor::mask($title),
            'text' => Redactor::mask($text),
            'facts' => $facts,
            'instance' => $message->instance !== null ? $this->catalog->instanceLabel($message->instance) : null,
            'routingKey' => $message->routing_key,
            'exchange' => $message->exchange,
            'queue' => $queue,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, mixed>  $context
     * @return array{0: ?array<string, mixed>, 1: array<string, string>}
     */
    private function pick(array $rules, string $routingKey, array $context): array
    {
        foreach ($rules as $rule) {
            $groups = [];
            if ($rule['regex'] !== null && ! SafeRegex::match($rule['regex'], $routingKey, $groups)) {
                continue;
            }
            if (! $this->conditionsHold($rule['when'], $context)) {
                continue;
            }

            return [$rule, array_filter($groups, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY)];
        }

        return [null, []];
    }

    /**
     * @param  array<string, mixed>  $conditions  "corpo:path" or "cab:name" => ">0", "<=5", "!x", or a value
     * @param  array<string, mixed>  $context
     */
    private function conditionsHold(array $conditions, array $context): bool
    {
        foreach ($conditions as $ref => $expected) {
            $actual = $this->lookup((string) $ref, [], $context);
            $expected = (string) $expected;

            if (preg_match('/^(>=|<=|>|<)\s*(-?\d+(?:\.\d+)?)$/', $expected, $m)) {
                if (! is_numeric($actual)) {
                    return false;
                }
                $ok = match ($m[1]) {
                    '>' => $actual > $m[2], '<' => $actual < $m[2], '>=' => $actual >= $m[2], default => $actual <= $m[2],
                };
                if (! $ok) {
                    return false;
                }
            } elseif (str_starts_with($expected, '!')) {
                if ($this->scalar($actual) === substr($expected, 1)) {
                    return false;
                }
            } elseif ($this->scalar($actual) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $groups
     * @param  array<string, mixed>  $context
     */
    private function render(string $template, array $groups, array $context): string
    {
        // plurals first ({cab:tentativas|tentativa|tentativas}): their forms are free text, not modifiers
        $template = Plural::fill($template, fn (string $ref) => $this->lookup($ref, $groups, $context));

        return (string) preg_replace_callback('/\{([^{}|]+)(?:\|([a-z_]+))?\}/', function (array $m) use ($groups, $context) {
            return $this->display($this->lookup($m[1], $groups, $context), $m[1], $m[2] ?? null);
        }, $template);
    }

    /**
     * @param  array<string, string>  $fields  label => reference
     * @param  array<string, string>  $groups
     * @param  array<string, mixed>  $context
     * @return list<array{label: string, value: string}>
     */
    private function facts(array $fields, array $groups, array $context): array
    {
        $facts = [];
        foreach ($fields as $label => $reference) {
            $reference = (string) $reference;
            [$ref, $modifier] = array_pad(explode('|', $reference, 2), 2, null);
            $value = $this->lookup($ref, $groups, $context);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $shown = Redactor::isSensitiveKey($ref) ? Redactor::MASK : Redactor::mask($this->display($value, $ref, $modifier));
            $facts[] = ['label' => (string) $label, 'value' => $shown];
        }

        return $facts;
    }

    /**
     * @param  array<string, string>  $groups
     * @param  array<string, mixed>  $context
     */
    private function lookup(string $ref, array $groups, array $context): mixed
    {
        if (str_starts_with($ref, 'corpo:')) {
            return MessagePath::get($context['body'], substr($ref, 6));
        }
        if (str_starts_with($ref, 'cab:')) {
            $name = substr($ref, 4);

            return $context['headers'][$name] ?? $context['properties'][$name] ?? null;
        }
        if ($ref === 'fila') {
            return $context['queue'];
        }

        return $groups[$ref] ?? ($ref === 'instancia' ? $context['instance'] : null);
    }

    private function display(mixed $value, string $ref, ?string $modifier): string
    {
        if ($value === null) {
            return '—';
        }
        $text = $this->scalar($value);

        if ($modifier === 'ultimo') {
            $text = substr(strrchr('.'.$text, '.'), 1);
        } elseif ($modifier === 'duracao_ms' && is_numeric($value)) {
            return $this->duration((float) $value / 1000);
        }

        if ($ref === 'instancia') {
            $text = $this->catalog->instanceLabel($text);
        }
        $vocabulary = $this->catalog->vocabulary();
        if (isset($vocabulary[$text])) {
            return $vocabulary[$text];
        }
        // codes captured from routing keys ("nota_fiscal_arquivos") read better as words; free text does not change
        $isCode = ! str_contains($ref, ':') && $ref !== 'fila' && $ref !== 'instancia';
        if ($modifier === 'humanizar' || $isCode) {
            return $this->humanize($text);
        }

        return mb_strlen($text) > self::MAX_VALUE ? mb_substr($text, 0, self::MAX_VALUE - 1).'…' : $text;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    private function duration(float $seconds): string
    {
        return match (true) {
            $seconds < 90 => max(1, (int) round($seconds)).' s',
            $seconds < 5400 => (int) round($seconds / 60).' min',
            default => round($seconds / 3600, 1).' h',
        };
    }

    private function humanize(string $code): string
    {
        return trim(preg_replace('/[_.\-]+/', ' ', $code) ?? $code);
    }

    private function lastSegments(string $routingKey): string
    {
        return implode('.', array_slice(explode('.', $routingKey), -2));
    }

    private function status(?string $fromRule, string $routingKey, string $panelKind): string
    {
        if (in_array($fromRule, ['sucesso', 'erro', 'aviso', 'info'], true)) {
            return $fromRule;
        }
        if (preg_match('/(^|[._-])(error|erro|fatal|failure|falha|falhas)($|[._-])/i', $routingKey)) {
            return 'erro';
        }
        if (preg_match('/(^|[._-])(warning|aviso)($|[._-])/i', $routingKey)) {
            return 'aviso';
        }
        if (preg_match('/(^|[._-])(success|sucesso|ok)($|[._-])/i', $routingKey)) {
            return 'sucesso';
        }

        return $panelKind === 'erros' ? 'erro' : 'info';
    }

    private function fallbackText(CapturedMessage $message, ?string $queue): string
    {
        return $queue !== null ? "Mensagem registrada em {$queue}." : 'Mensagem registrada.';
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array{label: string, value: string}>
     */
    private function fallbackFacts(array $body): array
    {
        $facts = [];
        foreach ($body as $key => $value) {
            if (! is_scalar($value) || $value === '' || count($facts) >= 6) {
                continue;
            }
            $facts[] = ['label' => $this->humanize((string) $key), 'value' => Redactor::isSensitiveKey((string) $key) ? Redactor::MASK : Redactor::mask($this->display($value, 'corpo:'.$key, null))];
        }

        return $facts;
    }
}
