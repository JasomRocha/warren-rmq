import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { estimateQueues } from '../lib/estimate';
import { buildLanes, laneShare } from '../lib/lanes';
import type { Queue, Worker } from '../types';
import { message, topology } from './fixtures';
import { installApi, openWorkspace, resetApp, settle } from './harness';
import { pageProps } from './inertia-mock';

vi.mock('@inertiajs/vue3', async () => (await import('./inertia-mock')).inertiaModule());

const queue = (over: Partial<Queue>): Queue => ({
    name: 'pdf', catalog: { name: null, description: null, category: null, role: 'principal', instance: null }, type: 'quorum', state: 'running',
    durable: true, arguments: {}, ready: 0, unacked: 0, consumers: 1, rateIn: 0, rateDeliver: 0, rateAck: 0, ...over,
});
const worker = { name: 'weasy@10.0.0.2', queues: [{ queue: 'pdf' }] } as unknown as Worker;

describe('filas desenhadas como pacotes', () => {
    const msgs = [
        message({ id: 1, instance: 'acme', queues: ['pdf'], priority: 4 }),
        message({ id: 2, instance: 'acme', queues: ['pdf'], priority: 4 }),
        message({ id: 3, instance: 'acme', queues: ['pdf'], priority: 1 }),
        message({ id: 4, instance: 'globex', queues: ['pdf'], priority: 4 }),
        message({ id: 5, instance: 'initech', queues: ['pdf'], priority: 31 }),
    ];

    it('os mais antigos estão com o worker; quem espera sai pela prioridade e, empatado, pela chegada (quorum 4.3+)', () => {
        const q = queue({ ready: 4, unacked: 1 });
        const [lane] = buildLanes([q], estimateQueues(msgs, [q]), [worker], { brokerVersion: '4.3.6' });

        expect(lane.processing.map((p) => p.message.id)).toEqual([1]);
        expect(lane.waiting.map((p) => p.message.id)).toEqual([5, 2, 4, 3]); // a devolutiva (31) fura a fila, a fatia em 1 vai para o fim
        expect(lane.workers).toEqual([worker]);
        expect(laneShare(lane)).toEqual([{ key: 'acme', count: 2 }, { key: 'globex', count: 1 }, { key: 'initech', count: 1 }]);
    });

    it('antes do RabbitMQ 4.3 a ordem é a de chegada', () => {
        const q = queue({ ready: 4, unacked: 1 });
        const [lane] = buildLanes([q], estimateQueues(msgs, [q]), [worker], { brokerVersion: '4.1.1' });

        expect(lane.waiting.map((p) => p.message.id)).toEqual([2, 3, 4, 5]);
    });

    it('mensagens anteriores à escuta ocupam a frente, sem detalhe', () => {
        const q = queue({ ready: 8, unacked: 2 });
        const [lane] = buildLanes([q], estimateQueues(msgs, [q]), [worker], { brokerVersion: '4.3.6' });

        expect(lane.unknownProcessing).toBe(2);
        expect(lane.unknownWaiting).toBe(3);
        expect(lane.processing).toHaveLength(0);
        expect(lane.waiting).toHaveLength(5);
    });

    it('filas sem consumidor (resultados) só aparecem quando pedidas', () => {
        const results = queue({ name: 'resultados', ready: 9, consumers: 0 });
        expect(buildLanes([results], new Map(), [])).toHaveLength(0);
        expect(buildLanes([results], new Map(), [], { includeIdle: true })).toHaveLength(1);
    });
});

describe('tela Filas', () => {
    beforeEach(() => {
        resetApp();
        pageProps.value = { locale: 'pt-BR', auth: { enabled: false, user: null } };
    });
    afterEach(() => vi.unstubAllGlobals());

    it('desenha uma faixa por fila com consumidor e abre a mensagem ao clicar no pacote', async () => {
        const now = Date.now();
        const messages = [
            message({ id: 11, instance: 'acme', queues: ['orders'], ts: now - 3000 }),
            message({ id: 12, instance: 'globex', queues: ['orders'], ts: now - 2000 }),
            message({ id: 13, instance: 'acme', queues: ['orders'], ts: now - 1000 }),
        ];
        installApi({ messages });
        const w = await openWorkspace('filas');

        expect(w.find('.topbar h1').text()).toBe('Filas');
        expect(w.findAll('.lane .lane-head b').map((b) => b.text())).toEqual(['orders', 'pdf']); // orders.created e audit não têm consumidor
        const orders = w.findAll('.lane')[0];
        // 5 na fila, 3 vistas pela escuta: as 2 mais antigas (em processamento) são anteriores à escuta
        expect(orders.find('.lane-worker .pkg-unknown').text()).toBe('2');
        expect(orders.findAll('.lane-track .pkg')).toHaveLength(3);
        expect(orders.find('.lane-track .pkg').attributes('title')).toContain('1º da fila');

        await orders.find('.lane-track .pkg').trigger('click');
        await settle();
        expect(w.find('.lanes-drawer pre').text()).toContain('"hello": "world"');
        w.unmount();
    });

    it('a legenda destaca uma instância em todas as faixas', async () => {
        const messages = [message({ id: 21, instance: 'acme', queues: ['orders'] }), message({ id: 22, instance: 'globex', queues: ['orders'] })];
        installApi({ messages, topology: { ...topology, queues: topology.queues.map((q) => (q.name === 'orders' ? { ...q, ready: 2, unacked: 0 } : q)) } });
        const w = await openWorkspace('filas');

        await w.findAll('.legend-chip').find((c) => c.text().includes('globex'))!.trigger('click');
        const dim = w.findAll('.lane-track .pkg').map((p) => p.classes().includes('dim'));
        expect(dim).toEqual([true, false]);
        w.unmount();
    });

    it('as letras dos pacotes são únicas na tela, mesmo com nomes parecidos', async () => {
        const messages = [message({ id: 31, instance: 'globex', queues: ['orders'] }), message({ id: 32, instance: 'globus', queues: ['orders'] })];
        installApi({ messages, topology: { ...topology, queues: topology.queues.map((q) => (q.name === 'orders' ? { ...q, ready: 2, unacked: 0 } : q)) } });
        const w = await openWorkspace('filas');

        expect(w.findAll('.lane-track .pkg').map((p) => p.text()).sort()).toEqual(['Gl', 'Go']); // por ordem de nome: Globex fica com Gl, Globus com Go
        w.unmount();
    });
});
