<?php

namespace Tests\Feature;

use App\Models\Broker;
use App\Models\CapturedMessage;
use App\Models\MetricSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\HasBroker;
use Tests\TestCase;

class BrokerTest extends TestCase
{
    use HasBroker;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** Sets an environment variable in every place Laravel's env() looks, and puts it back after the test. */
    private function setEnv(string $name, string $value): void
    {
        $before = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
        $this->beforeApplicationDestroyed(function () use ($name, $before) {
            $before[0] === false ? putenv($name) : putenv("{$name}={$before[0]}");
            $_ENV[$name] = $before[1];
            $_SERVER[$name] = $before[2];
        });
    }

    private function form(array $over = []): array
    {
        return $over + ['name' => 'Produção', 'url' => 'https://rabbit.example.com/', 'username' => 'ana', 'password' => 'segredo-longo', 'vhost' => '/prod', 'amqp_port' => 5672];
    }

    public function test_a_connection_is_created_from_the_screen_and_its_password_is_never_sent_back(): void
    {
        $json = $this->postJson('/api/brokers', $this->form())->assertCreated()->json();

        $this->assertSame('Produção', $json['name']);
        $this->assertSame('https://rabbit.example.com', $json['url'], 'the trailing slash is dropped');
        $this->assertTrue($json['hasPassword']);
        $this->assertStringNotContainsString('segredo-longo', json_encode($json));

        $list = $this->getJson('/api/brokers')->assertOk();
        $this->assertStringNotContainsString('segredo-longo', $list->getContent());
        $this->assertStringNotContainsString('"password"', $list->getContent());
    }

    public function test_the_password_is_stored_encrypted_but_used_in_the_clear(): void
    {
        $this->postJson('/api/brokers', $this->form());

        $stored = DB::table('brokers')->value('password');
        $this->assertStringNotContainsString('segredo-longo', $stored);
        $this->assertSame('segredo-longo', Broker::first()->password);
    }

    public function test_editing_without_a_password_keeps_the_saved_one(): void
    {
        $broker = $this->makeBroker(['url' => 'https://rabbit.example.com', 'username' => 'ana', 'vhost' => '/prod', 'password' => 'antiga']);

        $this->putJson("/api/brokers/{$broker->id}", $this->form(['name' => 'Novo nome', 'password' => '']))->assertOk();
        $this->assertSame('antiga', $broker->fresh()->password);
        $this->assertSame('Novo nome', $broker->fresh()->name);

        $this->putJson("/api/brokers/{$broker->id}", $this->form(['password' => 'trocada']))->assertOk();
        $this->assertSame('trocada', $broker->fresh()->password);
    }

