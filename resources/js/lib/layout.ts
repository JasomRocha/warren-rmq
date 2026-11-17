import type { Exchange, InstanceStat, MessageSummary, NodeKind, Queue, Topology, Worker } from '../types';
import { jsonArgs } from './format';
import { workerLabel, workerService } from './names';

export const COLUMNS: Record<NodeKind, { x: number; w: number; h: number }> = {
    producer: { x: 0, w: 220, h: 100 },
    exchange: { x: 440, w: 220, h: 66 },
    queue: { x: 940, w: 270, h: 140 },
    worker: { x: 1400, w: 250, h: 104 },
};
const TOP = 64;
const GAP = 16;
const MAX_PRODUCERS = 14;
const MAX_PRODUCERS_FOCUSED = 24;

export interface GraphNode {
    key: string;
    kind: NodeKind;
    id: string;
    x: number;
    y: number;
    w: number;
    h: number;
    producer?: InstanceStat;
    exchange?: Exchange;
    queue?: Queue;
    worker?: Worker;
}

export interface GraphEdge {
    from: string;
    to: string;
    /** Full routing keys (or "×n" consumer counts). Never truncated here. */
    labels: string[];
    flow: boolean;
}

export interface Graph {
    nodes: GraphNode[];
    edges: GraphEdge[];
    width: number;
    height: number;
}

export interface GraphInput {
    topology: Topology;
    query: string;
    /** Every instance seen, whatever the selection. */
    stats: InstanceStat[];
    /** Messages of the visible instances only. */
    messages: MessageSummary[];
    /** Ticked instances; empty means "show everything". */
    selected: ReadonlySet<string>;
}

export const nodeKey = (kind: NodeKind, id: string): string => `${kind}:${id}`;

/**
 * Lays the system out in four columns: producers, exchanges, queues, workers.
 *
 * Only what is connected to something visible is drawn. With instances ticked, only what their
 * messages touched is drawn: the exchanges they published to (and what those forward to) and the
 * queues that received copies.
 */
