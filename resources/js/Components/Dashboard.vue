<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import BarList, { type BarItem } from './charts/BarList.vue';
import LineChart, { type Series } from './charts/LineChart.vue';
import Sparkline from './charts/Sparkline.vue';
import ChartSkeleton from './skeletons/ChartSkeleton.vue';
import InfoTip from './InfoTip.vue';
import Skeleton from './Skeleton.vue';
import { apiBase, request } from '../lib/brokers';
import { durationTick, durationTicks } from '../lib/charts';
import { drainTime, isWorkQueue, littleWait, queueHealth, summarize, type QueueTrend } from '../lib/analysis';
import { clock, duration, number, percent, queueLabel, rate } from '../lib/format';
import { useI18n, type Key } from '../lib/i18n';
import { instanceName } from '../lib/names';
import type { MetricRange, Metrics, Selection, Topology } from '../types';

const props = defineProps<{ topology: Topology | null }>();
const emit = defineEmits<{ select: [selection: Selection] }>();
const { t } = useI18n();

const RANGES: MetricRange[] = ['1h', '6h', '24h', '3d'];
const STORAGE = 'warren.range';
/** Validated categorical slots (docs/DESIGN.md), handed out in a fixed order, never cycled. */
const SLOTS = 6;

function storedRange(): MetricRange {
    try {
        const value = localStorage.getItem(STORAGE);
        return RANGES.includes(value as MetricRange) ? (value as MetricRange) : '1h';
    } catch {
        return '1h';
    }
}

const range = ref<MetricRange>(storedRange());
const metrics = ref<Metrics | null>(null);
const failed = ref(false);
/** A new period is loading: the old charts stay, faded, instead of a skeleton flash. */
const refetching = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;
let busy = false;

async function load(): Promise<void> {
    const base = apiBase.value;
    if (base === null || busy || document.hidden) return;
    busy = true;
    const asked = range.value;
    try {
        const result = await request<Metrics>('GET', `${base}/metrics?range=${asked}`);
        if (base !== apiBase.value || asked !== range.value) return; // moved on while it loaded
        failed.value = !result.ok;
        if (result.ok) metrics.value = result.data;
        refetching.value = false;
    } catch {
        failed.value = true;
    } finally {
        busy = false;
    }
}

function pick(next: MetricRange): void {
    if (next === range.value) return;
    range.value = next;
    refetching.value = metrics.value !== null;
    try {
        localStorage.setItem(STORAGE, next);
    } catch {
        /* not remembered */
    }
    void load();
}

watch(apiBase, () => {
    metrics.value = null;
    void load();
});
onMounted(() => {
    void load();
    timer = setInterval(() => void load(), 30_000);
});
onUnmounted(() => clearInterval(timer));

// --- right now, from the live topology (work queues only)
const health = computed(() => queueHealth(props.topology?.queues ?? []));
const work = computed(() => (props.topology?.queues ?? []).filter(isWorkQueue));
const now = computed(() => {
    const sum = work.value.reduce(
        (acc, q) => {
            acc.ready += q.ready;
            acc.unacked += q.unacked;
            acc.consumers += q.consumers;
            acc.in += q.rateIn;
            acc.ack += q.rateAck;
            if (typeof q.consumerCapacity === 'number') {
                acc.capWeight += q.consumers;
                acc.capSum += q.consumerCapacity * q.consumers;
            }
            return acc;
        },
        { ready: 0, unacked: 0, consumers: 0, in: 0, ack: 0, capWeight: 0, capSum: 0 },
    );
    return {
        ...sum,
        backlog: sum.ready + sum.unacked,
        wait: littleWait(sum.ready + sum.unacked, sum.ack),
        drain: drainTime(sum.ready, sum.ack, sum.in),
        capacity: sum.capWeight > 0 ? sum.capSum / sum.capWeight : null,
    };
});

// --- over the period, from the collector's samples
const samples = computed(() => metrics.value?.samples ?? []);
const times = computed(() => samples.value.map((s) => s.t));
const hasHistory = computed(() => samples.value.length > 1);
const summary = computed(() => summarize(samples.value));
const loadingHistory = computed(() => metrics.value === null && !failed.value);
const column = (get: (s: Metrics['samples'][number]) => number): number[] => samples.value.map(get);

const signed = (n: number): string => (n === 0 ? number(0) : `${n > 0 ? '+' : '−'}${number(Math.abs(Math.round(n)))}`);
const at = (seconds: number): string => clock(seconds * 1000).slice(0, 5);

