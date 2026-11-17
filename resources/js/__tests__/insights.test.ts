import { describe, expect, it } from 'vitest';
import { instanceStats, estimateQueues } from '../lib/estimate';
import { duration, queueLabel } from '../lib/format';
import {
    alerts, areaInsights, drainSeconds, instanceRanking, levelOf, processingSeconds, queueInsights, summary, waitByQueue, workerInsights,
} from '../lib/insights';
import type { Queue, Topology } from '../types';
import { message, topology } from './fixtures';

const q = (over: Partial<Queue> & { role?: Queue['catalog']['role']; category?: string | null }): Queue => {
    const { role, category, ...rest } = over;
    return {
        name: 'fila', catalog: { name: null, description: null, category: category ?? null, role: role ?? 'principal', instance: null },
        type: 'classic', state: 'running', durable: true, arguments: {}, ready: 0, unacked: 0, consumers: 1, rateIn: 0, rateDeliver: 0, rateAck: 0, ...rest,
    };
};
const withQueues = (queues: Queue[]): Topology => ({ ...topology, queues });

describe('tempo de processamento (Lei de Little)', () => {
    it('é o que está sem ack dividido pela taxa de ack', () => {
        expect(processingSeconds(30, 10)).toBe(3); // 30 em andamento, 10 concluídas por segundo → 3 s cada
        expect(processingSeconds(2, 0.5)).toBe(4);
    });

    it('não inventa um número quando não há dados suficientes', () => {
        expect(processingSeconds(0, 10)).toBeNull(); // nada em andamento
        expect(processingSeconds(5, 0)).toBeNull(); // ninguém concluindo
        expect(processingSeconds(5, 0.01)).toBeNull(); // quase parado: o número seria ruído
    });
});

describe('tempo para esvaziar', () => {
    it('é o que espera dividido pela taxa de entrega', () => {
        expect(drainSeconds(120, 2)).toBe(60);
    });

    it('é infinito quando há mensagens e nada sai, e nulo quando não há o que esvaziar', () => {
        expect(drainSeconds(10, 0)).toBe(Infinity);
        expect(drainSeconds(0, 5)).toBeNull();
    });
});

describe('duration', () => {
    it.each([
        [null, '—'], [Infinity, '∞'], [0.25, '250 ms'], [3, '3 s'], [42, '42 s'], [60, '1 min'], [200, '3 min 20 s'], [3600, '1 h'], [7500, '2 h 5 min'],
    ])('%s → %s', (input, expected) => {
        expect(duration(input as number | null).replace(/\s/g, ' ')).toBe(expected);
    });
});

describe('nível de uma fila', () => {
    const base = { ready: 0, consumers: 1, role: 'principal' as const, drain: null };

    it('é crítico quando há mensagens e ninguém consome', () => {
        expect(levelOf({ ...base, ready: 5, consumers: 0 })).toBe('critical');
    });

    it('falhas com mensagens são críticas; espera com mensagens pede atenção', () => {
        expect(levelOf({ ...base, role: 'falhas', ready: 1 })).toBe('critical');
        expect(levelOf({ ...base, role: 'falhas', ready: 0 })).toBe('ok');
        expect(levelOf({ ...base, role: 'espera', ready: 3 })).toBe('warning');
    });

    it('fila de espera e de falhas sem consumidor é normal, não é alerta de "sem consumidor"', () => {
        expect(levelOf({ ...base, role: 'espera', ready: 0, consumers: 0 })).toBe('ok');
    });

    it('demora longa para esvaziar é atenção; parada total é crítico', () => {
        expect(levelOf({ ...base, ready: 100, drain: 301 })).toBe('warning');
        expect(levelOf({ ...base, ready: 100, drain: 299 })).toBe('ok');
        expect(levelOf({ ...base, ready: 100, drain: Infinity })).toBe('critical');
    });
});