export function buildGraph({ topology: t, query, stats, messages, selected }: GraphInput): Graph {
    const q = query.trim().toLowerCase();
    const matches = (s: string): boolean => s.toLowerCase().includes(q);
    const focused = selected.size > 0;

    const toQueue = t.bindings.filter((b) => b.destinationType === 'queue');
    const toExchange = t.bindings.filter((b) => b.destinationType === 'exchange');

    // --- what the visible messages touched (only matters when focused)
    const touchedQueues = new Set(messages.flatMap((m) => m.queues));
    let reachable: Set<string> | null = null;
    if (focused) {
        reachable = new Set(messages.map((m) => m.exchange));
        for (let changed = true; changed; ) {
            changed = false;
            for (const b of toExchange) {
                if (reachable.has(b.source) && !reachable.has(b.destination)) {
                    reachable.add(b.destination);
                    changed = true;
                }
            }
        }
    }

    // --- producers
    let producers = stats.filter((s) => !focused || selected.has(s.key)).sort((a, b) => b.last - a.last);
    producers = producers.slice(0, focused ? MAX_PRODUCERS_FOCUSED : MAX_PRODUCERS);

    // --- queues
    const queues = t.queues.filter((queue) => {
        if (focused && !touchedQueues.has(queue.name)) return false;
        return !q || matches(queue.name) || toQueue.some((b) => b.destination === queue.name && (matches(b.source) || matches(b.routingKey)));
    });
    const queueNames = new Set(queues.map((x) => x.name));

    // --- exchanges
    const used = new Set(toQueue.filter((b) => queueNames.has(b.destination) && (!reachable || reachable.has(b.source))).map((b) => b.source));
    for (let changed = true; changed; ) {
        changed = false;
        for (const b of toExchange) {
            if (used.has(b.destination) && !used.has(b.source) && (!reachable || reachable.has(b.source))) {
                used.add(b.source);
                changed = true;
            }
        }
    }
    if (!q) producers.forEach((p) => p.exchanges.forEach((_, e) => used.add(e)));

    const defaultExchange: Exchange = t.exchanges.find((e) => e.name === '') ?? {
        name: '', type: 'direct', durable: true, internal: false, arguments: {}, rateIn: 0, rateOut: 0,
    };
    const exchanges = [...t.exchanges.filter((e) => e.name !== ''), ...(used.has('') ? [defaultExchange] : [])]
        .filter((e) => used.has(e.name))
        .sort((a, b) => a.name.localeCompare(b.name));
    const exchangeIndex = new Map(exchanges.map((e, i) => [e.name, i]));
    const exchangeNames = new Set(exchanges.map((e) => e.name));

    // --- ordering
    const firstExchange = (name: string): number =>
        Math.min(...toQueue.filter((b) => b.destination === name).map((b) => exchangeIndex.get(b.source) ?? 999), 999);
    queues.sort((a, b) => firstExchange(a.name) - firstExchange(b.name) || a.name.localeCompare(b.name));
    const queueIndex = new Map(queues.map((x, i) => [x.name, i]));
    // one node per process, kept together by service ("Gerador de PDF" x5, then "Relatórios"...) and then by name
    const workers = t.workers
        .filter((w) => w.queues.some((wq) => queueNames.has(wq.queue)))
        .sort((a, b) => workerService(a).localeCompare(workerService(b)) || workerLabel(a).localeCompare(workerLabel(b), undefined, { numeric: true }));

    // --- nodes
    const nodes: GraphNode[] = [];
    let width = COLUMNS.worker.x + COLUMNS.worker.w + 40;
    let height = 300;
    const place = <T,>(kind: NodeKind, items: T[], build: (item: T) => Partial<GraphNode> & { id: string }): void => {
        const col = COLUMNS[kind];
        items.forEach((item, i) => {
            const part = build(item);
            nodes.push({ key: nodeKey(kind, part.id), kind, x: col.x, y: TOP + i * (col.h + GAP), w: col.w, h: col.h, ...part });
        });
        height = Math.max(height, TOP + items.length * (col.h + GAP) + 80);
    };
    place('producer', producers, (producer) => ({ id: producer.key, producer }));
    place('exchange', exchanges, (exchange) => ({ id: exchange.name, exchange }));
    place('queue', queues, (queue) => ({ id: queue.name, queue }));
    place('worker', workers, (worker) => ({ id: worker.name, worker }));

    // --- edges
    const edges: GraphEdge[] = [];
    const exchangeByName = new Map(exchanges.map((e) => [e.name, e]));
    const queueByName = new Map(queues.map((x) => [x.name, x]));

    for (const p of producers) {
        for (const [exchange, keys] of p.exchanges) {
            if (!exchangeNames.has(exchange)) continue;
            edges.push({
                from: nodeKey('producer', p.key),
                to: nodeKey('exchange', exchange),
                labels: [...keys.keys()],
                flow: p.recent > 0,
            });
        }
    }

    const groups = new Map<string, GraphEdge>();
    for (const b of t.bindings) {
        const toQ = b.destinationType === 'queue';
        if (!exchangeNames.has(b.source) || !(toQ ? queueNames.has(b.destination) : exchangeNames.has(b.destination))) continue;
        const from = nodeKey('exchange', b.source);
        const to = nodeKey(toQ ? 'queue' : 'exchange', b.destination);
        const id = `${from}>${to}`;
        const label = b.routingKey || jsonArgs(b.arguments);
        let edge = groups.get(id);
        if (!edge) {
            const dest = queueByName.get(b.destination);
            edge = { from, to, labels: [], flow: (exchangeByName.get(b.source)?.rateOut ?? 0) > 0 && (dest?.rateIn ?? 0) > 0 };
            groups.set(id, edge);
        }
        if (label && !edge.labels.includes(label)) edge.labels.push(label);
    }
    edges.push(...groups.values());

    // The default exchange takes no bindings: it routes straight to the queue named by the key.
    if (exchangeNames.has('')) {
        const direct = new Set(messages.filter((m) => m.exchange === '').flatMap((m) => m.queues).filter((name) => queueNames.has(name)));
        for (const name of direct) edges.push({ from: nodeKey('exchange', ''), to: nodeKey('queue', name), labels: [name], flow: false });
    }

    for (const w of workers) {
        for (const wq of w.queues) {
            const queue = queueByName.get(wq.queue);
            if (!queue) continue;
            edges.push({
                from: nodeKey('queue', wq.queue),
                to: nodeKey('worker', w.name),
                labels: wq.consumers > 1 ? [`×${wq.consumers}`] : [],
                flow: wq.activeConsumers > 0 && queue.rateDeliver > 0,
            });
        }
    }

    return { nodes, edges, width, height };
}
