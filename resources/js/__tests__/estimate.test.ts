import { describe, expect, it } from 'vitest';
import { estimateQueues, instanceStats, isProcessing, situation } from '../lib/estimate';
import { message, topology } from './fixtures';

describe('estimateQueues', () => {
    it('holds the N most recent routed messages, N being the queue depth', () => {
        const msgs = Array.from({ length: 8 }, () => message({ queues: ['orders'] })); // depth of orders is 5
        const e = estimateQueues(msgs, topology.queues).get('orders')!;

        expect(e.messages.map((m) => m.id)).toEqual(msgs.slice(-5).map((m) => m.id));
        expect(e.unknown).toBe(0);
    });

    it('counts what was published before the tap as unknown', () => {
        const e = estimateQueues([message({ queues: ['orders'] })], topology.queues).get('orders')!;
        expect(e.messages).toHaveLength(1);
        expect(e.unknown).toBe(4);
    });

    it('leaves out messages the trace says were delivered', () => {
        const delivered = message({ queues: ['pdf'], deliveries: [{ queue: 'pdf', ts: 1, redelivered: false }] });
        expect(estimateQueues([delivered], topology.queues).get('pdf')!.messages).toHaveLength(0);
    });

    it('treats the oldest `unacked` slots as being processed, unknowns first', () => {
        // orders: depth 5, unacked 2. Two known messages -> 3 unknown fill the 2 processing slots and 1 waiting.
        const [a, b] = [message({ queues: ['orders'] }), message({ queues: ['orders'] })];
        const e = estimateQueues([a, b], topology.queues).get('orders')!;
        expect(isProcessing(e, a.id)).toBe(false);
        expect(isProcessing(e, b.id)).toBe(false);

        // with four known messages there is 1 unknown: it takes the first processing slot, the oldest known one the second
        const four = Array.from({ length: 4 }, () => message({ queues: ['orders'] }));
        const e2 = estimateQueues(four, topology.queues).get('orders')!;
        expect(four.map((m) => isProcessing(e2, m.id))).toEqual([true, false, false, false]);
    });
});

describe('instanceStats', () => {
    it('totals, recent rate and what each instance has in flight', () => {
        const now = Date.now();
        const old = message({ instance: 'acme', ts: now - 120_000, queues: [] });
        const fresh = message({ instance: 'acme', ts: now - 1_000, queues: ['pdf'] }); // pdf: depth 1, unacked 1 -> processing
        const other = message({ instance: null, ts: now, queues: ['orders'] });
        const stats = instanceStats([old, fresh, other], estimateQueues([old, fresh, other], topology.queues), now);

        const acme = stats.find((s) => s.key === 'acme')!;
        expect(acme.total).toBe(2);
        expect(acme.recent).toBe(1);
        expect(acme.processing).toBe(1);
        expect(stats.find((s) => s.key === '')).toBeDefined();
    });

    it('remembers every routing key used per exchange', () => {
        const stats = instanceStats([message({ routingKey: 'a' }), message({ routingKey: 'a' }), message({ routingKey: 'b' })], new Map(), Date.now());
        expect([...stats[0].exchanges.get('events')!]).toEqual([['a', 2], ['b', 1]]);
    });
});

describe('situation', () => {
    it('reports no route, waiting, delivered and left', () => {
        const none = message({ queues: [] });
        const delivered = message({ queues: ['audit'], deliveries: [{ queue: 'audit', ts: 5, redelivered: false }] });
        const gone = message({ queues: ['audit'] });
        const est = estimateQueues([none, delivered, gone], topology.queues);

        expect(situation(none, est).kind).toBe('noroute');
        expect(situation(delivered, est)).toEqual({ kind: 'delivered', at: 5 });
        expect(situation(gone, est).kind).toBe('left'); // audit is empty, so it has left
    });
});
