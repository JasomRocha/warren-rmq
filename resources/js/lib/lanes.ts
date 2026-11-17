import { readPriority, strictQuorumPriorities, type PriorityReading } from './priority';
import type { MessageSummary, Queue, QueueEstimate, Worker } from '../types';

/**
 * A queue drawn as a line of packages, from the head (next out, beside the worker) to the tail.
 *
 * Positions come from the estimator (arrival order, the oldest `unacked` already with a worker) and the
 * broker's dispatch rule on top: a quorum queue on RabbitMQ 4.3+ hands out the highest priority first,
 * so a priority package overtakes the ones that arrived before it. Before 4.3, and on classic queues
 * without x-max-priority, the order stays the arrival order.
 */
export interface LanePackage {
    message: MessageSummary;
    priority: PriorityReading;
}

export interface Lane {
    queue: Queue;
    /** With a worker right now. */
    processing: LanePackage[];
    /** Waiting, head first. */
    waiting: LanePackage[];
    /** Messages from before the tap started: in the queue, but nothing is known about them. */
    unknownProcessing: number;
    unknownWaiting: number;
    workers: Worker[];
}

export interface LaneOptions {
    brokerVersion?: string | null;
    /** Also draw queues with no consumer (results, failures), which never move. */
    includeIdle?: boolean;
}

function headFirst(queue: Queue, brokerVersion: string | null | undefined, packages: LanePackage[]): LanePackage[] {
    const strict = queue.type === 'quorum' ? strictQuorumPriorities(brokerVersion) : packages.some((p) => p.priority.level !== 'ignored');
    if (!strict) return packages;
    // stable: equal priorities keep their arrival order
    return packages
        .map((p, i) => ({ p, i }))
        .sort((a, b) => b.p.priority.effective - a.p.priority.effective || a.i - b.i)
        .map(({ p }) => p);
}

export function buildLanes(queues: Queue[], estimates: Map<string, QueueEstimate>, workers: Worker[], options: LaneOptions = {}): Lane[] {
    return queues
        .filter((q) => q.ready + q.unacked > 0 || q.consumers > 0)
        .filter((q) => options.includeIdle || q.consumers > 0)
        .map((queue) => {
            const e = estimates.get(queue.name);
            const unknown = e?.unknown ?? 0;
            const unacked = e?.unacked ?? queue.unacked;
            const unknownProcessing = Math.min(unknown, unacked);
            const known = (e?.messages ?? []).map((message) => ({ message, priority: readPriority(message.priority, queue, options.brokerVersion) }));
            const knownProcessing = Math.max(0, unacked - unknownProcessing);
            return {
                queue,
                processing: known.slice(0, knownProcessing),
                waiting: headFirst(queue, options.brokerVersion, known.slice(knownProcessing)),
                unknownProcessing,
                unknownWaiting: unknown - unknownProcessing,
                workers: workers.filter((w) => w.queues.some((wq) => wq.queue === queue.name)),
            };
        })
        .sort((a, b) => b.queue.ready - a.queue.ready || b.queue.unacked - a.queue.unacked || a.queue.name.localeCompare(b.queue.name));
}

/** How many packages of each instance wait in a lane, busiest first: the lane's caption. */
export function laneShare(lane: Lane): { key: string; count: number }[] {
    const counts = new Map<string, number>();
    for (const p of lane.waiting) counts.set(p.message.instance ?? '', (counts.get(p.message.instance ?? '') ?? 0) + 1);
    return [...counts].map(([key, count]) => ({ key, count })).sort((a, b) => b.count - a.count || a.key.localeCompare(b.key));
}
