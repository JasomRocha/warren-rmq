<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { apiBase, request } from '../lib/brokers';
import { dateTime, duration, number, percent, relativeTime } from '../lib/format';
import { useI18n, type Key } from '../lib/i18n';
import type { PanelData, PanelItem, PanelMeta, PanelStatus } from '../types';

const props = defineProps<{ panel: PanelMeta; active: boolean; now: number }>();
const emit = defineEmits<{ select: [messageId: number] }>();
const { t } = useI18n();

const data = ref<PanelData | null>(null);
const failed = ref(false);
const text = ref('');
const status = ref<PanelStatus | ''>('');
const instance = ref('');

let timer: ReturnType<typeof setInterval> | undefined;
let busy = false;

async function load(): Promise<void> {
    if (busy || !props.active || document.hidden) return;
    busy = true;
    try {
        if (apiBase.value === null) return;
        const result = await request<PanelData>('GET', `${apiBase.value}/paineis/${encodeURIComponent(props.panel.id)}`);
        if (result.status === 401) return; // request() already sent the user to the login page
        if (!result.ok) throw new Error(String(result.status));
        data.value = result.data;
        failed.value = false;
    } catch {
        failed.value = true;
    } finally {
        busy = false;
    }
}

onMounted(() => {
    void load();
    timer = setInterval(() => void load(), 3000);
});
onUnmounted(() => clearInterval(timer));
watch(() => props.active, (isActive) => isActive && void load());
watch(apiBase, () => {
    data.value = null;
    void load();
});

const STATUSES: PanelStatus[] = ['sucesso', 'erro', 'aviso', 'info'];
const items = computed(() => data.value?.items ?? []);
const instances = computed(() => [...new Set(items.value.map((i) => i.instance).filter((i): i is string => !!i))].sort());
const present = computed(() => STATUSES.filter((s) => (data.value?.counts[s] ?? 0) > 0));

const visible = computed<PanelItem[]>(() => {
    const term = text.value.trim().toLowerCase();
    return items.value.filter((i) => {
        if (status.value && i.status !== status.value) return false;
        if (instance.value && i.instance !== instance.value) return false;
        return !term || [i.title, i.text, i.instance ?? '', ...i.facts.map((f) => f.value)].some((v) => v.toLowerCase().includes(term));
    });
});

const queueReady = computed(() => (data.value?.queues ?? []).reduce((n, q) => n + q.ready, 0));
const ttl = computed(() => data.value?.queues.find((q) => q.ttlMs)?.ttlMs ?? null);
const statusText = (s: PanelStatus): string => t(`panel.status.${s}` as Key);
const breakdownLabel = computed(() => present.value.map((s) => `${statusText(s)}: ${number(data.value?.counts[s] ?? 0)}`).join(', '));
const icon = (s: PanelStatus): string => ({ sucesso: '✓', erro: '✕', aviso: '!', info: 'i' })[s];

/** Explains why the list may be empty or incomplete, in the order of what matters most. */
const notice = computed<string | null>(() => {
    const d = data.value;
    if (!d) return null;
    if (!d.tap.active) return t('panel.notice.no-tap');
    if (d.total === 0 && queueReady.value > 0) {
        return `${t('panel.notice.backlog', { n: number(queueReady.value) })}${props.panel.kind === 'erros' && !d.tap.tracing ? ` ${t('panel.notice.trace')}` : ''}`;
    }
    if (props.panel.kind === 'erros' && !d.tap.tracing) return t('panel.notice.trace');
    return null;
});
</script>

<template>
    <div class="panel">
        <header class="panel-head">
            <h2>{{ panel.title }}</h2>
            <p v-if="panel.description" class="muted">{{ panel.description }}</p>
        </header>

        <p v-if="failed && !data" class="muted">{{ t('error') }}</p>
        <p v-else-if="!data" class="muted">{{ t('panel.loading') }}</p>

        <template v-else>
            <section class="panel-summary">
                <div v-for="q in data.queues" :key="q.name" class="ps-block" :title="q.name">
                    <span class="ps-label">{{ t('panel.in-queue') }}</span>
                    <b class="ps-value">{{ number(q.ready) }}</b>
                    <span class="ps-name">{{ q.label }}</span>
                    <span class="ps-meta">
                        {{ q.type }} · {{ t('panel.no-consumer') }}<template v-if="q.ttlMs"> · {{ t('panel.kept', { time: duration(q.ttlMs / 1000) }) }}</template>
                    </span>
                </div>
                <div class="ps-block ps-captured">
                    <span class="ps-label">{{ t('panel.stat.total') }}</span>
                    <b class="ps-value">{{ number(data.total) }}</b>
                    <div v-if="data.total > 0" class="ps-bar" role="img" :aria-label="breakdownLabel">
                        <i v-for="s in present" :key="s" :class="'st-' + s" :style="{ width: ((data.counts[s] ?? 0) / data.total) * 100 + '%' }"></i>
                    </div>
                    <ul class="ps-breakdown">
                        <li v-for="s in present" :key="s" :class="'st-' + s">
                            <span class="ps-swatch"></span>{{ statusText(s) }}
                            <b>{{ number(data.counts[s] ?? 0) }}</b>
                            <span class="muted">{{ percent((data.counts[s] ?? 0) / data.total) }}</span>
                        </li>
                    </ul>
                </div>
            </section>

            <p v-if="notice" class="notice-inline">{{ notice }}</p>
            <p class="muted source-note">{{ t('panel.notice.source') }}</p>

            <div class="toolbar">
                <input v-model="text" type="search" :placeholder="t('panel.search')" :aria-label="t('panel.search')" />
                <select v-model="instance" :aria-label="t('panel.all-instances')">
                    <option value="">{{ t('panel.all-instances') }}</option>
                    <option v-for="i in instances" :key="i" :value="i">{{ i }}</option>
                </select>
                <button type="button" :class="{ active: status === '' }" @click="status = ''">{{ t('panel.all') }}</button>
                <button v-for="s in present" :key="s" type="button" :class="{ active: status === s }" @click="status = status === s ? '' : s">{{ statusText(s) }}</button>
                <span class="muted push">{{ t('panel.showing', { shown: number(visible.length), total: number(data.total) }) }}</span>
            </div>

            <p v-if="visible.length === 0" class="muted empty">{{ t('panel.empty') }}</p>

            <ul class="events">
                <li v-for="item in visible" :key="item.id" :class="'st-' + item.status">
                    <span class="badge-icon" :title="statusText(item.status)">{{ icon(item.status) }}</span>
                    <div class="event-body">
                        <div class="event-title">
                            <b>{{ item.title }}</b>
                            <span class="chip-status">{{ statusText(item.status) }}</span>
                            <span v-if="item.instance" class="chip-instance">{{ item.instance }}</span>
                        </div>
                        <p class="event-text">{{ item.text }}</p>
                        <p class="event-time">
                            {{ t('panel.queued-at') }} <time :datetime="new Date(item.queuedAt).toISOString()">{{ dateTime(item.queuedAt) }}</time>
                            <span class="muted"> · {{ relativeTime(item.queuedAt, now) }}</span>
                        </p>
                        <dl v-if="item.facts.length" class="facts">
                            <template v-for="f in item.facts" :key="f.label"><dt>{{ f.label }}</dt><dd>{{ f.value }}</dd></template>
                        </dl>
                        <button type="button" class="link-btn" @click="emit('select', item.id)">{{ t('panel.details') }}</button>
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>
