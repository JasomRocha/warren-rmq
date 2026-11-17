import type { Queue } from '../types';

/**
 * A message priority in words. The number only means something against the queue that holds the message:
 * - quorum queues on RabbitMQ 4.3+: 32 strict levels, 0 to 31; a message without priority counts as 4;
 * - quorum queues before 4.3: only two levels, 0-4 normal and 5+ high, served 2:1 (so 1 and 4 are the same);
 * - classic queues: 0 up to the queue's x-max-priority; without priority it is 0, and without
 *   x-max-priority the queue ignores priorities altogether.
 */
export type PriorityLevel = 'very-low' | 'low' | 'normal' | 'high' | 'very-high' | 'ignored';

export interface PriorityReading {
    level: PriorityLevel;
    /** The number the broker works with (the default when the message carries none). */
    effective: number;
    /** The message had no priority property: it took the queue's default. */
    implicit: boolean;
}

const QUORUM_DEFAULT = 4;
const QUORUM_MAX = 31;

type QueueShape = Pick<Queue, 'type' | 'arguments'> | null | undefined;

function maxPriority(queue: QueueShape): number | null {
    const raw = (queue?.arguments as Record<string, unknown> | undefined)?.['x-max-priority'];
    const n = typeof raw === 'number' ? raw : Number(raw);
    return Number.isFinite(n) && n > 0 ? n : null;
}

/** "4.1.1" → true when quorum queues have strict 0-31 levels (4.3 and later). Unknown versions count as strict. */
export function strictQuorumPriorities(version: string | null | undefined): boolean {
    const [major, minor] = String(version ?? '').split('.').map(Number);
    if (!Number.isFinite(major) || !Number.isFinite(minor)) return true;
    return major > 4 || (major === 4 && minor >= 3);
}

export function readPriority(priority: number | null, queue?: QueueShape, brokerVersion?: string | null): PriorityReading {
    const implicit = priority === null;

    if (queue && queue.type !== 'quorum') {
        const max = maxPriority(queue);
        const effective = Math.min(priority ?? 0, max ?? 0);
        if (max === null) return { level: 'ignored', effective: 0, implicit };
        if (effective <= 0) return { level: 'normal', effective: 0, implicit };
        return { level: effective >= Math.ceil(max * 0.66) ? 'very-high' : 'high', effective, implicit };
    }

    // quorum, or a queue we do not know (the scale used across this ecosystem)
    const effective = Math.max(0, Math.min(QUORUM_MAX, priority ?? QUORUM_DEFAULT));
    if (!strictQuorumPriorities(brokerVersion)) return { level: effective > QUORUM_DEFAULT ? 'high' : 'normal', effective, implicit };
    let level: PriorityLevel;
    if (effective <= 1) level = 'very-low';
    else if (effective < QUORUM_DEFAULT) level = 'low';
    else if (effective === QUORUM_DEFAULT) level = 'normal';
    else if (effective < 16) level = 'high';
    else level = 'very-high';
    return { level, effective, implicit };
}
