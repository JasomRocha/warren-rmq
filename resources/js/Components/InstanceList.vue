<script setup lang="ts">
import { computed, ref } from 'vue';
import { byBusiness } from '../lib/estimate';
import { instanceColor, number } from '../lib/format';
import { instanceName, withKey } from '../lib/names';
import { useI18n } from '../lib/i18n';
import type { InstanceStat } from '../types';

const props = defineProps<{ stats: InstanceStat[]; selected: ReadonlySet<string> }>();
const emit = defineEmits<{ toggle: [key: string, on: boolean]; clear: []; top: []; details: [key: string] }>();
const { t } = useI18n();

const search = ref('');
const hovering = ref(false);
let frozen: string[] = [];

const label = (key: string): string => instanceName(key, t('no-instance'));

const rows = computed(() => {
    const known = new Set(props.stats.map((s) => s.key));
    const ghosts: InstanceStat[] = [...props.selected]
        .filter((k) => !known.has(k))
        .map((key) => ({ key, total: 0, last: 0, recent: 0, processing: 0, waiting: 0, queues: new Set(), exchanges: new Map() }));
    const term = search.value.trim().toLowerCase();
    const list = [...props.stats, ...ghosts].filter((s) => !term || label(s.key).toLowerCase().includes(term) || s.key.toLowerCase().includes(term));

    // While the pointer is over the list the order is frozen, so a row does not jump away from a click.
    if (hovering.value && frozen.length) {
        const position = new Map(frozen.map((k, i) => [k, i]));
        list.sort((a, b) => (position.get(a.key) ?? 1e9) - (position.get(b.key) ?? 1e9));
    } else {
        list.sort(byBusiness);
        frozen = list.map((s) => s.key);
    }
    return list;
});

const busiest = computed(() => rows.value.find((s) => s.processing + s.waiting > 0)?.key);
const max = computed(() => Math.max(1, ...rows.value.map((s) => s.processing + s.waiting)));
</script>

<template>
    <div class="instances">
        <p class="muted help">
            {{ t('instances.seen', { n: stats.length }) }}
            {{ selected.size ? t('instances.help.some', { n: selected.size }) : t('instances.help.all') }}
        </p>
        <input v-model="search" type="search" :placeholder="t('instances.search')" :aria-label="t('instances.search')" />
        <div class="row-actions">
            <button type="button" @click="emit('top')">{{ t('instances.top') }}</button>
            <button type="button" @click="emit('clear')">{{ t('instances.clear') }}</button>
        </div>
        <ul @pointerenter="hovering = true" @pointerleave="hovering = false">
            <li v-for="s in rows" :key="s.key" :class="{ marked: selected.has(s.key) }">
                <label :title="withKey(label(s.key), s.key)">
                    <input type="checkbox" :checked="selected.has(s.key)" @change="emit('toggle', s.key, ($event.target as HTMLInputElement).checked)" />
                    <span class="dot" :style="{ background: instanceColor(s.key) }"></span>
                    <span class="name">{{ label(s.key) }}</span>
                    <span v-if="s.key === busiest" class="badge">{{ t('busiest') }}</span>
                </label>
                <button type="button" class="info" :title="t('tab.details')" :aria-label="t('tab.details')" @click="emit('details', s.key)">i</button>
                <div class="numbers" :title="t('instances.estimate')">
                    <span class="proc"><b>{{ number(s.processing) }}</b> {{ t('processing') }}</span> ·
                    <b>{{ number(s.waiting) }}</b> {{ t('waiting') }} · <b>{{ number(s.recent) }}</b>{{ t('per-min') }}
                </div>
                <div class="meter">
                    <i class="m-proc" :style="{ width: (s.processing / max) * 100 + '%' }"></i>
                    <i class="m-wait" :style="{ width: (s.waiting / max) * 100 + '%' }"></i>
                </div>
            </li>
        </ul>
    </div>
</template>