const stats = computed(() => {
    const s = summary.value;
    const history = hasHistory.value;
    return [
        {
            key: 'processed',
            label: t('dash.stat.processed'),
            help: t('dash.help.processed'),
            value: history ? number(Math.round(s.processed)) : '—',
            sub: history && s.peakThroughput
                ? t('dash.stat.processed.sub', { rate: rate(s.throughput), peak: rate(s.peakThroughput.value), time: at(s.peakThroughput.t) })
                : t('dash.stat.needs-history'),
            spark: column((x) => x.rateAck),
        },
        {
            key: 'wait',
            label: t('dash.stat.wait'),
            help: t('dash.help.wait'),
            value: !props.topology ? '—' : now.value.backlog === 0 ? t('dash.stat.wait.none') : duration(now.value.wait),
            sub: history && s.waitMedian !== null
                ? t('dash.stat.wait.sub', { median: duration(s.waitMedian), p95: duration(s.waitP95) })
                : t('dash.stat.needs-history'),
            spark: s.waits.map((w) => (Number.isFinite(w) ? w : 0)),
        },
        {
            key: 'backlog',
            label: t('dash.stat.backlog'),
            help: null,
            value: props.topology ? number(now.value.backlog) : '—',
            sub: [
                history ? t('dash.stat.backlog.change', { delta: signed(s.backlogChange) }) : null,
                now.value.ready === 0 ? null : now.value.drain === null ? t('dash.stat.backlog.growing') : t('dash.stat.backlog.drain', { time: duration(now.value.drain) }),
            ].filter(Boolean).join(' · ') || t('dash.stat.backlog.empty'),
            spark: column((x) => x.ready + x.unacked),
        },
        {
            key: 'capacity',
            label: t('dash.stat.capacity'),
            help: t('dash.help.capacity'),
            value: now.value.capacity === null ? '—' : percent(now.value.capacity),
            sub: t('dash.stat.capacity.sub', { consumers: number(now.value.consumers), queues: number(work.value.length) }),
            spark: column((x) => x.capacity ?? 0),
        },
        {
            key: 'redelivery',
            label: t('dash.stat.redelivery'),
            help: t('dash.help.redelivery'),
            value: s.redeliveryRatio === null ? '—' : percent(s.redeliveryRatio),
            sub: t('dash.stat.redelivery.sub'),
            spark: column((x) => x.rateRedeliver ?? 0),
        },
    ];
});

// Series wear the colour of the thing they measure, as in the flow diagram: what producers send, what workers finish.
const flow = computed<Series[]>(() => [
    { key: 'ack', name: t('dash.series.ack'), color: 'var(--c-worker)', values: column((s) => s.rateAck) },
    { key: 'in', name: t('dash.series.in'), color: 'var(--c-producer)', values: column((s) => s.rateIn) },
]);

const backlog = computed<Series[]>(() => [
    { key: 'unacked', name: t('dash.series.unacked'), color: 'var(--c-worker)', values: column((s) => s.unacked) },
    { key: 'ready', name: t('dash.series.ready'), color: 'var(--c-queue)', values: column((s) => s.ready) },
]);

/** A stalled moment has an infinite wait; it is drawn at the top of the chart instead. */
const waitSeries = computed<Series[]>(() => {
    const waits = summary.value.waits;
    const ceiling = Math.max(0, ...waits.filter((w) => Number.isFinite(w)));
    return [{ key: 'wait', name: t('dash.wait.title'), color: 'var(--series-1)', values: waits.map((w) => (Number.isFinite(w) ? w : ceiling)) }];
});

const hasCapacity = computed(() => samples.value.some((s) => typeof s.capacity === 'number'));
/** Readings without a capacity (older ones, or a moment the broker did not report it) take the nearest known value instead of a false 0. */
const capacitySeries = computed<Series[]>(() => {
    const raw = column((s) => (typeof s.capacity === 'number' ? s.capacity * 100 : NaN));
    const first = raw.find((v) => !Number.isNaN(v)) ?? 0;
    let last = first;
    const values = raw.map((v) => (Number.isNaN(v) ? last : (last = v)));
    return [{ key: 'capacity', name: t('dash.capacity.title'), color: 'var(--series-1)', values }];
});
const percentAxis = (n: number): string => `${Math.round(n)}%`;
const durationAxis = { ticks: durationTicks, format: durationTick };

/**
 * Waiting messages per catalog area. The colour belongs to the area (its place in the catalog), not to its rank,
 * so an area keeps its colour whatever the period; past the validated slots the rest fold into "Other".
 */