describe('queueInsights', () => {
    it('usa o nome do catálogo quando existe e o técnico quando não', () => {
        expect(queueLabel(q({ name: 'x_1', catalog: { name: 'Geração de pedidos', description: null, category: null, role: 'principal', instance: null } }))).toBe('Geração de pedidos');
        expect(queueLabel(q({ name: 'x_1' }))).toBe('x_1');
    });

    it('calcula o tempo de processamento e de esvaziamento de cada fila', () => {
        const t = withQueues([q({ name: 'a', ready: 60, unacked: 6, consumers: 2, rateDeliver: 2, rateAck: 2 })]);
        const [a] = queueInsights(t, [], Date.now());

        expect(a.processingSeconds).toBe(3);
        expect(a.drainSeconds).toBe(30);
        expect(a.level).toBe('ok');
    });

    it('mede o tempo de espera na fila a partir da publicação até a entrega (trace)', () => {
        const now = Date.now();
        const fast = message({ ts: now - 10_000, queues: ['a'], deliveries: [{ queue: 'a', ts: now - 8_000, redelivered: false }] }); // 2 s
        const slow = message({ ts: now - 20_000, queues: ['a'], deliveries: [{ queue: 'a', ts: now - 14_000, redelivered: false }] }); // 6 s
        const old = message({ ts: now - 3_600_000, queues: ['a'], deliveries: [{ queue: 'a', ts: now - 3_599_000, redelivered: false }] }); // fora da janela

        expect(waitByQueue([fast, slow, old], now).get('a')).toBe(4);
        expect(waitByQueue([], now).size).toBe(0);
    });
});

describe('fila sem consumidor por desenho (papel passiva)', () => {
    it('não gera alerta nem nível crítico, mas continua contando no volume', () => {
        const now = Date.now();
        const t = withQueues([q({ name: 'consulta', role: 'passiva', category: 'geral', ready: 40, consumers: 0 })]);
        const insights = queueInsights(t, [], now);

        expect(insights[0].level).toBe('ok');
        expect(alerts(insights, [], now)).toEqual([]);
        expect(summary(insights, []).waiting).toBe(40);
    });
});

describe('areaInsights', () => {
    const categories = [{ key: 'geracao', name: 'Geração', description: null, color: '#fff', generic: false }, { key: 'marketplace', name: 'Marketplace', description: null, color: null, generic: false }];

    it('soma as filas da área e calcula o tempo médio ponderado', () => {
        const t = withQueues([
            q({ name: 'a', category: 'geracao', ready: 10, unacked: 4, rateAck: 1, rateDeliver: 1, rateIn: 2 }),
            q({ name: 'b', category: 'geracao', ready: 20, unacked: 2, rateAck: 1, rateDeliver: 1, rateIn: 3 }),
            q({ name: 'c', category: 'marketplace', ready: 1 }),
        ]);
        const areas = areaInsights(queueInsights(t, [], Date.now()), categories, 'Sem área');
        const geracao = areas.find((a) => a.key === 'geracao')!;

        expect(geracao.queues).toBe(2);
        expect(geracao.ready).toBe(30);
        expect(geracao.unacked).toBe(6);
        expect(geracao.rateIn).toBe(5);
        expect(geracao.processingSeconds).toBe(3); // 6 em andamento ÷ 2 acks/s
        expect(geracao.drainSeconds).toBe(15); // 30 esperando ÷ 2 entregas/s
        expect(areas.map((a) => a.key)).toEqual(['geracao', 'marketplace']);
    });

    it('conta retentativas e falhas à parte, sem misturar na vazão', () => {
        const t = withQueues([
            q({ name: 'a', category: 'geracao', ready: 5, rateDeliver: 5, rateAck: 5, unacked: 5 }),
            q({ name: 'a.aguardando', category: 'geracao', role: 'espera', ready: 7, consumers: 0 }),
            q({ name: 'a.falhas', category: 'geracao', role: 'falhas', ready: 2, consumers: 0 }),
        ]);
        const geracao = areaInsights(queueInsights(t, [], Date.now()), categories, 'x')[0];

        expect(geracao.ready).toBe(5);
        expect(geracao.retry).toBe(7);
        expect(geracao.failures).toBe(2);
        expect(geracao.level).toBe('critical');
    });

    it('filas sem área vão para "Sem área", no fim', () => {
        const t = withQueues([q({ name: 'solta' }), q({ name: 'c', category: 'marketplace' })]);
        const areas = areaInsights(queueInsights(t, [], Date.now()), categories, 'Sem área');

        expect(areas.map((a) => a.name)).toEqual(['Marketplace', 'Sem área']);
    });
});

