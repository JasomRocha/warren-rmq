<?php

namespace Tests\Unit;

use App\Rabbit\MessagePath;
use App\Rabbit\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    private const JWT = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJsb2phIiwic3ViIjoic2VydmljZSJ9.oWQCWyMmHw82Z65BwwzXpgMgOJRTb';

    public function test_hides_tokens_in_urls_and_keeps_the_rest_of_the_url_readable(): void
    {
        $masked = Redactor::mask('https://acme.example.com/callback?evento=folhas_prontas&token='.self::JWT.'&x=1');

        $this->assertStringNotContainsString('eyJ', $masked);
        $this->assertStringContainsString('evento=folhas_prontas', $masked);
        $this->assertStringContainsString('&x=1', $masked);
        $this->assertStringContainsString('token='.Redactor::MASK, $masked);
    }

    public function test_hides_tokens_when_the_url_came_json_escaped(): void
    {
        $this->assertStringNotContainsString('abc123secret', Redactor::mask('callback?evento=ok&token=abc123secret&y=2'));
    }

    public function test_hides_a_bare_jwt_anywhere_in_a_text(): void
    {
        $this->assertSame('Bearer '.Redactor::MASK.' fim', Redactor::mask('Bearer '.self::JWT.' fim'));
    }

    public function test_leaves_normal_text_alone(): void
    {
        $text = 'A geração de PDF do pedido 22457 terminou. Arquivo: notas_lote_95564.zip';

        $this->assertSame($text, Redactor::mask($text));
    }

    public function test_masks_values_of_sensitive_keys_in_data_whatever_their_shape(): void
    {
        $data = Redactor::maskData(['usuario' => 'ana', 'senha' => 'x', 'api_key' => 'y', 'aninhado' => ['Authorization' => 'Bearer z', 'ok' => 1]]);

        $this->assertSame('ana', $data['usuario']);
        $this->assertSame(Redactor::MASK, $data['senha']);
        $this->assertSame(Redactor::MASK, $data['api_key']);
        $this->assertSame(Redactor::MASK, $data['aninhado']['Authorization']);
        $this->assertSame(1, $data['aninhado']['ok']);
    }

    public function test_reads_paths_including_json_inside_a_json_string(): void
    {
        $body = ['mensagem' => json_encode(['id' => '131057', 'arquivos' => [['nome' => 'a.zip']]]), 'excecao' => 'x.Y'];

        $this->assertSame('131057', MessagePath::get($body, 'mensagem.id'));
        $this->assertSame('a.zip', MessagePath::get($body, 'mensagem.arquivos.0.nome'));
        $this->assertSame('x.Y', MessagePath::get($body, 'excecao'));
        $this->assertNull(MessagePath::get($body, 'mensagem.naoexiste'));
        $this->assertNull(MessagePath::get($body, 'excecao.profundo'));
    }
}
