<?php

namespace App\Support;

/**
 * Singular and plural without "(s)" or "(ns)", with the same syntax as the interface (resources/js/lib/plural.ts):
 * {n|mensagem|mensagens}, or {n|nenhuma mensagem|# mensagem|# mensagens} with a form for zero, where # is the value.
 * Only 1 (and -1) takes the singular: "0 mensagens", "1,5 mensagens".
 */
final class Plural
{
    /** @param  list<string>  $forms  [one, other] or [zero, one, other] */
    public static function pick(int|float $n, array $forms): string
    {
        [$zero, $one, $other] = count($forms) >= 3 ? $forms : [null, $forms[0], $forms[1] ?? $forms[0]];
        if ($n == 0 && $zero !== null) {
            return $zero;
        }

        return abs($n) == 1 ? $one : $other;
    }

    /**
     * Fills every {name|one|other} (or {name|zero|one|other}) with the form for the number `$value($name)` gives.
     * A name without a numeric value keeps the "other" form.
     *
     * @param  callable(string): mixed  $value
     */
    public static function fill(string $text, callable $value): string
    {
        return (string) preg_replace_callback('/\{([^{}|]+)((?:\|[^{}|]*){2,3})\}/u', function (array $m) use ($value) {
            $forms = explode('|', substr($m[2], 1));
            $raw = $value($m[1]);
            $shown = is_scalar($raw) ? (string) $raw : '';
            $n = self::number($raw);
            $chosen = $n === null ? $forms[count($forms) - 1] : self::pick($n, $forms);

            return str_replace('#', $shown, $chosen);
        }, $text);
    }

    private static function number(mixed $raw): int|float|null
    {
        if (is_int($raw) || is_float($raw)) {
            return $raw;
        }
        if (is_string($raw) && is_numeric(trim($raw))) {
            return trim($raw) + 0;
        }

        return null;
    }
}
