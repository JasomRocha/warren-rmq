<?php

namespace Tests\Unit;

use App\Rabbit\Catalog;
use PHPUnit\Framework\TestCase;

class CatalogTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'catalogo');
        $this->write([
            'categorias' => ['pedidos' => ['nome' => 'Pedidos', 'descricao' => 'Depois do fechamento.', 'cor' => '#fff'], 'docs' => ['nome' => 'Documentos'], 'geral' => ['nome' => 'Geral', 'generica' => true]],
            'instancias' => ['remover' => ['_empresa_com_br$', '\.empresa\.com\.br$'], 'nomes' => ['piedpiper' => 'Pied Piper']],
            'workers' => [
                ['padrao' => '^app-pdf@(?<host>.+)$', 'nome' => 'Gerador de PDF · {host}', 'servico' => 'Gerador de PDF', 'descricao' => 'Converte HTML em PDF.'],
                ['padrao' => '([unclosed', 'nome' => 'quebrada'],
            ],
            'filas' => [
                ['padrao' => '^pedidos\.processar\.falhas$', 'nome' => 'Pedidos · falhas', 'categoria' => 'pedidos', 'papel' => 'falhas'],
                ['padrao' => '^pedidos\.processar$', 'nome' => 'Processamento de pedidos', 'categoria' => 'pedidos', 'descricao' => 'Cobra o cliente.'],
                ['padrao' => '^nfe_(?<instancia>.+)$', 'nome' => 'Nota fiscal · {instancia}', 'categoria' => 'docs'],
                ['padrao' => '^catalogo\.leitura$', 'nome' => 'Fila de consulta', 'papel' => 'passiva'],
                ['padrao' => '([unclosed', 'nome' => 'regex quebrada'],
                ['nome' => 'sem padrão'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function write(array $data): void
    {
        file_put_contents($this->file, json_encode($data));
        touch($this->file, time() + random_int(1, 1000)); // force a different mtime
    }

    public function test_names_a_queue_and_keeps_its_area_and_description(): void
    {
        $info = (new Catalog($this->file))->describe('pedidos.processar');

        $this->assertSame('Processamento de pedidos', $info['name']);
        $this->assertSame('pedidos', $info['category']);
        $this->assertSame('Cobra o cliente.', $info['description']);
        $this->assertSame('principal', $info['role']);
    }

    public function test_first_matching_rule_wins_and_can_set_the_role(): void
    {
        $info = (new Catalog($this->file))->describe('pedidos.processar.falhas');

        $this->assertSame('Pedidos · falhas', $info['name']);
        $this->assertSame('falhas', $info['role']);
    }

    public function test_captured_instance_fills_the_name_and_is_cleaned(): void
    {
        $info = (new Catalog($this->file))->describe('nfe_loja42_empresa_com_br');

        $this->assertSame('Nota fiscal · Loja42', $info['name']);
        $this->assertSame('Loja42', $info['instance']);
    }

    public function test_unknown_queues_have_no_name_but_a_guessed_role(): void
    {
        $catalog = new Catalog($this->file);

        $this->assertNull($catalog->describe('algo.estranho')['name']);
        $this->assertSame('principal', $catalog->describe('algo.estranho')['role']);
        $this->assertSame('espera', $catalog->describe('tarefas.aguardando')['role']);
        $this->assertSame('espera', $catalog->describe('x.delay.300000')['role']);
        $this->assertSame('falhas', $catalog->describe('servicos.failed.dlq')['role']);
        $this->assertSame('falhas', $catalog->describe('pdf.falhas')['role']);
        $this->assertSame('principal', $catalog->describe('faturamento')['role'], 'a word inside another word is not a role');
    }

    public function test_broken_or_incomplete_rules_are_skipped_instead_of_breaking_everything(): void
    {
        $catalog = new Catalog($this->file);

        $this->assertNull($catalog->describe('unclosed')['name']);
        $this->assertSame('Processamento de pedidos', $catalog->describe('pedidos.processar')['name']);
    }

    public function test_lists_the_areas_in_file_order(): void
    {
        $areas = (new Catalog($this->file))->categories();

        $this->assertSame(['pedidos', 'docs', 'geral'], array_column($areas, 'key'));
        $this->assertSame([false, false, true], array_column($areas, 'generic'));
        $this->assertSame('Pedidos', $areas[0]['name']);
        $this->assertSame('#fff', $areas[0]['color']);
    }

    public function test_without_a_file_or_with_garbage_it_still_works(): void
    {
        $this->assertSame([], (new Catalog('/does/not/exist.json'))->categories());
        $this->assertNull((new Catalog(null))->describe('qualquer')['name']);

        file_put_contents($this->file, '{not json');
        touch($this->file, time() + 5000);
        $this->assertNull((new Catalog($this->file))->describe('pedidos.processar')['name']);
    }

    public function test_edits_to_the_file_are_picked_up_without_restarting(): void
    {
        $catalog = new Catalog($this->file);
        $this->assertNull($catalog->describe('nova.fila')['name']);

        $this->write(['filas' => [['padrao' => '^nova\.fila$', 'nome' => 'Fila nova']]]);

        $this->assertSame('Fila nova', $catalog->describe('nova.fila')['name']);
    }

    public function test_describes_many_at_once(): void
    {
        $map = (new Catalog($this->file))->describeMany(['pedidos.processar', 'algo']);

        $this->assertSame(['pedidos.processar', 'algo'], array_keys($map));
    }

    public function test_a_passive_queue_is_one_that_has_no_consumer_by_design(): void
    {
        $this->assertSame('passiva', (new Catalog($this->file))->describe('catalogo.leitura')['role']);
    }

    public function test_an_instance_has_one_name_whatever_way_it_was_written(): void
    {
        $catalog = new Catalog($this->file);

        $this->assertSame('Acme', $catalog->instanceLabel('acme.empresa.com.br'), 'as it comes in the message body');
        $this->assertSame('Acme', $catalog->instanceLabel('acme_empresa_com_br'), 'as it comes in a queue name');
        $this->assertSame('Acme', $catalog->instanceLabel('ACME'));
    }

    public function test_the_catalog_can_give_an_instance_its_proper_name(): void
    {
        $catalog = new Catalog($this->file);

        $this->assertSame('Pied Piper', $catalog->instanceLabel('piedpiper.empresa.com.br'));
        $this->assertSame('Pied Piper', $catalog->instanceLabel('PIEDPIPER_empresa_com_br'));
    }

    public function test_short_names_are_acronyms_and_underscores_separate_words(): void
    {
        $catalog = new Catalog($this->file);

        $this->assertSame('FIP', $catalog->instanceLabel('fip.empresa.com.br'));
        $this->assertSame('Meu Cliente', $catalog->instanceLabel('meu_cliente.empresa.com.br'));
    }

    public function test_an_instance_that_does_not_look_like_a_simple_name_is_left_alone(): void
    {
        $catalog = new Catalog($this->file);

        $this->assertSame('outro.dominio.com', $catalog->instanceLabel('outro.dominio.com'));
        $this->assertSame('.empresa.com.br', $catalog->instanceLabel('.empresa.com.br'), 'nothing left after cleaning: keep what came');
    }

    public function test_a_worker_gets_a_name_a_service_and_a_description_from_its_rule(): void
    {
        $info = (new Catalog($this->file))->describeWorker('app-pdf@10.0.0.2', 'app-pdf', '10.0.0.2');

        $this->assertSame('Gerador de PDF · 10.0.0.2', $info['name']);
        $this->assertSame('Gerador de PDF', $info['service']);
        $this->assertSame('Converte HTML em PDF.', $info['description']);
    }

    public function test_a_worker_no_rule_knows_has_no_name_and_a_bad_rule_is_skipped(): void
    {
        $info = (new Catalog($this->file))->describeWorker('outro@10.0.0.9', 'outro', '10.0.0.9');

        $this->assertSame(['name' => null, 'service' => null, 'description' => null], $info);
    }
}
