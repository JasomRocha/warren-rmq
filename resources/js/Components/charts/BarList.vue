<script setup lang="ts">
import { computed } from 'vue';

export interface BarItem {
    key: string;
    label: string;
    value: number;
    /** Printed after the value ("3 filas", "12%"). */
    note?: string;
    color?: string;
    title?: string;
}

const props = defineProps<{ items: BarItem[]; format: (n: number) => string; clickable?: boolean }>();
const emit = defineEmits<{ select: [key: string] }>();
const max = computed(() => Math.max(1, ...props.items.map((i) => i.value)));
</script>

<template>
    <ul class="bar-list">
        <li v-for="item in items" :key="item.key">
            <component :is="clickable ? 'button' : 'div'" class="bar-row" type="button" :title="item.title ?? item.label" @click="clickable && emit('select', item.key)">
                <span class="bar-label">{{ item.label }}</span>
                <span class="bar-track"><i :style="{ width: Math.max(2, (item.value / max) * 100) + '%', background: item.color ?? 'var(--accent)' }"></i></span>
                <span class="bar-value">{{ format(item.value) }}<small v-if="item.note">{{ item.note }}</small></span>
            </component>
        </li>
    </ul>
</template>
