<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { compact, labelIndexes, niceTicks, timeLabel } from '../../lib/charts';
import { useI18n } from '../../lib/i18n';

const props = withDefaults(defineProps<{ points: { t: number; count: number }[]; height?: number; color?: string; label: string; unit: string }>(), { height: 200, color: 'var(--c-producer)' });
const { locale } = useI18n();

const MARGIN = { top: 10, right: 8, bottom: 24, left: 40 };
const box = ref<HTMLElement | null>(null);
const width = ref(480);
let observer: ResizeObserver | undefined;
onMounted(() => {
    if (!box.value) return;
    width.value = box.value.clientWidth || 480;
    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(() => box.value && (width.value = box.value.clientWidth || width.value));
        observer.observe(box.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());

const inner = computed(() => ({ w: Math.max(80, width.value - MARGIN.left - MARGIN.right), h: props.height - MARGIN.top - MARGIN.bottom }));
const ticks = computed(() => niceTicks(Math.max(0, ...props.points.map((p) => p.count))));
const top = computed(() => ticks.value[ticks.value.length - 1] || 1);
const slot = computed(() => (props.points.length ? inner.value.w / props.points.length : 0));
const span = computed(() => (props.points.length > 1 ? props.points[props.points.length - 1].t - props.points[0].t : 0));
const y = (v: number): number => MARGIN.top + inner.value.h - (v / top.value) * inner.value.h;
const labels = computed(() => labelIndexes(props.points.length, 5).map((i) => ({ x: MARGIN.left + slot.value * i + slot.value / 2, text: timeLabel(props.points[i].t, locale, span.value) })));
const total = computed(() => props.points.reduce((n, p) => n + p.count, 0));
</script>

<template>
    <div ref="box" class="column-chart" :style="{ height: height + 'px' }">
        <svg :width="width" :height="height" role="img" :aria-label="`${label}: ${total} ${unit}`">
            <g class="chart-grid">
                <g v-for="tick in ticks" :key="tick">
                    <line :x1="MARGIN.left" :x2="MARGIN.left + inner.w" :y1="y(tick)" :y2="y(tick)" />
                    <text :x="MARGIN.left - 8" :y="y(tick) + 4" text-anchor="end">{{ compact(tick, locale) }}</text>
                </g>
                <text v-for="l in labels" :key="l.x" :x="l.x" :y="height - 6" text-anchor="middle">{{ l.text }}</text>
            </g>
            <rect
                v-for="(p, i) in points"
                :key="p.t"
                :x="MARGIN.left + slot * i + Math.min(2, slot * 0.2)"
                :y="y(p.count)"
                :width="Math.max(1, slot - Math.min(4, slot * 0.4))"
                :height="Math.max(p.count > 0 ? 1.5 : 0, MARGIN.top + inner.h - y(p.count))"
                :fill="color"
                rx="1.5"
            >
                <title>{{ timeLabel(p.t, locale, span) }}: {{ p.count }} {{ unit }}</title>
            </rect>
        </svg>
    </div>
</template>
