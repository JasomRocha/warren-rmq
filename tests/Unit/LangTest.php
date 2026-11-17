<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LangTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function files(): array
    {
        $files = [];
        foreach (glob(__DIR__.'/../../lang/*/*.php') as $file) {
            $files[basename(dirname($file)).'/'.basename($file)] = [$file];
        }

        return $files;
    }

    /** @dataProvider files */
    #[DataProvider('files')]
    public function test_no_top_level_key_is_defined_twice(string $file): void
    {
        // A repeated key in a PHP array literal silently replaces the first one (and its messages show as raw keys).
        preg_match_all("/^    '([A-Za-z_]+)' =>/m", (string) file_get_contents($file), $found);

        $this->assertSame(array_values(array_unique($found[1])), $found[1], basename($file).' repeats a key');
    }

    public function test_portuguese_and_english_have_the_same_messages(): void
    {
        $keys = fn (string $path) => array_keys(self::flatten(require $path));

        foreach (['warren.php', 'auth.php'] as $name) {
            $pt = $keys(__DIR__.'/../../lang/pt_BR/'.$name);
            $en = $keys(__DIR__.'/../../lang/en/'.$name);
            sort($pt);
            sort($en);
            $this->assertSame($en, $pt, "{$name}: a message exists in only one language");
        }
    }

    /** @return array<string, mixed> */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            is_array($value) ? $out += self::flatten($value, "{$prefix}{$key}.") : $out["{$prefix}{$key}"] = $value;
        }

        return $out;
    }
}
