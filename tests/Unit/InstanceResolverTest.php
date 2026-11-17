<?php

namespace Tests\Unit;

use App\Rabbit\InstanceResolver;
use PHPUnit\Framework\TestCase;

class InstanceResolverTest extends TestCase
{
    private function resolver(): InstanceResolver
    {
        return new InstanceResolver(
            ['tenant', 'data.tenantId', 'data.files.0.tenant'],
            ['x-tenant'],
            ['/\|app\.events\.(?<instance>[^.]+)/', '/^jobs_[a-z]+\|[^.]+\.v1\.(?<instance>[^.]+)/'],
        );
    }

    public function test_reads_the_body_first_and_in_path_order(): void
    {
        $this->assertSame('acme', $this->resolver()->instance(['tenant' => ' acme '], 'x', 'k'));
        $this->assertSame('42', $this->resolver()->instance(['data' => ['tenantId' => 42]], 'x', 'k'));
        $this->assertSame('deep', $this->resolver()->instance(['data' => ['files' => [['tenant' => 'deep']]]], 'x', 'k'));
    }

    public function test_falls_back_to_headers_then_routing_key(): void
    {
        $this->assertSame('from-header', $this->resolver()->instance(null, 'x', 'k', ['x-tenant' => 'from-header']));
        $this->assertSame('globex', $this->resolver()->instance(null, 'events', 'app.events.globex.created'));
        $this->assertSame('initech', $this->resolver()->instance(null, 'jobs_pdf', 'render.v1.initech.x'));
    }

    public function test_no_match_means_no_instance_and_blank_values_are_ignored(): void
    {
        $this->assertNull($this->resolver()->instance(['tenant' => '  '], 'x', 'plain.key'));
        $this->assertNull($this->resolver()->instance(null, 'x', 'plain.key'));
    }

    public function test_an_invalid_regex_is_ignored_instead_of_crashing(): void
    {
        $r = new InstanceResolver([], [], ['/(unclosed/', '/\|k(?<instance>.)/']);
        $this->assertSame('1', $r->instance(null, 'x', 'k1'));
    }

    public function test_the_same_instance_written_two_ways_counts_once(): void
    {
        $r = new InstanceResolver(['tenant'], [], ['/^[^|]*\|[^.]+\.(?<instance>[^.]+)$/'], [['/_empresa_com_br$/', '.empresa.com.br']]);

        $fromKey = $r->instance(null, 'x', 'evento.acme_empresa_com_br');
        $fromBody = $r->instance(['tenant' => 'acme.empresa.com.br'], 'x', 'qualquer');

        $this->assertSame('acme.empresa.com.br', $fromKey);
        $this->assertSame($fromBody, $fromKey);
    }

    public function test_a_broken_normalization_rule_is_ignored(): void
    {
        $r = new InstanceResolver(['tenant'], [], [], [['/(unclosed/', 'x'], ['/_a$/', '-b'], ['so-um-item']]);

        $this->assertSame('acme-b', $r->instance(['tenant' => 'acme_a'], 'x', 'k'));
    }
}