const areas = computed<Series[]>(() => {
    const catalogOrder = (metrics.value?.areas ?? []).map((a) => a.key);
    const names = new Map((metrics.value?.areas ?? []).map((a) => [a.key, a.name]));
    const seen = new Set(samples.value.flatMap((s) => Object.keys(s.areas)));
    const keys = [...catalogOrder.filter((k) => seen.has(k)), ...[...seen].filter((k) => !catalogOrder.includes(k)).sort()]
        .filter((key) => samples.value.some((s) => (s.areas[key] ?? 0) > 0));
    const shown = keys.slice(0, keys.length > SLOTS ? SLOTS - 1 : SLOTS);
    const rest = keys.slice(shown.length);
    const series: Series[] = shown.map((key, i) => ({
        key,
        name: names.get(key) ?? key,
        color: `var(--series-${i + 1})`,
        values: samples.value.map((s) => s.areas[key] ?? 0),
    }));
    if (rest.length) {
        series.push({ key: '__other', name: t('dash.areas.other'), color: 'var(--text-3)', values: samples.value.map((s) => rest.reduce((n, k) => n + (s.areas[k] ?? 0), 0)) });
    }
    return series;
});

const publishers = computed<BarItem[]>(() =>
    (metrics.value?.instances ?? []).map((i) => ({
        key: i.key,
        label: i.name ?? instanceName(i.key, t('no-instance')),
        value: i.count,
        title: i.key || t('no-instance'),
        // one hue for every bar: the instances are names, not an ordered scale, and the bar length already says how many
        color: 'var(--c-producer)',
    })),
);

const trendLabel = (trend: QueueTrend): string => t(`dash.trend.${trend}` as Key);
const rows = computed(() => health.value.slice(0, 12));
</script>

