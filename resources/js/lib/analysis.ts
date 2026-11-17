import type { MetricPoint, Queue } from '../types';

/**
 * Operational analysis of the work queues: what was processed, how long a message waits, whether the
 * backlog is draining and how saturated the consumers are. Pure functions, so every number on the
 * dashboard can be checked in a test.
 */

/** Below this many acks per second a queue is treated as not draining at all. */
const STALLED = 0.01;

/** A queue the dashboard counts: something consumes it. Result, retry and failure queues have no consumer by design. */
export const isWorkQueue = (q: Pick<Queue, 'consumers' | 'catalog'>): boolean =>
    q.consumers > 0 && (q.catalog?.role ?? 'principal') === 'principal';

/**
 * Little's law (L = λW): with L messages in the system and λ leaving per second, a message that arrives now
 * waits about L / λ seconds before it is done. Infinity when there is backlog and nothing leaves; 0 when empty.
 */
export function littleWait(inSystem: number, ackRate: number): number {
    if (inSystem <= 0) return 0;
    return ackRate < STALLED ? Infinity : inSystem / ackRate;
}

/** Seconds until the backlog is gone at the current rates, or null when it is not shrinking. */
export function drainTime(backlog: number, ackRate: number, inRate: number): number | null {
    if (backlog <= 0) return 0;
    const net = ackRate - inRate;
    return net < STALLED ? null : backlog / net;
}

/**
 * The area under a rate (messages per second) over time: how many messages that rate amounts to.
 * A gap well beyond the usual spacing (the collector was down) is not filled in. The spacing grows with the
 * period, because the server averages long periods into fewer points (18 min apart over three days).
 */
export function integrate(times: number[], rates: number[], maxGap?: number): number {
    const steps = times.slice(1).map((t, i) => t - times[i]).filter((d) => d > 0).sort((a, b) => a - b);
    const typical = steps.length ? steps[Math.floor(steps.length / 2)] : 0;
    const limit = maxGap ?? Math.max(300, typical * 3);
    let total = 0;
    for (let i = 1; i < times.length; i++) {
        const dt = times[i] - times[i - 1];
        if (dt <= 0 || dt > limit) continue;
        total += ((rates[i] + rates[i - 1]) / 2) * dt;
    }
    return total;
}

/** The p-th percentile (0..100) of the finite values, nearest-rank. Null when there are none. */
export function percentile(values: number[], p: number): number | null {
    const sorted = values.filter((v) => Number.isFinite(v)).sort((a, b) => a - b);
    if (sorted.length === 0) return null;
    const rank = Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1));
    return sorted[rank];
}

export interface Peak {
    value: number;
    t: number;
}

function peak(samples: MetricPoint[], pick: (s: MetricPoint) => number): Peak | null {
    let best: Peak | null = null;
    for (const s of samples) {
        const value = pick(s);
        if (best === null || value > best.value) best = { value, t: s.t };
    }
    return best;
}

export interface PeriodSummary {
    /** Messages acknowledged (processed) in the period. */
    processed: number;
    /** Messages that arrived in the work queues in the period. */
    received: number;
    /** Average processing rate over the period. */
    throughput: number;
    peakThroughput: Peak | null;
    backlogPeak: Peak | null;
    /** Last sample minus first: positive means the queues grew over the period. */
    backlogChange: number;
    /** Estimated wait (Little's law) for each sample, in seconds. */
    waits: number[];
    waitMedian: number | null;
    waitP95: number | null;
    /** Share of deliveries that were redeliveries (a message handed out again after a failure or a lost consumer). */
    redeliveryRatio: number | null;
    /** Average consumer capacity (0..1), when the broker reports it. */
    capacity: number | null;
    /** Share of the period in which the backlog grew from one sample to the next. */
    growingShare: number;
}

export function summarize(samples: MetricPoint[]): PeriodSummary {
    const times = samples.map((s) => s.t);
    const processed = integrate(times, samples.map((s) => s.rateAck));
    const received = integrate(times, samples.map((s) => s.rateIn));
    const delivered = integrate(times, samples.map((s) => s.rateDeliver));
    const redelivered = integrate(times, samples.map((s) => s.rateRedeliver ?? 0));
    const span = times.length > 1 ? times[times.length - 1] - times[0] : 0;
    const waits = samples.map((s) => littleWait(s.ready + s.unacked, s.rateAck));
    const capacities = samples.map((s) => s.capacity).filter((c): c is number => typeof c === 'number');
    const backlog = samples.map((s) => s.ready + s.unacked);
    let grew = 0;
    for (let i = 1; i < backlog.length; i++) if (backlog[i] > backlog[i - 1]) grew++;

    return {
        processed,
        received,
        throughput: span > 0 ? processed / span : 0,
        peakThroughput: peak(samples, (s) => s.rateAck),
        backlogPeak: peak(samples, (s) => s.ready + s.unacked),
        backlogChange: backlog.length > 1 ? backlog[backlog.length - 1] - backlog[0] : 0,
        waits,
        waitMedian: percentile(waits, 50),
        waitP95: percentile(waits, 95),
        redeliveryRatio: delivered > 0 ? redelivered / delivered : null,
        capacity: capacities.length ? capacities.reduce((a, b) => a + b, 0) / capacities.length : null,
        growingShare: backlog.length > 1 ? grew / (backlog.length - 1) : 0,
    };
}

export type QueueTrend = 'idle' | 'draining' | 'steady' | 'growing' | 'stalled';

export interface QueueHealth {
    queue: Queue;
    backlog: number;
    wait: number;
    drain: number | null;
    capacity: number | null;
    redeliveryRatio: number | null;
    trend: QueueTrend;
}

/** How each work queue is doing right now, the slowest to get through first. */
export function queueHealth(queues: Queue[]): QueueHealth[] {
    return queues
        .filter(isWorkQueue)
        .map((q) => {
            const backlog = q.ready + q.unacked;
            const net = q.rateAck - q.rateIn;
            let trend: QueueTrend;
            if (backlog === 0 && q.rateIn < STALLED) trend = 'idle';
            else if (q.ready > 0 && q.rateAck < STALLED) trend = 'stalled';
            else if (net > Math.max(STALLED, q.rateIn * 0.1)) trend = 'draining';
            else if (-net > Math.max(STALLED, q.rateAck * 0.1) && q.ready > 0) trend = 'growing';
            else trend = 'steady';
            return {
                queue: q,
                backlog,
                wait: littleWait(backlog, q.rateAck),
                drain: drainTime(q.ready, q.rateAck, q.rateIn),
                capacity: q.consumerCapacity ?? null,
                redeliveryRatio: q.rateDeliver > STALLED ? (q.rateRedeliver ?? 0) / q.rateDeliver : null,
                trend,
            };
        })
        .sort((a, b) => b.wait - a.wait || b.backlog - a.backlog);
}