describe('ranking de instâncias', () => {
    it('ordena por quem tem mais mensagens pendentes e mostra a parcela da carga', () => {
        const t = withQueues([q({ name: 'a', ready: 100, unacked: 0, consumers: 1 })]);
        const msgs = [
            ...Array.from({ length: 8 }, () => message({ instance: 'grande', queues: ['a'] })),
            ...Array.from({ length: 2 }, () => message({ instance: 'pequena', queues: ['a'] })),
        ];
        const stats = instanceStats(msgs, estimateQueues(msgs, t.queues), Date.now());
        const ranking = instanceRanking(stats, msgs, new Map());

        expect(ranking.map((r) => r.key)).toEqual(['grande', 'pequena']);
        expect(ranking[0].pending).toBe(8);
        expect(ranking[0].shareOfPending).toBeCloseTo(0.8);
        expect(ranking[1].shareOfPending).toBeCloseTo(0.2);
    });

    it('marca como dominante quem concentra a maior parte do acúmulo, só quando é relevante', () => {
        const stat = (key: string, waiting: number) => ({ key, total: waiting, last: 1, recent: waiting, processing: 0, waiting, queues: new Set<string>(), exchanges: new Map() });

        expect(instanceRanking([stat('a', 30), stat('b', 5)], [], new Map())[0].dominates).toBe(true);
        expect(instanceRanking([stat('a', 6), stat('b', 1)], [], new Map())[0].dominates).toBe(false); // pouco acúmulo
        expect(instanceRanking([stat('a', 20), stat('b', 20)], [], new Map()).some((r) => r.dominates)).toBe(false); // dividido
        expect(instanceRanking([stat('a', 30), stat('b', 20)], [], new Map()).some((r) => r.dominates)).toBe(false); // 60% mas não o dobro
        expect(instanceRanking([stat('a', 50)], [], new Map())[0].dominates).toBe(false); // única instância
    });

    it('indica a área de negócio para onde a instância mais mandou', () => {
        const msgs = [message({ instance: 'x', queues: ['a'] }), message({ instance: 'x', queues: ['a'] }), message({ instance: 'x', queues: ['b'] })];
        const stats = instanceStats(msgs, new Map(), Date.now());

        expect(instanceRanking(stats, msgs, new Map([['a', 'geracao'], ['b', 'marketplace']]))[0].topArea).toBe('geracao');
    });
});

describe('área principal da instância', () => {
    it('ignora áreas genéricas (catch-all) para não descrever todo mundo como "geral"', () => {
        const msgs = [message({ instance: 'x', queues: ['catchall', 'geracao'] }), message({ instance: 'x', queues: ['catchall'] }), message({ instance: 'x', queues: ['catchall'] })];
        const stats = instanceStats(msgs, new Map(), Date.now());
        const area = new Map([['catchall', 'geral'], ['geracao', 'geracao-pedidos']]);

        expect(instanceRanking(stats, msgs, area)[0].topArea).toBe('geral');
        expect(instanceRanking(stats, msgs, area, new Set(['geral']))[0].topArea).toBe('geracao-pedidos');
    });
});

