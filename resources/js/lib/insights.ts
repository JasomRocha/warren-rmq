import type { Category, InstanceStat, MessageSummary, Queue, QueueRole, Topology, Worker } from '../types';
import { queueLabel } from './format';
import { workerLabel } from './names';

/** Below this rate a queue is considered not moving (messages per second). */
const IDLE_RATE = 0.05;
/** A queue that needs longer than this to empty is worth a look (seconds). */
const SLOW_DRAIN_SECONDS = 300;
const WAIT_WINDOW_MS = 10 * 60_000;
const NO_ROUTE_WINDOW_MS = 5 * 60_000;

export type Level = 'ok' | 'warning' | 'critical';
const order: Record<Level, number> = { ok: 0, warning: 1, critical: 2 };
const worst = (a: Level, b: Level): Level => (order[a] >= order[b] ? a : b);

/**
 * Average time a message spends being processed, by Little's law: messages delivered and not yet acked,
 * divided by how many are acked per second. The broker does not report the real per-message time (its trace
 * has publishes and deliveries, not acks), so this is an estimate, valid when the flow is steady.
 */
export function processingSeconds(unacked: number, ackRate: number): number | null {
    return unacked > 0 && ackRate >= IDLE_RATE ? unacked / ackRate : null;
}

/** How long the waiting messages need to leave at the current delivery rate. Infinity: nothing is leaving. */
export function drainSeconds(ready: number, deliverRate: number): number | null {
    if (ready <= 0) return null;
    return deliverRate >= IDLE_RATE ? ready / deliverRate : Infinity;
}

export interface QueueInsight {
    name: string;
    label: string;
    description: string | null;
    category: string | null;
    role: QueueRole;
    ready: number;
    unacked: number;
    consumers: number;
    rateIn: number;
    rateDeliver: number;
    rateAck: number;
    /** Estimated average processing time (Little's law). */
    processingSeconds: number | null;
    /** Time to empty the waiting messages at the current rate. */
    drainSeconds: number | null;
    /** Average time from publish to delivery, measured from the broker trace (needs trace on). */
    waitSeconds: number | null;
    level: Level;
}

/** Publish → delivery time per queue, over the last minutes. Needs the trace; null where there is no data. */
export function waitByQueue(messages: MessageSummary[], now: number): Map<string, number> {
    const sums = new Map<string, { total: number; n: number }>();
    for (const m of messages) {
        for (const d of m.deliveries) {
            if (now - d.ts > WAIT_WINDOW_MS || d.ts < m.ts) continue;
            const s = sums.get(d.queue) ?? { total: 0, n: 0 };
            s.total += (d.ts - m.ts) / 1000;
            s.n++;
            sums.set(d.queue, s);
        }
    }
    return new Map([...sums].map(([queue, s]) => [queue, s.total / s.n]));
}

export function levelOf(q: Pick<Queue, 'ready' | 'consumers'> & { role: QueueRole; drain: number | null }): Level {
    if (q.role === 'falhas') return q.ready > 0 ? 'critical' : 'ok';
    if (q.role === 'espera') return q.ready > 0 ? 'warning' : 'ok';
    if (q.role === 'passiva') return 'ok'; // no consumer by design
    if (q.ready > 0 && q.consumers === 0) return 'critical';
    if (q.drain !== null && q.drain > SLOW_DRAIN_SECONDS) return Number.isFinite(q.drain) ? 'warning' : 'critical';
    return 'ok';
}

export function queueInsights(topology: Topology, messages: MessageSummary[], now: number): QueueInsight[] {
    const waits = waitByQueue(messages, now);
    return topology.queues.map((q) => {
        const drain = drainSeconds(q.ready, q.rateDeliver);
        const role = q.catalog?.role ?? 'principal';
        return {
            name: q.name,
            label: queueLabel(q),
            description: q.catalog?.description ?? null,
            category: q.catalog?.category ?? null,
            role,
            ready: q.ready,
            unacked: q.unacked,
            consumers: q.consumers,
            rateIn: q.rateIn,
            rateDeliver: q.rateDeliver,
            rateAck: q.rateAck,
            processingSeconds: processingSeconds(q.unacked, q.rateAck),
            drainSeconds: drain,
            waitSeconds: waits.get(q.name) ?? null,
            level: levelOf({ ready: q.ready, consumers: q.consumers, role, drain }),
        };
    });
}

