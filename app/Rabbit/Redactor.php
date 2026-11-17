<?php

namespace App\Rabbit;

/**
 * Hides secrets before a message is shown to a person: tokens in URLs, JWTs, passwords.
 * Messages between services routinely carry callback URLs with a signed token in them.
 */
final class Redactor
{
    public const MASK = '••••••';

    private const SENSITIVE_KEY = '/(token|senha|password|passwd|secret|segredo|authorization|api[_-]?key|jwt|signature)/i';

    public static function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY, $key) === 1;
    }

    public static function mask(string $text): string
    {
        // token=..., access_token=..., signature=... inside URLs and query strings (also when the & was escaped)
        $text = preg_replace('/((?:token|access_token|jwt|signature|sig|apikey|api_key|senha|password)(?:=|\\\\u003d))[^&\s"\'\\\\]+/i', '$1'.self::MASK, $text) ?? $text;

        // JWTs: three base64url blocks, the first starting with {"
        return preg_replace('/eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/', self::MASK, $text) ?? $text;
    }

    /** Masks every sensitive value in decoded JSON, keeping its shape. */
    public static function maskData(mixed $data, ?string $key = null): mixed
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = self::maskData($v, (string) $k);
            }

            return $out;
        }
        if (is_string($data)) {
            return ($key !== null && self::isSensitiveKey($key)) ? self::MASK : self::mask($data);
        }

        return $data;
    }
}
