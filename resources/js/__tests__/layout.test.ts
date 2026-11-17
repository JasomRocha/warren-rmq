import { describe, expect, it } from 'vitest';
import { estimateQueues, instanceStats } from '../lib/estimate';
import { buildGraph } from '../lib/layout';
import { message, topology } from './fixtures';

function graph(messages = [message()], selected: string[] = [], query = '') {
    const estimates = estimateQueues(messages, topology.queues);
    const stats = instanceStats(messages, estimates, Date.now());
    const visible = selected.length ? messages.filter((m) => selected.includes(m.instance ?? '')) : messages;
    return buildGraph({ topology, query, stats, messages: visible, selected: new Set(selected) });
}
const ids = (g: ReturnType<typeof graph>, kind: string) => g.nodes.filter((n) => n.kind === kind).map((n) => n.id).sort();

describe('buildGraph', () => {
    it('has a producer per instance and only draws exchanges that are connected', () => {
        const g = graph([message({ instance: 'acme' }), message({ instance: 'globex' })]);

        expect(ids(g, 'producer')).toEqual(['acme', 'globex']);
        expect(ids(g, 'exchange')).toEqual(['edge', 'events', 'jobs']); // "unused" has no bindings
    });

    it('connects producers to the exchange they published to, with every routing key in full', () => {
        const g = graph([message({ routingKey: 'orders.eu.created' }), message({ routingKey: 'orders.us.created' })]);
        const edge = g.edges.find((e) => e.from === 'producer:acme' && e.to === 'exchange:events')!;

        expect(edge.labels).toEqual(['orders.eu.created', 'orders.us.created']);
    });

    it('groups several bindings of one exchange->queue pair into a single edge', () => {
        const edge = graph().edges.find((e) => e.from === 'exchange:events' && e.to === 'queue:orders.created')!;
        expect(edge.labels).toEqual(['orders.*.created', 'orders.created']);
    });

    it('with an instance ticked, keeps only what its messages touched', () => {
        const mine = message({ instance: 'acme', exchange: 'jobs', routingKey: 'pdf', queues: ['pdf'] });
        const theirs = message({ instance: 'globex', exchange: 'events', queues: ['orders', 'orders.created'] });
        const g = graph([mine, theirs], ['acme']);

        expect(ids(g, 'producer')).toEqual(['acme']);
        expect(ids(g, 'exchange')).toEqual(['jobs']);
        expect(ids(g, 'queue')).toEqual(['pdf']);
        expect(ids(g, 'worker')).toEqual(['svc@10.0.0.1']);
    });

    it('follows exchange-to-exchange bindings from where the instance published', () => {
        const viaEdge = message({ instance: 'acme', exchange: 'edge', routingKey: 'orders.us.created', queues: ['audit', 'orders', 'orders.created'] });
        const g = graph([viaEdge], ['acme']);

        expect(ids(g, 'exchange')).toEqual(['edge', 'events']);
        expect(g.edges.some((e) => e.from === 'exchange:edge' && e.to === 'exchange:events')).toBe(true);
    });

    it('draws the default exchange for direct publishes, linked to the queue named by the key', () => {
        const direct = message({ instance: 'hooli', exchange: '', routingKey: 'orders', queues: ['orders'] });
        const g = graph([direct]);

        expect(ids(g, 'exchange')).toContain('');
        expect(g.edges).toContainEqual({ from: 'exchange:', to: 'queue:orders', labels: ['orders'], flow: false });
    });

    it('shows each worker once, linked once per queue with the consumer count', () => {
        const g = graph();
        expect(ids(g, 'worker')).toEqual(['svc@10.0.0.1']);
        expect(g.edges.find((e) => e.to === 'worker:svc@10.0.0.1' && e.from === 'queue:orders')!.labels).toEqual(['×3']);
    });

    it('filters by queue name or routing key', () => {
        expect(ids(graph([message()], [], 'pdf'), 'queue')).toEqual(['pdf']);
        expect(ids(graph([message()], [], 'orders.*.created'), 'queue')).toEqual(['orders.created']);
    });
});