    public function test_a_password_is_required_to_create_but_not_to_edit(): void
    {
        $this->postJson('/api/brokers', $this->form(['password' => '']))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_the_address_must_be_http_or_https(): void
    {
        $this->postJson('/api/brokers', $this->form(['url' => 'ftp://rabbit.example.com']))->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->postJson('/api/brokers', $this->form(['url' => 'não é endereço']))->assertUnprocessable();
        $this->postJson('/api/brokers', $this->form(['amqp_port' => 99999]))->assertUnprocessable()->assertJsonValidationErrors('amqp_port');
    }

    public function test_validation_errors_come_in_portuguese(): void
    {
        $errors = $this->postJson('/api/brokers', $this->form(['name' => '', 'url' => 'nada']))->assertUnprocessable()->json('errors');

        $this->assertSame('O campo nome é obrigatório.', $errors['name'][0]);
        $this->assertStringContainsString('endereço da API', $errors['url'][0]);
    }

    public function test_deleting_a_connection_also_deletes_what_was_collected_for_it(): void
    {
        $mine = $this->makeBroker();
        $other = $this->makeBroker(['name' => 'Outra']);
        foreach ([$mine, $other] as $b) {
            CapturedMessage::create(['broker_id' => $b->id, 'rev' => 1, 'ts' => 1, 'origin' => 'copy', 'exchange' => 'x', 'routing_key' => 'k', 'size' => 1, 'queues' => [], 'deliveries' => [], 'properties' => [], 'headers' => [], 'body' => '', 'body_truncated' => false, 'hash' => 'h']);
            MetricSample::create(['queues' => 1, 'broker_id' => $b->id, 'ts' => time(), 'ready' => 1, 'unacked' => 0, 'consumers' => 0, 'rate_in' => 0, 'rate_deliver' => 0, 'rate_ack' => 0]);
        }

        $this->deleteJson("/api/brokers/{$mine->id}")->assertNoContent();

        $this->assertNull(Broker::find($mine->id));
        $this->assertSame([$other->id], CapturedMessage::pluck('broker_id')->all());
        $this->assertSame([$other->id], MetricSample::pluck('broker_id')->all());
    }

    public function test_each_connection_only_sees_its_own_messages(): void
    {
        $a = $this->makeBroker(['name' => 'A']);
        $b = $this->makeBroker(['name' => 'B']);
        $row = fn (Broker $x, string $key) => CapturedMessage::create(['broker_id' => $x->id, 'rev' => 1, 'ts' => 1, 'origin' => 'copy', 'exchange' => 'x', 'routing_key' => $key, 'size' => 1, 'queues' => [], 'deliveries' => [], 'properties' => [], 'headers' => [], 'body' => '', 'body_truncated' => false, 'hash' => $key]);
        $mine = $row($a, 'da-a');
        $row($b, 'da-b');

        $this->assertSame(['da-a'], array_column($this->getJson($this->api('/events', $a))->json('messages'), 'routingKey'));
        $this->assertSame(['da-b'], array_column($this->getJson($this->api('/events', $b))->json('messages'), 'routingKey'));
        $this->getJson($this->api("/messages/{$mine->id}", $b))->assertNotFound();
    }

    public function test_the_list_shows_the_health_each_connection_last_had_without_calling_the_brokers(): void
    {
        $broker = $this->makeBroker();
        Cache::put("warren.health.{$broker->id}", ['ok' => true, 'message' => null, 'at' => 123, 'version' => '4.1', 'queues' => 7, 'ready' => 9, 'workers' => 2]);
        Http::fake(); // would fail the test if anything tried to reach a broker

        $row = $this->getJson('/api/brokers')->assertOk()->json('0');

        Http::assertNothingSent();
        $this->assertTrue($row['health']['ok']);
        $this->assertSame(7, $row['health']['queues']);
        $this->assertArrayHasKey('active', $row['tap']);
    }

    public function test_a_connection_is_created_from_the_environment_once_and_only_if_it_is_configured(): void
    {
        $this->setEnv('RABBITMQ_URL', 'http://env-broker.test:15672');
        config(['warren.broker.url' => 'http://env-broker.test:15672', 'warren.broker.username' => 'u', 'warren.broker.password' => 'p', 'warren.broker.vhost' => '/v']);

        $this->assertSame('env-broker.test', Broker::seedFromEnvironment()->name);
        $this->assertNull(Broker::seedFromEnvironment(), 'a second call creates nothing');
        $this->assertSame(1, Broker::count());
        $this->assertSame('p', Broker::first()->password);

        Broker::query()->delete();
        $this->setEnv('RABBITMQ_URL', '');
        $this->assertNull(Broker::seedFromEnvironment(), 'no RABBITMQ_URL, no automatic connection');
    }

    public function test_the_connection_test_reports_the_version_the_vhost_and_what_the_user_may_do(): void
    {
        Http::fake([
            '*/api/overview' => Http::response(['rabbitmq_version' => '4.1.1', 'cluster_name' => 'rabbit@x']),
            '*/api/whoami' => Http::response(['name' => 'ana', 'tags' => ['management']]),
            '*/api/vhosts/%2Fprod' => Http::response(['name' => '/prod']),
        ]);

        $json = $this->postJson('/api/brokers/test', $this->form(['amqp_host' => '127.0.0.1', 'amqp_port' => 1]))->assertOk()->json();

        $this->assertTrue($json['ok']);
        $this->assertSame('4.1.1', $json['version']);
        $this->assertFalse($json['canMonitor'], 'only "management": Warren will read each queue separately');
        $this->assertFalse($json['amqp']['ok'], 'nothing listens on that port');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('ana:segredo-longo')));
    }

    public function test_the_connection_test_explains_wrong_credentials(): void
    {
        Http::fake(['*/api/overview' => Http::response('no', 401)]);

        $bad = $this->postJson('/api/brokers/test', $this->form())->json();

        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('Usuário ou senha', $bad['message']);
    }

    public function test_the_connection_test_explains_an_unknown_vhost(): void
    {
        Http::fake([
            '*/api/overview' => Http::response(['rabbitmq_version' => '4']),
            '*/api/whoami' => Http::response(['tags' => ['monitoring']]),
            '*/api/vhosts/*' => Http::response('', 404),
        ]);
        $noVhost = $this->postJson('/api/brokers/test', $this->form(['vhost' => '/nada']))->json();
        $this->assertFalse($noVhost['ok']);
        $this->assertStringContainsString('/nada', $noVhost['message']);
    }

    public function test_testing_an_existing_connection_without_retyping_the_password_uses_the_saved_one(): void
    {
        $broker = $this->makeBroker(['url' => 'https://rabbit.example.com', 'username' => 'ana', 'vhost' => '/prod', 'password' => 'a-guardada']);
        Http::fake(['*' => Http::response(['rabbitmq_version' => '4', 'tags' => []])]);

        $this->postJson('/api/brokers/test', $this->form(['id' => $broker->id, 'password' => '']))->assertOk();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('ana:a-guardada')));
    }

    public function test_the_saved_password_never_goes_to_another_address(): void
    {
        $broker = $this->makeBroker(['url' => 'https://rabbit.example.com', 'username' => 'ana', 'vhost' => '/prod', 'password' => 'a-guardada']);
        Http::fake(['*' => Http::response(['rabbitmq_version' => '4', 'tags' => []])]);

        $this->postJson('/api/brokers/test', $this->form(['id' => $broker->id, 'password' => '', 'url' => 'https://atacante.example.net']))->assertOk();
        Http::assertNotSent(fn ($r) => str_contains((string) $r->header('Authorization')[0] ?? '', base64_encode('ana:a-guardada')));

        $this->putJson("/api/brokers/{$broker->id}", $this->form(['password' => '', 'url' => 'https://atacante.example.net']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson("/api/brokers/{$broker->id}", $this->form(['password' => '', 'amqp_host' => 'atacante.example.net']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame('https://rabbit.example.com', $broker->fresh()->url, 'nothing changed');
    }

    public function test_link_local_addresses_and_smuggled_paths_are_refused(): void
    {
        foreach (['http://169.254.169.254', 'http://[fe80::1]:15672', 'http://metadata.google.internal', 'https://rabbit.example.com/?x=', 'https://u:p@rabbit.example.com'] as $url) {
            $this->postJson('/api/brokers/test', $this->form(['url' => $url]))->assertUnprocessable()->assertJsonValidationErrors('url');
        }
        $this->postJson('/api/brokers', $this->form(['amqp_host' => '169.254.169.254']))->assertUnprocessable()->assertJsonValidationErrors('amqp_host');
        $this->postJson('/api/brokers', $this->form(['url' => 'http://10.0.0.5:15672']))->assertCreated(); // private networks are the normal case
    }

    public function test_the_connection_test_cannot_be_used_to_reach_other_protocols(): void
    {
        $this->postJson('/api/brokers/test', $this->form(['url' => 'file:///etc/passwd']))->assertUnprocessable();
        $this->postJson('/api/brokers/test', $this->form(['url' => 'gopher://x']))->assertUnprocessable();
    }

    public function test_everything_here_needs_a_login(): void
    {
        config(['warren.auth.enabled' => true]);
        $broker = $this->makeBroker();

        $this->getJson('/api/brokers')->assertUnauthorized();
        $this->postJson('/api/brokers', $this->form())->assertUnauthorized();
        $this->postJson('/api/brokers/test', $this->form())->assertUnauthorized();
        $this->putJson("/api/brokers/{$broker->id}", $this->form())->assertUnauthorized();
        $this->deleteJson("/api/brokers/{$broker->id}")->assertUnauthorized();
        $this->getJson($this->api('/metrics'))->assertUnauthorized();

        $this->actingAs(User::factory()->create())->getJson('/api/brokers')->assertOk();
    }
}
