<?php

namespace Tests\Unit;

use App\Support\Plural;
use PHPUnit\Framework\TestCase;

class PluralTest extends TestCase
{
    public function test_only_one_takes_the_singular(): void
    {
        $this->assertSame(['tentativas', 'tentativa', 'tentativas', 'tentativas'], array_map(fn ($n) => Plural::pick($n, ['tentativa', 'tentativas']), [0, 1, 2, 1.5]));
        $this->assertSame('nenhuma', Plural::pick(0, ['nenhuma', 'uma', 'várias']));
    }

    public function test_it_fills_the_forms_from_the_values(): void
    {
        $values = ['cab:tentativas' => 1, 'n' => '3', 'vazio' => null];
        $fill = fn (string $text) => Plural::fill($text, fn (string $ref) => $values[$ref] ?? null);

        $this->assertSame('depois de 1 tentativa', $fill('depois de 1 {cab:tentativas|tentativa|tentativas}'));
        $this->assertSame('3 filas', $fill('{n|nenhuma fila|# fila|# filas}'));
        $this->assertSame('itens', $fill('{vazio|item|itens}'), 'without a number it keeps the plural');
        $this->assertSame('{x|ultimo}', $fill('{x|ultimo}'), 'a modifier is not a plural');
    }
}