describe('workers', () => {
    it('divide o que está em andamento entre os workers pela fatia de consumidores e calcula a saturação', () => {
        const t = withQueues([q({ name: 'a', unacked: 8, consumers: 4, rateAck: 4 })]);
        const worker = (name: string, consumers: number) => ({
            id: name, name, user: null, host: null, consumers, activeConsumers: consumers, prefetchMin: 1, prefetchMax: 1, manualAck: true, processingEstimate: 0, connections: [], nodes: [], catalog: { name: null, service: null, description: null },
            queues: [{ queue: 'a', consumers, activeConsumers: consumers, prefetchTotal: consumers * 2, manualAck: true, tags: [] }],
        });
        const [big, small] = workerInsights([worker('pequeno', 1), worker('grande', 3)], t.queues);

        expect(big.name).toBe('grande');
        expect(big.processing).toBe(6); // 3 de 4 consumidores → 6 de 8
        expect(big.capacity).toBe(6);
        expect(big.saturation).toBeCloseTo(1);
        expect(big.processingSeconds).toBe(2); // 6 ÷ (4 acks/s × 3/4)
        expect(small.processing).toBe(2);
        expect(small.saturation).toBeCloseTo(1);
    });

    it('não calcula capacidade quando algum consumidor não tem limite de prefetch', () => {
        const t = withQueues([q({ name: 'a', unacked: 1, consumers: 1 })]);
        const w = { id: 'w', name: 'w', user: null, host: null, consumers: 1, activeConsumers: 1, prefetchMin: 0, prefetchMax: 0, manualAck: true, processingEstimate: 0, connections: [], nodes: [], catalog: { name: null, service: null, description: null }, queues: [{ queue: 'a', consumers: 1, activeConsumers: 1, prefetchTotal: 0, manualAck: true, tags: [] }] };

        expect(workerInsights([w], t.queues)[0].capacity).toBeNull();
        expect(workerInsights([w], t.queues)[0].saturation).toBeNull();
    });
});

describe('alertas e resumo', () => {
    const now = Date.now();

    it('gera um alerta para cada problema, dos críticos para os de atenção', () => {
        const t = withQueues([
            q({ name: 'sem-consumidor', ready: 4, consumers: 0 }),
            q({ name: 'travada', ready: 9, consumers: 2, rateDeliver: 0 }),
            q({ name: 'lenta', ready: 1000, consumers: 2, rateDeliver: 1 }),
            q({ name: 'x.aguardando', role: 'espera', ready: 3, consumers: 0 }),
            q({ name: 'x.falhas', role: 'falhas', ready: 1, consumers: 0 }),
            q({ name: 'saudavel', ready: 2, consumers: 2, rateDeliver: 5 }),
            q({ name: 'vazia-sem-consumidor', ready: 0, consumers: 0 }),
        ]);
        const list = alerts(queueInsights(t, [], now), [], now);

        expect(list.map((a) => [a.code, a.queue])).toEqual(expect.arrayContaining([
            ['no-consumer', 'sem-consumidor'], ['stalled', 'travada'], ['slow-drain', 'lenta'], ['retry-backlog', 'x.aguardando'], ['failures', 'x.falhas'],
        ]));
        expect(list).toHaveLength(5);
        expect(list.slice(0, 3).every((a) => a.level === 'critical')).toBe(true);
        expect(list.at(-1)?.level).toBe('warning');
    });

    it('avisa de mensagens sem rota publicadas nos últimos minutos, ignorando as antigas', () => {
        const recent = message({ queues: [], ts: now - 60_000 });
        const old = message({ queues: [], ts: now - 3_600_000 });
        const list = alerts([], [recent, old], now);

        expect(list).toEqual([{ level: 'warning', code: 'no-route', queue: null, label: '', value: 1 }]);
    });

    it('o resumo considera só as filas principais', () => {
        const t = withQueues([
            q({ name: 'a', ready: 10, unacked: 4, rateIn: 1, rateAck: 2, rateDeliver: 2 }),
            q({ name: 'a.aguardando', role: 'espera', ready: 99, unacked: 50, rateIn: 5, rateAck: 5, consumers: 0 }),
        ]);
        const insights = queueInsights(t, [], now);
        const s = summary(insights, alerts(insights, [], now));

        expect(s).toMatchObject({ waiting: 10, processing: 4, ratePerMinute: 60, processingSeconds: 2, warnings: 1, critical: 0 });
    });
});
