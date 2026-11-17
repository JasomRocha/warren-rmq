import type { MessageSummary, Queue, Topology } from '../types';

const queue = (name: string, ready: number, unacked: number, consumers = 0, catalog: Partial<Queue['catalog']> = {}): Queue => ({
    name, catalog: { name: null, description: null, category: null, role: 'principal', instance: null, ...catalog }, type: 'classic', state: 'running', durable: true, arguments: {}, ready, unacked, consumers, rateIn: 0, rateDeliver: 0, rateAck: 0,
});
const bind = (source: string, destination: string, routingKey: string, destinationType: 'queue' | 'exchange' = 'queue') => ({
    source, destination, destinationType, routingKey, arguments: {},
});
const exchange = (name: string, type: string) => ({ name, type, durable: true, internal: false, arguments: {}, rateIn: 0, rateOut: 0 });

export const topology: Topology = {
    broker: { version: '4.0', cluster: 'rabbit@test', vhost: '/' },
    exchanges: [exchange('', 'direct'), exchange('events', 'topic'), exchange('jobs', 'direct'), exchange('edge', 'topic'), exchange('unused', 'fanout')],
    queues: [queue('orders', 3, 2, 3), queue('orders.created', 1, 0), queue('pdf', 0, 1, 1), queue('audit', 0, 0)],
    bindings: [
        bind('events', 'orders', 'orders.#'),
        bind('events', 'orders.created', 'orders.*.created'),
        bind('events', 'orders.created', 'orders.created'),
        bind('jobs', 'pdf', 'pdf'),
        bind('edge', 'events', 'orders.#', 'exchange'),
        bind('edge', 'audit', '#'),
    ],
    categories: [],
    panels: [],
    workers: [{
        id: 'svc@10.0.0.1', name: 'svc@10.0.0.1', user: 'svc', host: '10.0.0.1', consumers: 4, activeConsumers: 4, prefetchMin: 1, prefetchMax: 1,
        manualAck: true, processingEstimate: 3,
        connections: [{ name: '10.0.0.1:5001 -> broker:5672', host: '10.0.0.1', port: 5001, node: 'rabbit@n1', consumers: 4 }],
        nodes: ['rabbit@n1'],
        catalog: { name: null, service: null, description: null },
        queues: [
            { queue: 'orders', consumers: 3, activeConsumers: 3, prefetchTotal: 3, manualAck: true, tags: ['a', 'b', 'c'] },
            { queue: 'pdf', consumers: 1, activeConsumers: 1, prefetchTotal: 1, manualAck: true, tags: ['d'] },
        ],
    }],
    fetchedAt: '2026-11-17T12:00:00Z',
};

let id = 0;
export const message = (over: Partial<MessageSummary> = {}): MessageSummary => ({
    id: ++id, rev: id, ts: Date.now(), origin: 'copy', exchange: 'events', routingKey: 'orders.eu.created', instance: 'acme', instanceName: null,
    priority: null, job: null, uuid: null, size: 10, queues: ['orders', 'orders.created'], deliveries: [], ...over,
});
