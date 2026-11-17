<?php

namespace App\Rabbit;

/**
 * Reads "a.b.c" paths out of a message. Services often put JSON inside a JSON string
 * ({"mensagem": "{\"id\": 1}"}), so a string that holds JSON is opened on the way.
 */
final class MessagePath
{
    public static function get(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (is_string($data) && in_array(($data[0] ?? ''), ['{', '['], true)) {
                $decoded = json_decode($data, true);
                $data = is_array($decoded) ? $decoded : $data;
            }
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }
}
