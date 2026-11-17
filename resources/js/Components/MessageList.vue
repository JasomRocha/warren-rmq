<script setup lang="ts">
import { computed, ref } from 'vue';
import { situation } from '../lib/estimate';
import { clock, instanceColor, number } from '../lib/format';
import { instanceName, withKey } from '../lib/names';
import { useI18n } from '../lib/i18n';
import PriorityTag from './PriorityTag.vue';
import type { MessageSummary, Queue, QueueEstimate } from '../types';

const props = defineProps<{ messages: MessageSummary[]; estimates: Map<string, QueueEstimate>; selectedId: number | null; focused: boolean; focusCount: number; queue: string; queues?: Queue[]; brokerVersion?: string | null }>();

/** The queue a priority is read against: the first known one the message went to. */
const queueOf = (m: MessageSummary): Queue | undefined => m.queues.map((name) => props.queues?.find((q) => q.name === name)).find(Boolean);
const emit = defineEmits<{ select: [id: number]; 'update:queue': [value: string] }>();
const { t } = useI18n();

const MAX_ROWS = 300;
const text = ref('');
const onlyQueued = ref(false);
const newestFirst = ref(true);

const queueOptions = computed(() => [...new Set(props.messages.flatMap((m) => m.queues))].sort());

const rows = computed(() => {
    const term = text.value.trim().toLowerCase();
    const list = props.messages
        .map((m) => ({ m, s: situation(m, props.estimates) }))
        .filter(({ m, s }) => {
            if (props.queue && !m.queues.includes(props.queue)) return false;
            if (onlyQueued.value && s.kind !== 'waiting' && s.kind !== 'processing') return false;
            return !term || [m.routingKey, m.job, m.uuid, m.exchange, m.instance, m.instanceName].some((v) => (v ?? '').toLowerCase().includes(term));
        });
    return newestFirst.value ? list.reverse() : list;
});
const shown = computed(() => rows.value.slice(0, MAX_ROWS));

const statusText = (s: ReturnType<typeof situation>): string =>
    s.kind === 'delivered' ? t('status.delivered', { time: clock(s.at) }) : t(`status.${s.kind}` as 'status.left');

const clear = (): void => {
    text.value = '';
    onlyQueued.value = false;
    emit('update:queue', '');
};
</script>

<template>
    <div class="messages">
        <div class="toolbar">
            <select :value="queue" :aria-label="t('filter.queue')" @change="emit('update:queue', ($event.target as HTMLSelectElement).value)">
                <option value="">{{ t('filter.queue') }}</option>
                <option v-for="q in queueOptions" :key="q" :value="q">{{ q }}</option>
            </select>
            <input v-model="text" type="search" :placeholder="t('filter.text')" :aria-label="t('filter.text')" />
            <label><input v-model="onlyQueued" type="checkbox" /> {{ t('filter.only-queued') }}</label>
            <select :value="newestFirst ? 'new' : 'old'" @change="newestFirst = ($event.target as HTMLSelectElement).value === 'new'">
                <option value="new">{{ t('order.recent') }}</option>
                <option value="old">{{ t('order.old') }}</option>
            </select>
            <button type="button" @click="clear">{{ t('clear-filters') }}</button>
            <span class="muted push">
                {{ rows.length > shown.length ? t('count.some', { shown: number(shown.length), total: number(rows.length) }) : t('count.all', { total: number(rows.length) }) }}
                <template v-if="focused"> · {{ t('instances.help.some', { n: focusCount }) }}</template>
            </span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>{{ t('col.time') }}</th><th>{{ t('col.instance') }}</th><th>{{ t('col.exchange') }}</th><th>{{ t('col.key') }}</th>
                        <th>{{ t('col.job') }}</th><th>{{ t('col.prio') }}</th><th>{{ t('col.queue') }}</th><th>{{ t('col.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="{ m, s } in shown" :key="m.id" :class="{ open: selectedId === m.id }" @click="emit('select', m.id)">
                        <td class="mono">{{ clock(m.ts) }}</td>
                        <td :title="withKey(instanceName(m.instance, t('no-instance')), m.instance ?? '')"><span class="dot" :style="{ background: instanceColor(m.instance ?? '') }"></span>{{ instanceName(m.instance, t('no-instance')) }}</td>
                        <td>{{ m.exchange || t('default-exchange') }}</td>
                        <td class="mono wrap">{{ m.routingKey }}</td>
                        <td>{{ m.job }}</td>
                        <td><PriorityTag :priority="m.priority" :queue="queueOf(m)" :broker-version="brokerVersion" /></td>
                        <td class="mono wrap">{{ m.queues.join(', ') }}</td>
                        <td :class="'st-' + s.kind">{{ statusText(s) }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="shown.length === 0" class="muted empty">{{ messages.length === 0 ? t('empty.messages') : t('empty.filter') }}</p>
        </div>
    </div>
</template>
