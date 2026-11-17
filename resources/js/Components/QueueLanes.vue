<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import Icon from './Icon.vue';
import InfoTip from './InfoTip.vue';
import PriorityTag from './PriorityTag.vue';
import { apiBase, request } from '../lib/brokers';
import { clock, dateTime, instanceColor, number, queueLabel } from '../lib/format';
import { useI18n, type Key } from '../lib/i18n';
import { buildLanes, laneShare, type Lane, type LanePackage } from '../lib/lanes';
import { instanceName, workerLabel, withKey } from '../lib/names';
import type { MessageDetail, QueueEstimate, Topology } from '../types';

const props = defineProps<{ topology: Topology; estimates: Map<string, QueueEstimate> }>();
const emit = defineEmits<{ open: [id: number] }>();
const { t } = useI18n();

/** Packages drawn per lane: past this the tail folds into "+N", the head (what leaves next) always shows. */
const SHOWN = 120;

const query = ref('');
const includeIdle = ref(false);
const highlight = ref<string | null>(null);

const lanes = computed(() => {
    const term = query.value.trim().toLowerCase();
    return buildLanes(props.topology.queues, props.estimates, props.topology.workers, { brokerVersion: props.topology.broker.version, includeIdle: includeIdle.value })
        .filter((l) => !term || l.queue.name.toLowerCase().includes(term) || queueLabel(l.queue).toLowerCase().includes(term));
});

/** A lane with anything in it is drawn; the empty ones only take a line at the end. */
const busy = computed(() => lanes.value.filter((l) => l.queue.ready + l.queue.unacked > 0));
const quiet = computed(() => lanes.value.filter((l) => l.queue.ready + l.queue.unacked === 0));

/** Every instance with a package on screen, for the legend (busiest first). */
const legend = computed(() => {
    const counts = new Map<string, number>();
    for (const lane of lanes.value) for (const p of [...lane.processing, ...lane.waiting]) counts.set(p.message.instance ?? '', (counts.get(p.message.instance ?? '') ?? 0) + 1);
    return [...counts].map(([key, count]) => ({ key, count })).sort((a, b) => b.count - a.count);
});

const label = (key: string | null): string => instanceName(key, t('no-instance'));
/**
 * What is printed on a package: two letters per instance, unique on screen ("Globex" Gl, "Globus" Go), so the
 * letters tell instances apart where two colours are close. Names in several words use their initials (BM).
 */
const codes = computed(() => {
    const taken = new Set<string>();
    const out = new Map<string, string>();
    // assigned in name order, so a code does not change when the counts do
    for (const { key } of [...legend.value].sort((a, b) => a.key.localeCompare(b.key))) {
        const name = label(key || null).normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        const words = name.split(/[\s._-]+/).filter((w) => w.length > 2);
        const letters = name.replace(/[^A-Za-z0-9]/g, '');
        const candidates = [
            ...(words.length > 1 ? [words[0][0] + words[1][0]] : []),
            ...[...letters.slice(1)].map((c) => letters[0] + c),
            letters.slice(0, 2),
        ].map((c) => c.charAt(0).toUpperCase() + c.slice(1).toLowerCase());
        const code = candidates.find((c) => c.length === 2 && !taken.has(c)) ?? candidates[0] ?? '?';
        taken.add(code);
        out.set(key, code);
    }
    return out;
});
const initials = (key: string | null): string => codes.value.get(key ?? '') ?? '?';
const shown = (lane: Lane): LanePackage[] => lane.waiting.slice(0, SHOWN);
const hiddenTail = (lane: Lane): number => Math.max(0, lane.waiting.length - SHOWN);
const dimmed = (p: LanePackage): boolean => highlight.value !== null && (p.message.instance ?? '') !== highlight.value;
const raised = (p: LanePackage): boolean => p.priority.level === 'high' || p.priority.level === 'very-high';

function packageTitle(p: LanePackage, position: number | null): string {
    const m = p.message;
    return [
        withKey(label(m.instance), m.instance ?? ''),
        m.job || m.routingKey,
        `${t('lanes.arrived')} ${clock(m.ts)}`,
        `${t('detail.priority')}: ${t(`priority.${p.priority.level}` as Key)}`,
        position === null ? t('lanes.with-worker') : t('lanes.position', { n: position }),
    ].join('\n');
}