/** Queues that carry work: main ones, and the ones with no consumer by design (still part of the volume). */
export const isThroughput = (q: Pick<QueueInsight, 'role'>): boolean => q.role === 'principal' || q.role === 'passiva';

export interface AreaInsight {
    key: string;
    name: string;
    description: string | null;
    color: string | null;
    queues: number;
    ready: number;
    unacked: number;
    rateIn: number;
    rateDeliver: number;
    /** Messages waiting for a new attempt, and messages that failed for good, in this area. */
    retry: number;
    failures: number;
    processingSeconds: number | null;
    drainSeconds: number | null;
    level: Level;
}

/** Business areas from the catalog. Waiting-for-retry and failure queues count apart from throughput. */
export function areaInsights(queues: QueueInsight[], categories: Category[], noAreaName: string): AreaInsight[] {
    const known = new Map(categories.map((c) => [c.key, c]));
    const groups = new Map<string, QueueInsight[]>();
    for (const q of queues) {
        const key = q.category && known.has(q.category) ? q.category : '';
        groups.set(key, [...(groups.get(key) ?? []), q]);
    }

    const rows: AreaInsight[] = [];
    for (const [key, list] of groups) {
        const main = list.filter(isThroughput);
        const sum = (f: (q: QueueInsight) => number, from = main): number => from.reduce((total, q) => total + f(q), 0);
        const category = known.get(key);
        const ready = sum((q) => q.ready);
        const unacked = sum((q) => q.unacked);
        const rateAck = sum((q) => q.rateAck);
        const rateDeliver = sum((q) => q.rateDeliver);
        rows.push({
            key,
            name: category?.name ?? noAreaName,
            description: category?.description ?? null,
            color: category?.color ?? null,
            queues: list.length,
            ready,
            unacked,
            rateIn: sum((q) => q.rateIn),
            rateDeliver,
            retry: sum((q) => q.ready, list.filter((q) => q.role === 'espera')),
            failures: sum((q) => q.ready, list.filter((q) => q.role === 'falhas')),
            processingSeconds: processingSeconds(unacked, rateAck),
            drainSeconds: drainSeconds(ready, rateDeliver),
            level: list.reduce<Level>((l, q) => worst(l, q.level), 'ok'),
        });
    }

    const position = new Map(categories.map((c, i) => [c.key, i]));
    return rows.sort((a, b) => (position.get(a.key) ?? 999) - (position.get(b.key) ?? 999));
}

export interface InstanceRank {
    key: string;
    total: number;
    recent: number;
    processing: number;
    waiting: number;
    pending: number;
    /** Share of everything pending right now / of the traffic of the last minute, 0..1. */
    shareOfPending: number;
    shareOfRate: number;
    /** Business area where most of its messages went (needs the catalog). */
    topArea: string | null;
    /** True when this instance holds at least half of the backlog and at least twice as much as any other. */
    dominates: boolean;
}

/** Who is sending the most and who is loading the queues: instances ranked by what they have pending. */
export function instanceRanking(
    stats: InstanceStat[],
    messages: MessageSummary[],
    queueCategory: Map<string, string | null>,
    genericAreas: ReadonlySet<string> = new Set(),
): InstanceRank[] {
    const totalPending = stats.reduce((n, s) => n + s.processing + s.waiting, 0);
    const totalRate = stats.reduce((n, s) => n + s.recent, 0);

    const areas = new Map<string, Map<string, number>>();
    for (const m of messages) {
        for (const q of m.queues) {
            const category = queueCategory.get(q);
            if (!category || genericAreas.has(category)) continue;
            const counts = areas.get(m.instance ?? '') ?? new Map<string, number>();
            counts.set(category, (counts.get(category) ?? 0) + 1);
            areas.set(m.instance ?? '', counts);
        }
    }

    const pendingOf = (s: InstanceStat): number => s.processing + s.waiting;

    return stats
        .map((s) => {
            const pending = pendingOf(s);
            const biggestOther = Math.max(0, ...stats.filter((o) => o !== s).map(pendingOf));
            const top = [...(areas.get(s.key) ?? [])].sort((a, b) => b[1] - a[1])[0];
            const shareOfPending = totalPending > 0 ? pending / totalPending : 0;
            return {
                key: s.key,
                total: s.total,
                recent: s.recent,
                processing: s.processing,
                waiting: s.waiting,
                pending,
                shareOfPending,
                shareOfRate: totalRate > 0 ? s.recent / totalRate : 0,
                topArea: top?.[0] ?? null,
                dominates: stats.length > 1 && pending >= 10 && shareOfPending >= 0.5 && pending >= 2 * biggestOther,
            };
        })
        .sort((a, b) => b.pending - a.pending || b.recent - a.recent || b.total - a.total || a.key.localeCompare(b.key));
}

