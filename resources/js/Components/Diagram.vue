<script setup lang="ts">
import { computed, ref } from 'vue';
import { buildGraph, COLUMNS } from '../lib/layout';
import { clock, instanceColor, number, prefetchText, queueLabel, rate } from '../lib/format';
import { useI18n, type Key } from '../lib/i18n';
import { instanceName, withKey, workerLabel } from '../lib/names';
import type { InstanceStat, MessageSummary, NodeKind, QueueEstimate, Selection, Topology } from '../types';

const props = defineProps<{
    topology: Topology;
    query: string;
    showKeys: boolean;
    selected: Selection | null;
    stats: InstanceStat[];
    messages: MessageSummary[];
    estimates: Map<string, QueueEstimate>;
    ticked: ReadonlySet<string>;
}>();
const emit = defineEmits<{ select: [Selection] }>();
const { t } = useI18n();

const MAX_CHIPS = 6;
const MAX_BUBBLES = 24;

const graph = computed(() =>
    buildGraph({ topology: props.topology, query: props.query, stats: props.stats, messages: props.messages, selected: props.ticked }),
);
const byKey = computed(() => new Map(graph.value.nodes.map((n) => [n.key, n])));

interface Path {
    d: string;
    flow: boolean;
    from: string;
    to: string;
}
interface Chip {
    from: string;
    to: string;
    x: number;
    y: number;
    w: number;
    text: string;
    title?: string;
}

/**
 * With hundreds of connections the diagram is a tangle. Passing the mouse over a card keeps only its own
 * connections (and the cards at their ends) at full strength, so "this worker reads these queues" is one glance.
 */
const hovered = ref<string | null>(null);
const touches = (a: { from: string; to: string }): boolean => hovered.value !== null && (a.from === hovered.value || a.to === hovered.value);
const neighbours = computed(() => {
    const set = new Set<string>();
    if (hovered.value === null) return set;
    set.add(hovered.value);
    for (const e of graph.value.edges) {
        if (e.from === hovered.value) set.add(e.to);
        if (e.to === hovered.value) set.add(e.from);
    }
    return set;
});
const dimmed = (key: string): boolean => hovered.value !== null && !neighbours.value.has(key);

const geometry = computed(() => {
    const paths: Path[] = [];
    const chips: Chip[] = [];
    for (const e of graph.value.edges) {
        const a = byKey.value.get(e.from);
        const b = byKey.value.get(e.to);
        if (!a || !b) continue;
        const x1 = a.x + a.w, y1 = a.y + a.h / 2, x2 = b.x, y2 = b.y + b.h / 2;
        const k = Math.max(40, Math.abs(x2 - x1) / 2);
        paths.push({ d: `M${x1} ${y1} C${x1 + k} ${y1} ${x2 - k} ${y2} ${x2} ${y2}`, flow: e.flow, from: e.from, to: e.to });
        if (!props.showKeys || e.labels.length === 0) continue;

        // What a producer publishes with is different for almost every message: dozens of keys per producer drown the
        // diagram, so those appear only while the pointer is on that producer (and always in its details).
        // The keys of a binding are few and fixed, so they stay on screen, complete.
        if (e.from.startsWith('producer:') && hovered.value !== e.from) continue;
        const shown = e.labels.slice(0, MAX_CHIPS);
        const extra = e.labels.length - shown.length;
        const texts = extra > 0 ? [...shown, `+${extra} ${t('more')}`] : shown;
        const mx = (x1 + x2) / 2, my = (y1 + y2) / 2;
        texts.forEach((text, i) =>
            chips.push({
                from: e.from, to: e.to, x: mx, y: my + (i - (texts.length - 1) / 2) * 19, w: text.length * 6.4 + 12, text,
                title: extra > 0 && i === texts.length - 1 ? e.labels.join('\n') : undefined,
            }),
        );
    }
    return { paths, chips };
});

const titles: { kind: NodeKind; title: Key; sub: Key }[] = [
    { kind: 'producer', title: 'col.producers', sub: 'col.producers.sub' },
    { kind: 'exchange', title: 'col.exchanges', sub: 'col.exchanges.sub' },
    { kind: 'queue', title: 'col.queues', sub: 'col.queues.sub' },
    { kind: 'worker', title: 'col.workers', sub: 'col.workers.sub' },
];


const bindCount = (name: string, side: 'source' | 'destination'): number => props.topology.bindings.filter((b) => b[side] === name).length;
const isSelected = (key: string): boolean => props.selected !== null && `${props.selected.kind}:${props.selected.id}` === key;
const nodeTitle = (kind: NodeKind, id: string): string => {
    if (kind === 'queue') {
        const q = props.topology.queues.find((x) => x.name === id);
        return q ? queueLabel(q) : id;
    }
    if (kind === 'worker') {
        const w = props.topology.workers.find((x) => x.name === id);
        return w ? workerLabel(w) : id;
    }
    return kind === 'producer' ? instanceName(id, t('no-instance')) : id || t('default-exchange');
};
const kindText = (kind: NodeKind): string => t(`kind.${kind}` as 'kind.queue');
const areaColor = (area: string | null): string | undefined => props.topology.categories.find((c) => c.key === area)?.color ?? undefined;

