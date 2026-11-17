import { computed, shallowRef, triggerRef, ref, watch, type Ref } from 'vue';
import type { MessageSummary, TapStatus } from '../types';

/**
 * Keeps the recent messages of one connection in memory, downloading only what changed since the last poll.
 * `base` is the API root of the connection being looked at ("/api/b/3"); switching it starts over.
 */
export function useMessages(base: Ref<string | null>) {
    const byId = shallowRef(new Map<number, MessageSummary>());
    const tap = ref<TapStatus | null>(null);
    const now = ref(Date.now());
    let rev = 0;
    let generation = 0;

    function reset(): void {
        generation++;
        rev = 0;
        byId.value = new Map();
        tap.value = null;
    }

    watch(base, reset);

    async function poll(): Promise<void> {
        const root = base.value;
        if (root === null) return;
        const mine = generation;

        const response = await fetch(`${root}/events?since=${rev}`, { headers: { Accept: 'application/json' } });
        if (!response.ok || mine !== generation) return;
        const body = (await response.json()) as { rev: number; firstId: number; messages: MessageSummary[]; tap: TapStatus };
        if (mine !== generation) return; // the connection changed while this was in flight

        tap.value = body.tap;
        now.value = Date.now();

        if (body.rev < rev) {
            // The history was wiped: start over.
            byId.value.clear();
            rev = 0;
            triggerRef(byId);
            return;
        }
        rev = body.rev;
        for (const m of body.messages) byId.value.set(m.id, m);
        for (const id of byId.value.keys()) {
            if (id >= body.firstId) break;
            byId.value.delete(id);
        }
        triggerRef(byId);
    }

    /** Oldest first. */
    const all = computed(() => [...byId.value.values()]);

    return { all, tap, now, poll, reset };
}
