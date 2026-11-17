<script setup lang="ts">
import { computed } from 'vue';
import { useI18n, type Key } from '../lib/i18n';
import { readPriority, strictQuorumPriorities } from '../lib/priority';
import type { Queue } from '../types';

const props = defineProps<{ priority: number | null; queue?: Pick<Queue, 'type' | 'arguments'> | null; brokerVersion?: string | null }>();
const { t } = useI18n();

const BARS: Record<string, number> = { 'very-low': 1, low: 2, normal: 3, high: 4, 'very-high': 5, ignored: 0 };

const classic = computed(() => !!props.queue && props.queue.type !== 'quorum');
const reading = computed(() => readPriority(props.priority, props.queue, props.brokerVersion));
const twoLevels = computed(() => !classic.value && !strictQuorumPriorities(props.brokerVersion));
const max = computed(() => Number((props.queue?.arguments as Record<string, unknown> | undefined)?.['x-max-priority'] ?? 0));

const title = computed(() => {
    const r = reading.value;
    if (r.level === 'ignored') return t('priority.title.ignored');
    if (r.implicit) return t(classic.value ? 'priority.title.implicit-classic' : 'priority.title.implicit-quorum');
    if (twoLevels.value) return t('priority.title.two-levels', { n: r.effective });
    return classic.value ? t('priority.title.classic', { n: r.effective, max: max.value }) : t('priority.title.quorum', { n: r.effective });
});
</script>

<template>
    <span class="prio" :class="['prio-' + reading.level, { implicit: reading.implicit }]" :title="title">
        <i class="prio-bars" aria-hidden="true"><b v-for="i in 5" :key="i" :class="{ on: i <= BARS[reading.level] }"></b></i>
        {{ t(`priority.${reading.level}` as Key) }}
    </span>
</template>