<template>
    <section class="dashboard" :class="{ refetching }" :aria-label="t('nav.dashboard')" :aria-busy="refetching || undefined">
        <header class="filter-row">
            <div class="segmented" role="group" :aria-label="t('dash.range')">
                <button
                    v-for="r in RANGES"
                    :key="r"
                    type="button"
                    :class="{ active: r === range }"
                    :aria-pressed="r === range"
                    @click="pick(r)"
                >{{ t(`dash.range.${r}`) }}</button>
            </div>
            <InfoTip :label="t('dash.help.how')" :text="t('dash.method')" />
        </header>

        <p v-if="failed" class="banner warn" role="alert">{{ t('dash.error') }}</p>

        <section class="stat-strip" :aria-busy="!topology">
            <div v-for="s in stats" :key="s.key" class="stat" :class="'stat-' + s.key">
                <span class="stat-label">{{ s.label }}<InfoTip v-if="s.help" :text="s.help" /></span>
                <template v-if="topology || hasHistory">
                    <b class="stat-value">{{ s.value }}</b>
                    <span class="stat-sub">{{ s.sub }}</span>
                    <Sparkline v-if="s.spark.length > 1" :values="s.spark" :width="120" :height="26" />
                </template>
                <Skeleton v-else :lines="2" />
            </div>
        </section>

        <article class="block focal">
            <header class="block-head">
                <div>
                    <h3>{{ t('dash.flow.title') }}</h3>
                    <p class="muted">{{ t('dash.flow.sub') }}</p>
                </div>
            </header>
            <ChartSkeleton v-if="loadingHistory" :height="280" />
            <template v-else-if="hasHistory">
                <LineChart :series="flow" :times="times" :height="280" :format="rate" :label="t('dash.flow.title')" />
                <p class="block-foot">
                    {{ t('dash.flow.balance', { received: number(Math.round(summary.received)), processed: number(Math.round(summary.processed)) }) }}
                    <template v-if="summary.growingShare > 0"> {{ t('dash.flow.growing', { share: percent(summary.growingShare) }) }}</template>
                </p>
            </template>
            <p v-else class="empty-chart muted">{{ t('dash.no-history') }}</p>
        </article>

        <div class="grid-3">
            <article class="block">
                <header class="block-head"><div><h3>{{ t('dash.backlog.title') }}</h3><p class="muted">{{ t('dash.chart.backlog.sub') }}</p></div></header>
                <ChartSkeleton v-if="loadingHistory" :height="190" />
                <LineChart v-else-if="hasHistory" :series="backlog" :times="times" :height="190" stacked :format="number" :label="t('dash.backlog.title')" />
                <p v-else class="empty-chart muted">{{ t('dash.no-history') }}</p>
            </article>
            <article class="block">
                <header class="block-head"><div><h3>{{ t('dash.wait.title') }}</h3><p class="muted">{{ t('dash.wait.sub') }}</p></div></header>
                <ChartSkeleton v-if="loadingHistory" :height="190" />
                <LineChart v-else-if="hasHistory" :series="waitSeries" :times="times" :height="190" :format="duration" :axis="durationAxis" :label="t('dash.wait.title')" />
                <p v-else class="empty-chart muted">{{ t('dash.no-history') }}</p>
            </article>
            <article class="block">
                <header class="block-head"><div><h3>{{ t('dash.capacity.title') }}</h3><p class="muted">{{ t('dash.capacity.sub') }}</p></div></header>
                <ChartSkeleton v-if="loadingHistory" :height="190" />
                <LineChart v-else-if="hasCapacity" :series="capacitySeries" :times="times" :height="190" :format="percentAxis" :label="t('dash.capacity.title')" />
                <p v-else class="empty-chart muted">{{ hasHistory ? t('dash.capacity.none') : t('dash.no-history') }}</p>
            </article>
        </div>

        <article class="block">
            <header class="block-head"><div><h3>{{ t('dash.queues.title') }}</h3><p class="muted">{{ t('dash.queues.sub') }}</p></div></header>
            <Skeleton v-if="!topology" :lines="5" />
            <p v-else-if="rows.length === 0" class="muted">{{ t('dash.queues.empty') }}</p>
            <div v-else class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ t('dash.col.queue') }}</th>
                            <th>{{ t('dash.col.state') }}</th>
                            <th class="num">{{ t('dash.col.waiting') }}</th>
                            <th class="num">{{ t('dash.col.processing') }}</th>
                            <th class="num">{{ t('dash.col.in') }}</th>
                            <th class="num">{{ t('dash.col.out') }}</th>
                            <th class="num">{{ t('dash.col.wait') }}</th>
                            <th class="num">{{ t('dash.col.drain') }}</th>
                            <th class="num">{{ t('dash.col.capacity') }}</th>
                            <th class="num">{{ t('dash.col.redelivery') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.queue.name" class="clickable" @click="emit('select', { kind: 'queue', id: r.queue.name })">
                            <td :title="r.queue.name"><a @click.stop="emit('select', { kind: 'queue', id: r.queue.name })">{{ queueLabel(r.queue) }}</a></td>
                            <td><span class="trend" :class="'trend-' + r.trend">{{ trendLabel(r.trend) }}</span></td>
                            <td class="num">{{ number(r.queue.ready) }}</td>
                            <td class="num">{{ number(r.queue.unacked) }}</td>
                            <td class="num">{{ rate(r.queue.rateIn) }}</td>
                            <td class="num">{{ rate(r.queue.rateAck) }}</td>
                            <td class="num" :class="{ bad: !Number.isFinite(r.wait) }">{{ duration(r.wait) }}</td>
                            <td class="num">{{ r.queue.ready === 0 ? '—' : r.drain === null ? t('dash.stat.backlog.growing') : duration(r.drain) }}</td>
                            <td class="num">{{ r.capacity === null ? '—' : percent(r.capacity) }}</td>
                            <td class="num" :class="{ warn: (r.redeliveryRatio ?? 0) >= 0.1 }">{{ r.redeliveryRatio === null ? '—' : percent(r.redeliveryRatio) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>

        <div class="grid-2">
            <article class="block">
                <header class="block-head"><div><h3>{{ t('dash.chart.instances') }}</h3><p class="muted">{{ t(`dash.range.${range}`) }}</p></div></header>
                <ChartSkeleton v-if="loadingHistory" :height="200" />
                <BarList v-else-if="publishers.length" :items="publishers" :format="number" clickable @select="(key) => emit('select', { kind: 'producer', id: key })" />
                <p v-else class="empty-chart muted">{{ t('dash.no-tap') }}</p>
            </article>
            <article class="block">
                <header class="block-head">
                    <div><h3>{{ t('dash.chart.areas') }}</h3><p class="muted">{{ t('dash.chart.areas.sub') }}</p></div>
                </header>
                <ChartSkeleton v-if="loadingHistory" :height="200" />
                <LineChart v-else-if="areas.length" :series="areas" :times="times" :height="200" stacked :format="number" :label="t('dash.chart.areas')" />
                <p v-else class="empty-chart muted">{{ hasHistory ? t('dash.no-areas') : t('dash.no-history') }}</p>
            </article>
        </div>
    </section>
</template>
