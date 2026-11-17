<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { areaPath, compact, labelIndexes, linePath, nearestIndex, niceTicks, stack, timeLabel } from '../../lib/charts';
import { useI18n } from '../../lib/i18n';

export interface Series {
    key: string;
    name: string;
    color: string;
    values: number[];
}

const props = withDefaults(
    defineProps<{
        series: Series[];
        times: number[];
        height?: number;
        stacked?: boolean;
        area?: boolean;
        format?: (n: number) => string;
        /** The y axis in its own units, when the tooltip's long format would not fit beside the plot. */
        axis?: { ticks: (max: number) => number[]; format: (n: number) => string };
        label: string;
    }>(),
    { height: 220, stacked: false, area: false },
);
const { locale, t } = useI18n();

const MARGIN = { top: 10, right: 12, bottom: 24 };
/** 11px IBM Plex Mono: every glyph is 0.6em wide. */
const TICK_CHAR = 6.6;
const box = ref<HTMLElement | null>(null);
const width = ref(640);
let observer: ResizeObserver | undefined;

onMounted(() => {
    if (!box.value) return;
    width.value = box.value.clientWidth || 640;
    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(() => {
            if (box.value) width.value = box.value.clientWidth || width.value;
        });
        observer.observe(box.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());

const fmt = (n: number): string => (props.format ? props.format(n) : compact(n, locale));
const tickText = (n: number): string => (props.axis ? props.axis.format(n) : fmt(n));
/** Room for the widest tick label, so a long one never runs off the left edge. */
const left = computed(() => Math.max(28, Math.ceil(Math.max(...ticks.value.map((n) => tickText(n).length)) * TICK_CHAR) + 12));
const inner = computed(() => ({ w: Math.max(80, width.value - left.value - MARGIN.right), h: props.height - MARGIN.top - MARGIN.bottom }));

/** The line each series is drawn at: its own values, or the running total when stacked. */
const tops = computed(() => (props.stacked ? stack(props.series.map((s) => s.values)) : props.series.map((s) => s.values)));
const max = computed(() => Math.max(0, ...tops.value.flat()));
const ticks = computed(() => (props.axis ? props.axis.ticks(max.value) : niceTicks(max.value)));
const top = computed(() => ticks.value[ticks.value.length - 1] || 1);

const t0 = computed(() => props.times[0] ?? 0);
const t1 = computed(() => props.times[props.times.length - 1] ?? 1);
const x = (t: number): number => left.value + (t1.value === t0.value ? inner.value.w / 2 : ((t - t0.value) / (t1.value - t0.value)) * inner.value.w);
const y = (v: number): number => MARGIN.top + inner.value.h - (v / top.value) * inner.value.h;

const xs = computed(() => props.times.map(x));
const paths = computed(() =>
    props.series.map((s, i) => {
        const ys = tops.value[i].map(y);
        const floor = props.stacked && i > 0 ? tops.value[i - 1].map(y) : y(0);
        return { key: s.key, color: s.color, line: linePath(xs.value, ys), fill: props.area || props.stacked ? areaPath(xs.value, ys, floor) : '' };
    }),
);
const xLabels = computed(() => labelIndexes(props.times.length, 5).map((i) => ({ x: xs.value[i], text: timeLabel(props.times[i], locale, t1.value - t0.value) })));

const cursor = ref<number | null>(null);
function move(event: PointerEvent): void {
    const rect = (event.currentTarget as Element).getBoundingClientRect();
    const px = event.clientX - rect.left;
    const index = nearestIndex(xs.value, px);
    cursor.value = index >= 0 ? index : null;
}
function key(event: KeyboardEvent): void {
    if (props.times.length === 0) return;
    if (event.key === 'Escape') cursor.value = null;
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        const step = event.key === 'ArrowLeft' ? -1 : 1;
        cursor.value = Math.min(props.times.length - 1, Math.max(0, (cursor.value ?? props.times.length - 1) + step));
        event.preventDefault();
    }
}

const tip = computed(() => {
    const i = cursor.value;
    if (i === null || i >= props.times.length) return null;
    const left = xs.value[i];
    return {
        i,
        left,
        flip: left > width.value * 0.62,
        time: timeLabel(props.times[i], locale, t1.value - t0.value),
        rows: props.series.map((s) => ({ key: s.key, name: s.name, color: s.color, value: fmt(s.values[i] ?? 0), y: y(tops.value[props.series.indexOf(s)][i] ?? 0) })),
    };
});

const summary = computed(() => `${props.label}: ${props.series.map((s) => `${s.name} ${fmt(s.values[s.values.length - 1] ?? 0)}`).join(', ')}`);
</script>

<template>
    <div>
    <ul v-if="series.length > 1" class="chart-legend" :class="{ areas: stacked || area }">
        <li v-for="s in series" :key="s.key"><i :style="{ background: s.color }"></i>{{ s.name }}</li>
    </ul>
    <div ref="box" class="line-chart" :style="{ height: height + 'px' }">
        <svg
            :width="width"
            :height="height"
            role="img"
            :aria-label="summary"
            tabindex="0"
            @pointermove="move"
            @pointerleave="cursor = null"
            @keydown="key"
            @blur="cursor = null"
        >
            <g class="chart-grid">
                <g v-for="tick in ticks" :key="tick">
                    <line :x1="left" :x2="left + inner.w" :y1="y(tick)" :y2="y(tick)" />
                    <text :x="left - 8" :y="y(tick) + 4" text-anchor="end">{{ tickText(tick) }}</text>
                </g>
                <text v-for="l in xLabels" :key="l.x" :x="l.x" :y="height - 6" text-anchor="middle">{{ l.text }}</text>
            </g>
            <g v-for="p in [...paths].reverse()" :key="p.key">
                <path v-if="p.fill" :d="p.fill" :fill="p.color" fill-opacity="0.1" />
                <path :d="p.line" fill="none" :stroke="p.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
            </g>
            <g v-if="tip">
                <line :x1="tip.left" :x2="tip.left" :y1="MARGIN.top" :y2="MARGIN.top + inner.h" class="chart-cursor" />
                <circle v-for="r in tip.rows" :key="r.key" :cx="tip.left" :cy="r.y" r="4" :fill="r.color" stroke="var(--surface)" stroke-width="2" />
            </g>
        </svg>
        <div v-if="tip" class="chart-tip" :class="{ flip: tip.flip }" :style="{ left: tip.left + 'px' }" role="status">
            <b>{{ tip.time }}</b>
            <div v-for="r in tip.rows" :key="r.key"><i :style="{ background: r.color }"></i>{{ r.name }}<span>{{ r.value }}</span></div>
        </div>
    </div>
    <details class="chart-table">
        <summary>{{ t('chart.table') }}</summary>
        <div class="chart-table-scroll">
            <table>
                <thead>
                    <tr><th>{{ t('chart.time') }}</th><th v-for="s in series" :key="s.key" class="num">{{ s.name }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(time, i) in [...times].reverse()" :key="time">
                        <td>{{ timeLabel(time, locale, t1 - t0) }}</td>
                        <td v-for="s in series" :key="s.key" class="num">{{ fmt(s.values[times.length - 1 - i] ?? 0) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </details>
    </div>
</template>