/** The newest messages sitting in a queue, as coloured dots (one hue per instance). */
const bubbles = (queue: string): { id: number; color: string; title: string }[] =>
    (props.estimates.get(queue)?.messages ?? []).slice(-MAX_BUBBLES).map((m) => ({ id: m.id, color: instanceColor(m.instance ?? ''), title: instanceName(m.instance, t('no-instance')) }));
const unknownDots = (queue: string): number => Math.min(props.estimates.get(queue)?.unknown ?? 0, MAX_BUBBLES);
</script>

<template>
    <div class="stage" :style="{ width: graph.width + 'px', height: graph.height + 'px' }">
        <div v-for="c in titles" :key="c.kind" class="col-title" :class="c.kind" :style="{ left: COLUMNS[c.kind].x + 'px' }">
            <b>{{ t(c.title) }}</b><span>{{ t(c.sub) }}</span>
        </div>

        <svg class="layer" :width="graph.width" :height="graph.height" aria-hidden="true">
            <path v-for="(p, i) in geometry.paths" :key="i" :d="p.d" class="edge" :class="{ flow: p.flow, active: touches(p), dim: hovered !== null && !touches(p) }" />
        </svg>

        <button
            v-for="n in graph.nodes"
            :key="n.key"
            type="button"
            class="node"
            :class="[n.kind, { selected: isSelected(n.key), ticked: n.producer && ticked.has(n.producer.key), dimmed: dimmed(n.key) }]"
            @pointerenter="hovered = n.key"
            @pointerleave="hovered = null"
            :style="{ left: n.x + 'px', top: n.y + 'px', width: n.w + 'px', height: n.h + 'px', borderLeft: n.queue && areaColor(n.queue.catalog.category) ? `4px solid ${areaColor(n.queue.catalog.category)}` : undefined }"
            @click="emit('select', { kind: n.kind, id: n.id })"
        >
            <span class="node-head" :title="withKey(nodeTitle(n.kind, n.id), n.kind === 'exchange' ? '' : n.id)"><i class="tag">{{ kindText(n.kind) }}</i><span class="node-name">{{ nodeTitle(n.kind, n.id) }}</span></span>

            <span v-if="n.producer" class="node-body">
                <span><i class="dot" :style="{ background: instanceColor(n.producer.key) }"></i><b>{{ number(n.producer.total) }}</b> {{ t('published') }} · <b>{{ number(n.producer.recent) }}</b>{{ t('per-min') }}</span>
                <span class="big">{{ t('processing') }} <b>{{ number(n.producer.processing) }}</b> · {{ t('waiting') }} <b>{{ number(n.producer.waiting) }}</b></span>
                <span>{{ t('last') }} {{ clock(n.producer.last) }}</span>
            </span>

            <span v-else-if="n.exchange" class="node-body">
                <span><i class="pill">{{ n.exchange.type }}</i>{{ n.exchange.rateIn > 0 ? `${t('rate-in')} ${rate(n.exchange.rateIn)}` : '' }}</span>
                <span>{{ t('count.binds', { n: number(bindCount(n.id, 'source')) }) }}</span>
            </span>

            <span v-else-if="n.queue" class="node-body">
                <span class="big">{{ t('waiting') }} <b>{{ number(n.queue.ready) }}</b> · {{ t('processing') }} <b>{{ number(n.queue.unacked) }}</b></span>
                <span class="bubbles">
                    <i v-for="b in bubbles(n.id)" :key="b.id" class="bubble" :style="{ background: b.color }" :title="b.title"></i>
                    <i v-for="k in unknownDots(n.id)" :key="'u' + k" class="bubble unknown" :title="t('estimated')"></i>
                </span>
                <span>
                    <i class="pill">{{ n.queue.type }}</i><i v-if="n.queue.durable" class="pill">D</i>
                    <i v-if="n.queue.catalog.role !== 'principal'" class="pill" :class="n.queue.catalog.role">{{ t(`queue.role.${n.queue.catalog.role}` as 'queue.role.espera') }}</i>
                </span>
                <span v-if="n.queue.catalog.name" class="technical" :title="n.id">{{ n.id }}</span>
                <span>{{ t('count.binds', { n: number(bindCount(n.id, 'destination')) }) }} · {{ t('count.consumers', { n: number(n.queue.consumers) }) }}</span>
            </span>

            <span v-else-if="n.worker" class="node-body">
                <span class="big" :title="t('estimated')">{{ t('processing') }} <b>~{{ number(n.worker.processingEstimate) }}</b></span>
                <span>{{ t('count.queues', { n: number(n.worker.queues.length) }) }} · {{ t('count.consumers', { n: number(n.worker.consumers) }) }}</span>
                <span>
                    <i class="pill ok">{{ n.worker.manualAck ? t('manual-ack') : t('auto-ack') }}</i>
                    <i class="pill">{{ t('prefetch') }}: {{ prefetchText(n.worker.prefetchMax, t('unlimited')) }}</i>
                </span>
            </span>
        </button>

        <svg class="layer chips" :width="graph.width" :height="graph.height" aria-hidden="true">
            <g v-for="(c, i) in geometry.chips" :key="i" class="chip" :class="{ more: c.title, dim: hovered !== null && !touches(c) }">
                <rect :x="c.x - c.w / 2" :y="c.y - 9" :width="c.w" height="18" rx="4" />
                <text :x="c.x" :y="c.y + 4" text-anchor="middle">{{ c.text }}</text>
                <title v-if="c.title">{{ c.title }}</title>
            </g>
        </svg>
    </div>
</template>