// --- the message behind a package, in a side panel
const openId = ref<number | null>(null);
const detail = ref<MessageDetail | null>(null);
const failure = ref('');

async function open(id: number): Promise<void> {
    openId.value = id;
    detail.value = null;
    failure.value = '';
    if (apiBase.value === null) return;
    try {
        const result = await request<MessageDetail & { message?: string }>('GET', `${apiBase.value}/messages/${id}`);
        if (openId.value !== id) return;
        if (result.ok) detail.value = result.data;
        else failure.value = result.data?.message ?? `HTTP ${result.status}`;
    } catch (e) {
        if (openId.value === id) failure.value = e instanceof Error ? e.message : String(e);
    }
}
const close = (): void => {
    openId.value = null;
};
watch(apiBase, close);

const prettyBody = (m: MessageDetail): string => {
    try {
        return JSON.stringify(JSON.parse(m.body), null, 2);
    } catch {
        return m.body;
    }
};
const openQueue = computed(() => {
    const m = detail.value;
    return m ? props.topology.queues.find((q) => q.name === m.queues[0]) : undefined;
});
</script>

<template>
    <section class="lanes-screen" :class="{ 'with-drawer': openId !== null }" :aria-label="t('nav.lanes')">
        <div class="lanes-main">
            <header class="filter-row">
                <label class="search">
                    <Icon name="search" :size="16" />
                    <input v-model="query" type="search" :placeholder="t('lanes.search')" :aria-label="t('lanes.search')" />
                </label>
                <label class="check"><input v-model="includeIdle" type="checkbox" /> {{ t('lanes.include-idle') }}</label>
                <InfoTip :label="t('lanes.how')" :text="t('lanes.help')" />
            </header>

            <div v-if="legend.length" class="lanes-legend" role="group" :aria-label="t('lanes.legend')">
                <button
                    v-for="i in legend.slice(0, 24)"
                    :key="i.key"
                    type="button"
                    class="legend-chip"
                    :class="{ active: highlight === i.key }"
                    :aria-pressed="highlight === i.key"
                    :title="withKey(label(i.key), i.key)"
                    @click="highlight = highlight === i.key ? null : i.key"
                >
                    <i :style="{ background: instanceColor(i.key) }"></i>{{ label(i.key) }}<b>{{ number(i.count) }}</b>
                </button>
                <button v-if="highlight !== null" type="button" class="ghost" @click="highlight = null">{{ t('lanes.clear') }}</button>
            </div>

            <p v-if="lanes.length === 0" class="empty-chart muted">{{ t('lanes.empty') }}</p>
            <p v-else-if="busy.length === 0" class="empty-chart muted">{{ t('lanes.all-empty') }}</p>

            <ol class="lanes">
                <li v-for="lane in busy" :key="lane.queue.name" class="lane">
                    <header class="lane-head">
                        <b :title="lane.queue.name">{{ queueLabel(lane.queue) }}</b>
                        <span class="muted">
                            {{ t('lanes.counts', { waiting: number(lane.queue.ready), processing: number(lane.queue.unacked) }) }}
                        </span>
                        <span v-if="laneShare(lane).length > 1" class="lane-share">
                            <span v-for="s in laneShare(lane).slice(0, 4)" :key="s.key"><i :style="{ background: instanceColor(s.key) }"></i>{{ label(s.key) }} {{ number(s.count) }}</span>
                        </span>
                    </header>
                    <div class="lane-body">
                        <div class="lane-track" :aria-label="t('lanes.track', { name: queueLabel(lane.queue) })">
                            <span class="lane-exit" aria-hidden="true"></span>
                            <span v-if="lane.unknownWaiting" class="pkg-unknown" :title="t('lanes.unknown', { n: lane.unknownWaiting })">{{ number(lane.unknownWaiting) }}</span>
                            <TransitionGroup name="pkg">
                                <button
                                    v-for="(p, i) in shown(lane)"
                                    :key="p.message.id"
                                    type="button"
                                    class="pkg"
                                    :class="{ dim: dimmed(p), raised: raised(p), open: openId === p.message.id }"
                                    :style="{ '--pkg': instanceColor(p.message.instance ?? '') }"
                                    :title="packageTitle(p, lane.unknownWaiting + i + 1)"
                                    :aria-label="packageTitle(p, lane.unknownWaiting + i + 1)"
                                    @click="open(p.message.id)"
                                >{{ initials(p.message.instance) }}</button>
                            </TransitionGroup>
                            <span v-if="hiddenTail(lane)" class="pkg-more">+{{ number(hiddenTail(lane)) }}</span>
                            <span v-if="!lane.waiting.length && !lane.unknownWaiting" class="lane-empty">{{ t('lanes.no-wait') }}</span>
                        </div>
                        <div class="lane-ends" aria-hidden="true"><span>{{ t('lanes.last-out') }}</span><span>{{ t('lanes.first-out') }}</span></div>
                        <div class="lane-worker" :class="{ none: !lane.workers.length }">
                            <span class="lane-worker-name" :title="lane.workers.map((w) => w.name).join(', ')">
                                <Icon name="users" :size="14" />
                                {{ lane.workers.length ? (lane.workers.length === 1 ? workerLabel(lane.workers[0]) : t('lanes.workers', { n: lane.workers.length })) : t('lanes.no-worker') }}
                            </span>
                            <span class="lane-worker-slots">
                                <button
                                    v-for="p in lane.processing.slice(0, 40)"
                                    :key="p.message.id"
                                    type="button"
                                    class="pkg"
                                    :class="{ dim: dimmed(p), raised: raised(p), open: openId === p.message.id }"
                                    :style="{ '--pkg': instanceColor(p.message.instance ?? '') }"
                                    :title="packageTitle(p, null)"
                                    :aria-label="packageTitle(p, null)"
                                    @click="open(p.message.id)"
                                >{{ initials(p.message.instance) }}</button>
                                <span v-if="lane.unknownProcessing" class="pkg-unknown" :title="t('lanes.unknown', { n: lane.unknownProcessing })">{{ number(lane.unknownProcessing) }}</span>
                                <span v-if="!lane.processing.length && !lane.unknownProcessing" class="muted">{{ t('lanes.idle') }}</span>
                            </span>
                        </div>
                    </div>
                </li>
            </ol>

            <details v-if="quiet.length" class="lanes-quiet" :open="busy.length === 0">
                <summary>{{ t('lanes.quiet', { n: quiet.length }) }}</summary>
                <ul>
                    <li v-for="lane in quiet" :key="lane.queue.name" :title="lane.queue.name">
                        <span>{{ queueLabel(lane.queue) }}</span>
                        <span class="muted">{{ lane.workers.length ? (lane.workers.length === 1 ? workerLabel(lane.workers[0]) : t('lanes.workers', { n: lane.workers.length })) : t('lanes.no-worker') }}</span>
                    </li>
                </ul>
            </details>
        </div>

        <aside v-if="openId !== null" class="lanes-drawer" :aria-label="t('lanes.message')">
            <header>
                <h3>{{ t('lanes.message') }}</h3>
                <button type="button" class="icon-btn" :aria-label="t('conn.cancel')" @click="close"><Icon name="x" /></button>
            </header>
            <p v-if="failure" class="conn-error">{{ failure }}</p>
            <p v-else-if="!detail" class="muted">{{ t('loading') }}</p>
            <template v-else>
                <p class="drawer-instance"><i :style="{ background: instanceColor(detail.instance ?? '') }"></i><b>{{ label(detail.instance) }}</b></p>
                <dl>
                    <dt>{{ t('detail.time') }}</dt><dd>{{ dateTime(detail.ts) }}</dd>
                    <dt>{{ t('routing-key') }}</dt><dd>{{ detail.routingKey }}</dd>
                    <dt>{{ t('detail.exchange') }}</dt><dd>{{ detail.exchange || t('default-exchange') }}</dd>
                    <template v-if="detail.job"><dt>{{ t('col.job') }}</dt><dd>{{ detail.job }}</dd></template>
                    <dt>{{ t('detail.priority') }}</dt><dd><PriorityTag :priority="detail.priority" :queue="openQueue" :broker-version="topology.broker.version" /></dd>
                    <template v-if="Object.keys(detail.headers).length"><dt>{{ t('detail.headers') }}</dt><dd>{{ JSON.stringify(detail.headers) }}</dd></template>
                </dl>
                <pre>{{ prettyBody(detail) }}{{ detail.bodyTruncated ? '\n' + t('detail.truncated') : '' }}</pre>
                <button type="button" class="link-btn" @click="emit('open', detail.id)">{{ t('lanes.full') }}</button>
            </template>
        </aside>
    </section>
</template>
