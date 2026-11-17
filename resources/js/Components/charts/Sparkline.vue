<script setup lang="ts">
import { computed } from 'vue';
import { linePath } from '../../lib/charts';

/** The trend in the de-emphasis grey, with the current reading marked in ink: it supports the figure beside it, it is not a series. */
const props = withDefaults(defineProps<{ values: number[]; color?: string; width?: number; height?: number }>(), { color: 'var(--text-3)', width: 96, height: 28 });

const path = computed(() => {
    const v = props.values;
    if (v.length < 2) return '';
    const max = Math.max(...v);
    const min = Math.min(...v);
    const span = max - min || 1;
    const xs = v.map((_, i) => 2 + (i * (props.width - 4)) / (v.length - 1));
    const ys = v.map((n) => props.height - 3 - ((n - min) / span) * (props.height - 6));
    return linePath(xs, ys);
});

const end = computed(() => {
    const v = props.values;
    if (v.length < 2) return null;
    const max = Math.max(...v);
    const min = Math.min(...v);
    const span = max - min || 1;
    return { x: props.width - 2, y: props.height - 3 - ((v[v.length - 1] - min) / span) * (props.height - 6) };
});
</script>

<template>
    <svg class="sparkline" :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" aria-hidden="true">
        <path v-if="path" :d="path" fill="none" :stroke="color" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        <circle v-if="end" :cx="end.x" :cy="end.y" r="2.5" fill="var(--text)" />
        <line v-if="!path" x1="2" :x2="width - 2" :y1="height / 2" :y2="height / 2" stroke="var(--border)" />
    </svg>
</template>