export interface WorkerInsight {
    name: string;
    /** The name people recognise (from the catalog), or the technical name. */
    label: string;
    consumers: number;
    queues: number;
    processing: number;
    /** Messages it can hold at once (consumers × prefetch). Null when some consumer has no prefetch limit. */
    capacity: number | null;
    /** processing / capacity, 0..1+. */
    saturation: number | null;
    processingSeconds: number | null;
}

/** The worker's share of a queue's unacked messages and acks is proportional to its consumers there. */
export function workerInsights(workers: Worker[], queues: Queue[]): WorkerInsight[] {
    const byName = new Map(queues.map((q) => [q.name, q]));
    return workers
        .map((w) => {
            let unacked = 0;
            let acks = 0;
            let capacity: number | null = 0;
            for (const wq of w.queues) {
                const q = byName.get(wq.queue);
                const share = q && q.consumers > 0 ? wq.consumers / q.consumers : 0;
                unacked += (q?.unacked ?? 0) * share;
                acks += (q?.rateAck ?? 0) * share;
                capacity = capacity !== null && wq.prefetchTotal > 0 ? capacity + wq.prefetchTotal : null;
            }
            return {
                name: w.name,
                label: workerLabel(w),
                consumers: w.consumers,
                queues: w.queues.length,
                processing: Math.round(unacked),
                capacity,
                saturation: capacity ? unacked / capacity : null,
                processingSeconds: processingSeconds(unacked, acks),
            };
        })
        .sort((a, b) => b.processing - a.processing || a.name.localeCompare(b.name));
}

export type AlertCode = 'failures' | 'no-consumer' | 'stalled' | 'slow-drain' | 'retry-backlog' | 'no-route';

export interface Alert {
    level: Exclude<Level, 'ok'>;
    code: AlertCode;
    /** Queue the alert is about, when it is about one. */
    queue: string | null;
    label: string;
    /** ready (count) for queue alerts, seconds for slow-drain, count for no-route. */
    value: number;
}

export function alerts(queues: QueueInsight[], messages: MessageSummary[], now: number): Alert[] {
    const out: Alert[] = [];
    for (const q of queues) {
        const base = { queue: q.name, label: q.label };
        if (q.role === 'falhas' && q.ready > 0) out.push({ ...base, level: 'critical', code: 'failures', value: q.ready });
        else if (q.role === 'espera' && q.ready > 0) out.push({ ...base, level: 'warning', code: 'retry-backlog', value: q.ready });
        else if (q.role === 'principal' && q.ready > 0 && q.consumers === 0) out.push({ ...base, level: 'critical', code: 'no-consumer', value: q.ready });
        else if (q.role === 'principal' && q.ready > 0 && q.drainSeconds === Infinity) out.push({ ...base, level: 'critical', code: 'stalled', value: q.ready });
        else if (q.role === 'principal' && q.drainSeconds !== null && q.drainSeconds > SLOW_DRAIN_SECONDS) {
            out.push({ ...base, level: 'warning', code: 'slow-drain', value: q.drainSeconds });
        }
    }

    const lost = messages.filter((m) => m.queues.length === 0 && now - m.ts <= NO_ROUTE_WINDOW_MS).length;
    if (lost > 0) out.push({ level: 'warning', code: 'no-route', queue: null, label: '', value: lost });

    return out.sort((a, b) => order[b.level] - order[a.level] || b.value - a.value);
}

export interface Summary {
    waiting: number;
    processing: number;
    ratePerMinute: number;
    processingSeconds: number | null;
    critical: number;
    warnings: number;
}

export function summary(queues: QueueInsight[], list: Alert[]): Summary {
    const main = queues.filter(isThroughput);
    const unacked = main.reduce((n, q) => n + q.unacked, 0);
    const acks = main.reduce((n, q) => n + q.rateAck, 0);
    return {
        waiting: main.reduce((n, q) => n + q.ready, 0),
        processing: unacked,
        ratePerMinute: main.reduce((n, q) => n + q.rateIn, 0) * 60,
        processingSeconds: processingSeconds(unacked, acks),
        critical: list.filter((a) => a.level === 'critical').length,
        warnings: list.filter((a) => a.level === 'warning').length,
    };
}
