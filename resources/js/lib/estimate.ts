import type { InstanceStat, MessageSummary, Queue, QueueEstimate } from '../types';

/**
 * Without the broker's trace, nothing says when a message leaves a queue. So we assume arrival order:
 * the queue holds the N most recently routed messages, N being its current depth. The oldest `unacked`
 * of them are already with a worker; anything published before the tap started fills those slots first.
 */
export function estimateQueues(messages: MessageSummary[], queues: Queue[]): Map<string, QueueEstimate> {
    const routed = new Map<string, MessageSummary[]>();
    for (const m of messages) {
        for (const q of m.queues) {
            if (m.deliveries.some((d) => d.queue === q)) continue; // trace says it was delivered
            let list = routed.get(q);
            if (!list) routed.set(q, (list = []));
            list.push(m);
        }
    }

    const out = new Map<string, QueueEstimate>();
    for (const q of queues) {
        const depth = q.ready + q.unacked;
        const known = depth > 0 ? (routed.get(q.name) ?? []).slice(-depth) : [];
        out.set(q.name, {
            messages: known,
            unacked: q.unacked,
            unknown: depth - known.length,
            index: new Map(known.map((m, i) => [m.id, i])),
        });
    }
    return out;
}

export function isProcessing(e: QueueEstimate, id: number): boolean {
    const position = e.index.get(id);
    return position !== undefined && e.unknown + position < e.unacked;
}

export function instanceStats(messages: MessageSummary[], estimates: Map<string, QueueEstimate>, now: number): InstanceStat[] {
    const stats = new Map<string, InstanceStat>();
    const of = (instance: string | null): InstanceStat => {
        const key = instance ?? '';
        let s = stats.get(key);
        if (!s) stats.set(key, (s = { key, total: 0, last: 0, recent: 0, processing: 0, waiting: 0, queues: new Set(), exchanges: new Map() }));
        return s;
    };

    for (const m of messages) {
        const s = of(m.instance);
        s.total++;
        s.last = Math.max(s.last, m.ts);
        if (now - m.ts < 60_000) s.recent++;
        let keys = s.exchanges.get(m.exchange);
        if (!keys) s.exchanges.set(m.exchange, (keys = new Map()));
        keys.set(m.routingKey, (keys.get(m.routingKey) ?? 0) + 1);
        m.queues.forEach((q) => s.queues.add(q));
    }

    for (const e of estimates.values()) {
        for (const m of e.messages) {
            if (isProcessing(e, m.id)) of(m.instance).processing++;
            else of(m.instance).waiting++;
        }
    }
    return [...stats.values()];
}

export type Situation =
    | { kind: 'noroute' }
    | { kind: 'processing' }
    | { kind: 'waiting' }
    | { kind: 'delivered'; at: number }
    | { kind: 'left' };

export function situation(m: MessageSummary, estimates: Map<string, QueueEstimate>): Situation {
    if (m.queues.length === 0) return { kind: 'noroute' };
    for (const q of m.queues) {
        const e = estimates.get(q);
        if (e?.index.has(m.id)) return isProcessing(e, m.id) ? { kind: 'processing' } : { kind: 'waiting' };
    }
    const last = m.deliveries.at(-1);
    return last ? { kind: 'delivered', at: last.ts } : { kind: 'left' };
}

/** Busiest first: more being processed, then more waiting, then more recent traffic. */
export function byBusiness(a: InstanceStat, b: InstanceStat): number {
    return b.processing - a.processing || b.waiting - a.waiting || b.recent - a.recent || b.last - a.last || a.key.localeCompare(b.key);
}
