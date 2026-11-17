<?php

namespace App\Rabbit;

/**
 * preg_* for patterns that come from a user file: a broken pattern means "no match", never an error page.
 */
final class SafeRegex
{
    /** Turns a bare pattern from a JSON file into a case-insensitive unicode regex. */
    public static function wrap(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~iu';
    }

    public static function isValid(string $regex): bool
    {
        // preg_match() returns 0 for a valid pattern that does not match, and false for an invalid one
        return self::quiet(fn () => preg_match($regex, '') !== false);
    }

    /** @param  array<int|string, string>|null  $groups */
    public static function match(string $regex, string $subject, ?array &$groups = null): bool
    {
        $found = [];
        $ok = self::quiet(function () use ($regex, $subject, &$found) {
            return preg_match($regex, $subject, $found) === 1;
        });
        $groups = $ok ? $found : [];

        return $ok;
    }

    public static function replace(string $regex, string $replacement, string $subject): string
    {
        return self::quiet(fn () => preg_replace($regex, $replacement, $subject) ?? $subject);
    }

    private static function quiet(callable $run): mixed
    {
        set_error_handler(static fn () => true);
        $limit = ini_set('pcre.backtrack_limit', '100000');
        try {
            return $run();
        } finally {
            if ($limit !== false) {
                ini_set('pcre.backtrack_limit', $limit);
            }
            restore_error_handler();
        }
    }
}
