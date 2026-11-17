import { beforeEach, describe, expect, it } from 'vitest';
import { estimateQueues, instanceStats } from '../lib/estimate';
import { buildGraph } from '../lib/layout';
import { instanceName, learnInstanceNames, resetInstanceNames, withKey, workerLabel, workerService } from '../lib/names';
import type { Topology as TopologyData, Worker } from '../types';
import { message, topology } from './fixtures';
import { goTo, openWorkspace, resetWorkspace, routeApi, settle } from './workspace';

const worker = (name: string, queues: string[], over: Partial<Worker> = {}): Worker => ({
    id: name, name, user: name.split('@')[0], host: name.split('@')[1], consumers: queues.length, activeConsumers: queues.length, prefetchMin: 1, prefetchMax: 1, manualAck: true, processingEstimate: 0,
    connections: [{ name: `${name.split('@')[1]}:5001 -> broker:5672`, host: name.split('@')[1], port: 5001, node: 'rabbit@n1', consumers: queues.length }], nodes: ['rabbit@n1'],
    catalog: { name: null, service: null, description: null },
    queues: queues.map((queue) => ({ queue, consumers: 1, activeConsumers: 1, prefetchTotal: 1, manualAck: true, tags: [] })), ...over,
});

describe('nomes que as pessoas reconhecem', () => {
    beforeEach(resetInstanceNames);

    it('a instância mostra o nome dado pelo servidor e, sem ele, a chave como veio', () => {
        learnInstanceNames([message({ instance: 'acme.empresa.com.br', instanceName: 'Acme' })]);

        expect(instanceName('acme.empresa.com.br', '(sem instância)')).toBe('Acme');
        expect(instanceName('desconhecida', '(sem instância)')).toBe('desconhecida');
        expect(instanceName(null, '(sem instância)')).toBe('(sem instância)');
        expect(instanceName('', '(sem instância)')).toBe('(sem instância)');
    });

    it('o worker mostra o nome do catálogo e, sem ele, o nome técnico; o serviço vem do catálogo ou do começo do nome', () => {
        const named = worker('app-pdf@10.0.0.2', [], { catalog: { name: 'Gerador de PDF · 10.0.0.2', service: 'Gerador de PDF', description: null } });
        const plain = worker('app-reports@10.0.0.9', []);

        expect(workerLabel(named)).toBe('Gerador de PDF · 10.0.0.2');
        expect(workerService(named)).toBe('Gerador de PDF');
        expect(workerLabel(plain)).toBe('app-reports@10.0.0.9');
        expect(workerService(plain)).toBe('app-reports');
    });

    it('a dica só repete a chave técnica quando ela é diferente do nome', () => {
        expect(withKey('Acme', 'acme.empresa.com.br')).toBe('Acme (acme.empresa.com.br)');
        expect(withKey('x', 'x')).toBe('x');
        expect(withKey('Trocas', '')).toBe('Trocas');
    });
});

describe('um nó por worker, não um por instância', () => {
    const queuesFor = (institutions: string[]) => institutions.flatMap((i) => ['exportacao', 'importacao', 'integracao'].map((kind) => `${kind}_${i}`));

    function graphWith(workers: Worker[], institutions: string[]) {
        const names = queuesFor(institutions);
        const t: TopologyData = {
            ...topology,
            exchanges: [{ name: 'x', type: 'topic', durable: true, internal: false, arguments: {}, rateIn: 0, rateOut: 0 }],
            queues: names.map((name) => ({ ...topology.queues[0], name, consumers: workers.length })),
            bindings: names.map((name) => ({ source: 'x', destination: name, destinationType: 'queue' as const, routingKey: name, arguments: {} })),
            workers,
        };
        return buildGraph({ topology: t, query: '', stats: instanceStats([], estimateQueues([], t.queues), 0), messages: [], selected: new Set() });
    }

    it('3 workers lendo as filas de 10 lojas continuam sendo 3 nós, ligados a cada fila', () => {
        const institutions = Array.from({ length: 10 }, (_, i) => `inst${i}`);
        const all = queuesFor(institutions);
        const g = graphWith(['app-pdf@10.0.0.1', 'app-pdf@10.0.0.2', 'app-pdf@10.0.0.3'].map((n) => worker(n, all)), institutions);

        expect(g.nodes.filter((n) => n.kind === 'worker')).toHaveLength(3);
        expect(g.nodes.filter((n) => n.kind === 'queue')).toHaveLength(30);
        expect(g.edges.filter((e) => e.to.startsWith('worker:'))).toHaveLength(90); // 30 filas x 3 workers, todas para os mesmos 3 nós
    });

    it('os workers ficam juntos por serviço e, dentro dele, em ordem numérica', () => {
        const all = queuesFor(['a']);
        const g = graphWith(
            [worker('app-pdf@10.0.0.10', all), worker('app-reports@10.0.0.1', all), worker('app-pdf@10.0.0.9', all), worker('app-billing@10.0.0.4', all)],
            ['a'],
        );

        expect(g.nodes.filter((n) => n.kind === 'worker').map((n) => n.id)).toEqual([
            'app-billing@10.0.0.4', 'app-pdf@10.0.0.9', 'app-pdf@10.0.0.10', 'app-reports@10.0.0.1',
        ]);
    });
});
