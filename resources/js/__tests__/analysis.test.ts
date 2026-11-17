import { describe, expect, it } from 'vitest';
import { drainTime, integrate, isWorkQueue, littleWait, percentile, queueHealth, summarize } from '../lib/analysis';
import type { MetricPoint, Queue } from '../types';

const point = (t: number, over: Partial<MetricPoint> = {}): MetricPoint => ({
    t, ready: 0, unacked: 0, consumers: 1, rateIn: 0, rateDeliver: 0, rateAck: 0, areas: {}, ...over,
});
const queue = (over: Partial<Queue>): Queue => ({
    name: 'q', catalog: { name: null, description: null, category: null, role: 'principal', instance: null }, type: 'quorum', state: 'running',
    durable: true, arguments: {}, ready: 0, unacked: 0, consumers: 1, rateIn: 0, rateDeliver: 0, rateAck: 0, ...over,
});

describe('análise das filas de trabalho', () => {
    it('Lei de Little: 120 mensagens saindo a 4/s esperam 30 s; sem saída a espera é infinita', () => {
        expect(littleWait(120, 4)).toBe(30);
        expect(littleWait(0, 0)).toBe(0);
        expect(littleWait(5, 0)).toBe(Infinity);
    });

    it('tempo para esvaziar usa o saldo entre saída e entrada, e não promete prazo quando a fila cresce', () => {
        expect(drainTime(100, 3, 1)).toBe(50);
        expect(drainTime(100, 1, 3)).toBeNull();
        expect(drainTime(0, 0, 0)).toBe(0);
    });

    it('soma a taxa ao longo do tempo sem inventar dados nos buracos do coletor', () => {
        expect(integrate([0, 30, 60], [2, 2, 2])).toBe(120);
        expect(integrate([0, 30, 60, 90, 3600], [2, 2, 2, 2, 2]), 'o intervalo de quase uma hora é um buraco').toBe(180);
        expect(integrate([0, 1080, 2160], [1, 1, 1]), 'pontos agrupados de 18 em 18 min não são buracos').toBe(2160);
    });

    it('percentil por posição, ignorando o infinito', () => {
        expect(percentile([5, 1, 3, 2, 4], 50)).toBe(3);
        expect(percentile([1, 2, 3, 4, 100], 95)).toBe(100);
        expect(percentile([Infinity], 50)).toBeNull();
    });

    it('resume o período: processadas, picos, reentregas e quanto do tempo o acúmulo cresceu', () => {
        const s = summarize([
            point(0, { ready: 10, rateAck: 1, rateIn: 2, rateDeliver: 1, rateRedeliver: 0, capacity: 0.8 }),
            point(60, { ready: 20, rateAck: 3, rateIn: 2, rateDeliver: 3, rateRedeliver: 0.3, capacity: 0.6 }),
            point(120, { ready: 15, rateAck: 2, rateIn: 2, rateDeliver: 2, rateRedeliver: 0.1 }),
        ]);

        expect(s.processed).toBe(270); // (1+3)/2·60 + (3+2)/2·60
        expect(s.throughput).toBe(2.25);
        expect(s.peakThroughput).toEqual({ value: 3, t: 60 });
        expect(s.backlogPeak).toEqual({ value: 20, t: 60 });
        expect(s.backlogChange).toBe(5);
        expect(s.redeliveryRatio).toBeCloseTo(21 / 270);
        expect(s.capacity).toBeCloseTo(0.7);
        expect(s.growingShare).toBe(0.5);
        expect(s.waits).toEqual([10, 20 / 3, 7.5]);
    });

    it('só conta filas com consumidor e papel principal', () => {
        expect(isWorkQueue(queue({}))).toBe(true);
        expect(isWorkQueue(queue({ consumers: 0 }))).toBe(false);
        expect(isWorkQueue(queue({ catalog: { name: null, description: null, category: null, role: 'falhas', instance: null } }))).toBe(false);
    });

    it('classifica cada fila e põe as mais lentas primeiro', () => {
        const rows = queueHealth([
            queue({ name: 'ociosa' }),
            queue({ name: 'esvaziando', ready: 100, rateIn: 1, rateAck: 5, rateDeliver: 5 }),
            queue({ name: 'acumulando', ready: 50, rateIn: 5, rateAck: 1, rateDeliver: 1.25, rateRedeliver: 0.25 }),
            queue({ name: 'parada', ready: 3 }),
            queue({ name: 'resultados', ready: 1420, consumers: 0 }),
        ]);

        expect(rows.map((r) => [r.queue.name, r.trend])).toEqual([
            ['parada', 'stalled'],
            ['acumulando', 'growing'],
            ['esvaziando', 'draining'],
            ['ociosa', 'idle'],
        ]);
        expect(rows[1].redeliveryRatio).toBe(0.2);
        expect(rows[1].drain).toBeNull();
        expect(rows[2].drain).toBe(25);
    });
});
