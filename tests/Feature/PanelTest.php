<?php

namespace Tests\Feature;

use App\Models\CapturedMessage;
use App\Models\User;
use App\Rabbit\Catalog;
use App\Warren\Brokers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\HasBroker;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use HasBroker;
    use RefreshDatabase;

    private const JWT = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJsb2phIiwic3ViIjoic2VydmljZSJ9.oWQCWyMmHw82Z65BwwzXpgMgOJRTb';

    private string $catalogFile;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->makeBroker();

        $chave = fn (string $fim) => '^loja\.faturamento\.(?<servico>[^.]+)\.(?<instancia>[^.]+)\.(?<acao>.+)\.'.$fim.'$';
        $this->catalogFile = tempnam(sys_get_temp_dir(), 'catalogo');
        file_put_contents($this->catalogFile, json_encode([
            'instancias' => ['remover' => ['_empresa_com_br$']],
            'vocabulario' => ['pdf.gerar' => 'geração de PDF', 'nota_fiscal_arquivos' => 'nota fiscal'],
            'paineis' => [
                [
                    'id' => 'resultados', 'titulo' => 'Resultados do faturamento', 'tipo' => 'resultados', 'descricao' => 'Avisos.',
                    'filas' => ['^resultados_faturamento$'],
                    'regras' => [
                        ['chave' => $chave('success'), 'status' => 'sucesso', 'titulo' => '{servico}: {acao}', 'texto' => 'Terminou com sucesso: {acao} ({servico}) da loja {instancia}.',
                            'campos' => ['Arquivo' => 'corpo:mensagem.nomeArquivoFinal', 'Link' => 'corpo:mensagem.urlCallback', 'Senha' => 'corpo:mensagem.senha', 'Vazio' => 'corpo:mensagem.nada']],
                        ['chave' => $chave('error'), 'quando' => ['corpo:delayReprocessamento' => '>0'], 'status' => 'erro', 'titulo' => '{acao}',
                            'texto' => 'Deu erro, vai tentar de novo em {corpo:delayReprocessamento|duracao_ms}: {acao}.', 'campos' => ['Tipo do erro' => 'corpo:excecao|ultimo']],
                        ['chave' => $chave('error'), 'status' => 'erro', 'titulo' => '{acao}', 'texto' => 'Deu erro e não será tentado de novo: {acao}.'],
                    ],
                ],
                [
                    'id' => 'falhas', 'titulo' => 'Erros do gerador de PDF', 'tipo' => 'erros', 'filas' => ['^falhas$'],
                    'regras' => [['status' => 'erro', 'titulo' => 'Um PDF não foi gerado', 'texto' => 'Desistiu depois de {cab:tentativas} {cab:tentativas|tentativa|tentativas}.', 'campos' => ['Loja' => 'instancia', 'Identificador' => 'corpo:uuid']]],
                ],
            ],
        ]));
        $this->app->singleton(Catalog::class, fn () => new Catalog($this->catalogFile));
        $this->app->forgetInstance(Brokers::class);

        Http::fake([
            '*/api/overview' => Http::response(['rabbitmq_version' => '4']),
            '*/api/exchanges/%2F' => Http::response([['name' => '', 'type' => 'direct']]),
            '*/api/queues/%2F' => Http::response([
                ['name' => 'resultados_faturamento', 'type' => 'quorum', 'messages_ready' => 1420, 'consumers' => 0, 'arguments' => ['x-message-ttl' => 86400000]],
                ['name' => 'falhas', 'type' => 'quorum', 'messages_ready' => 616, 'consumers' => 0],
                ['name' => 'outra', 'messages_ready' => 1],
            ]),
            '*/api/bindings/%2F' => Http::response([]),
            '*/api/consumers/%2F' => Http::response([]),
            '*/api/connections' => Http::response([]),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->catalogFile);
        parent::tearDown();
    }

    private function message(string $key, array $body, array $queues = ['resultados_faturamento'], array $extra = []): CapturedMessage
    {
        static $rev = 0;
        $json = json_encode($body);

        return CapturedMessage::create($extra + [
            'broker_id' => $this->broker->id, 'rev' => ++$rev, 'ts' => 1_800_000_000_000 + $rev * 1000, 'origin' => 'copy', 'exchange' => 'faturamento_resultados', 'routing_key' => $key,
            'instance' => null, 'job' => null, 'uuid' => null, 'priority' => null, 'size' => strlen($json), 'queues' => $queues, 'deliveries' => [],
            'properties' => [], 'headers' => [], 'body' => $json, 'body_truncated' => false, 'hash' => sha1($json.$rev),
        ]);
    }

    private function success(): CapturedMessage
    {
        return $this->message('loja.faturamento.nota_fiscal_arquivos.acme_empresa_com_br.pdf.gerar.success', ['mensagem' => json_encode([
            'id' => '131057', 'nomeArquivoFinal' => 'notas_lote_95564.zip', 'senha' => 'abc',
            'urlCallback' => 'https://acme.example.com/callback?evento=ok&token='.self::JWT,
        ])]);
    }

    public function test_a_success_is_written_in_plain_language_with_the_time_it_was_queued(): void
    {
        $m = $this->success();

        $item = $this->getJson($this->api('/paineis/resultados').'')->assertOk()->json('items.0');

        $this->assertSame('sucesso', $item['status']);
        $this->assertSame('nota fiscal: geração de PDF', $item['title']);
        $this->assertSame('Terminou com sucesso: geração de PDF (nota fiscal) da loja Acme.', $item['text']);
        $this->assertSame($m->ts, $item['queuedAt'], 'the time the message was put on the queue');
        $this->assertSame($m->id, $item['id']);
    }

    public function test_facts_are_labelled_and_secrets_are_hidden_everywhere(): void
    {
        $this->success();

        $response = $this->getJson($this->api('/paineis/resultados').'')->assertOk();
        $facts = collect($response->json('items.0.facts'))->pluck('value', 'label');

        $this->assertSame('notas_lote_95564.zip', $facts['Arquivo']);
        $this->assertSame('••••••', $facts['Senha'], 'a field called "senha" is never shown');
        $this->assertStringContainsString('evento=ok', $facts['Link']);
        $this->assertArrayNotHasKey('Vazio', $facts->all(), 'facts without a value are left out');
        $this->assertStringNotContainsString('eyJ', $response->getContent(), 'no token leaves the server');
    }

    public function test_an_error_that_will_be_retried_says_when(): void
    {
        $this->message('loja.faturamento.nota_fiscal_arquivos.acme_empresa_com_br.pdf.gerar.error', ['mensagem' => '{}', 'excecao' => 'br.com.eadmaster.EADMasterErrorException', 'delayReprocessamento' => 30000]);

        $item = $this->getJson($this->api('/paineis/resultados').'')->json('items.0');

        $this->assertSame('erro', $item['status']);
        $this->assertSame('Deu erro, vai tentar de novo em 30 s: geração de PDF.', $item['text']);
        $this->assertSame('EADMasterErrorException', collect($item['facts'])->firstWhere('label', 'Tipo do erro')['value']);
    }

    public function test_an_error_with_no_retry_says_so(): void
    {
        $this->message('loja.faturamento.nota_fiscal_arquivos.acme_empresa_com_br.pdf.gerar.error', ['delayReprocessamento' => -1]);

        $this->assertSame('Deu erro e não será tentado de novo: geração de PDF.', $this->getJson($this->api('/paineis/resultados').'')->json('items.0.text'));
    }

    public function test_a_message_no_rule_knows_gets_a_generic_but_safe_description(): void
    {
        $this->message('qualquer.coisa.diferente.warning', ['quem' => 'ana', 'senha' => 'x', 'qtd' => 3]);

        $item = $this->getJson($this->api('/paineis/resultados').'')->json('items.0');

        $this->assertSame('aviso', $item['status'], 'guessed from the end of the routing key');
        $this->assertSame('diferente warning', $item['title']);
        $labels = collect($item['facts'])->pluck('value', 'label');
        $this->assertSame('ana', $labels['quem']);
        $this->assertSame('••••••', $labels['senha']);
    }

    public function test_the_errors_panel_reads_the_retry_count_from_the_headers_and_the_instance_of_the_message(): void
    {
        $this->message('pdf.falhas', ['uuid' => 'u-9', 'displayName' => 'GerarPdf'], ['falhas'], ['exchange' => '', 'headers' => ['tentativas' => 3], 'instance' => 'acme_empresa_com_br']);

        $item = $this->getJson($this->api('/paineis/falhas').'')->json('items.0');

        $this->assertSame('Um PDF não foi gerado', $item['title']);
        $this->assertSame('Desistiu depois de 3 tentativas.', $item['text']);
        $facts = collect($item['facts'])->pluck('value', 'label');
        $this->assertSame('Acme', $facts['Loja']);
        $this->assertSame('u-9', $facts['Identificador']);
    }

    public function test_only_messages_of_the_panels_queues_appear_newest_first_with_counts(): void
    {
        $this->success();
        $this->message('pdf.falhas', [], ['falhas']);
        $this->message('loja.faturamento.nota_fiscal_arquivos.acme_empresa_com_br.pdf.gerar.error', ['delayReprocessamento' => -1]);
        $this->message('noutra.fila.success', [], ['outra']);

        $json = $this->getJson($this->api('/paineis/resultados').'')->assertOk()->json();

        $this->assertSame(2, $json['total']);
        $this->assertSame(['erro', 'sucesso'], array_column($json['items'], 'status'));
        $this->assertEquals(['sucesso' => 1, 'erro' => 1], $json['counts']);
    }

    public function test_it_reports_the_real_queue_totals_and_the_ttl_since_the_history_only_covers_what_the_tap_saw(): void
    {
        $queue = $this->getJson($this->api('/paineis/resultados').'')->assertOk()->json('queues.0');

        $this->assertSame('resultados_faturamento', $queue['name']);
        $this->assertSame(1420, $queue['ready']);
        $this->assertSame(86400000, $queue['ttlMs']);
        $this->assertSame('quorum', $queue['type']);
    }

    public function test_the_limit_is_respected(): void
    {
        foreach (range(1, 5) as $i) {
            $this->message("loja.faturamento.x.y.acao{$i}.success", []);
        }

        $this->assertCount(2, $this->getJson($this->api('/paineis/resultados').'?limite=2')->json('items'));
        $this->assertSame(5, $this->getJson($this->api('/paineis/resultados').'?limite=2')->json('total'));
    }

    public function test_an_unknown_panel_is_a_404_and_login_is_required(): void
    {
        $this->getJson($this->api('/paineis/nao-existe').'')->assertNotFound();

        config(['warren.auth.enabled' => true]);
        $this->getJson($this->api('/paineis/resultados').'')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($this->api('/paineis/resultados'))->assertOk();
    }

    public function test_the_topology_lists_the_panels_with_the_real_queues_they_cover(): void
    {
        $panels = $this->getJson($this->api('/topology'))->assertOk()->json('panels');

        $this->assertSame(['resultados', 'falhas'], array_column($panels, 'id'));
        $this->assertSame(['resultados_faturamento'], $panels[0]['queues']);
        $this->assertSame('erros', $panels[1]['kind']);
        $this->assertArrayNotHasKey('rules', $panels[0], 'rules stay on the server');
    }
}
