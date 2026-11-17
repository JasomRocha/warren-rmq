<script setup lang="ts">
import { computed, ref } from 'vue';
import { clock, decimal, duration, number } from '../lib/format';
import { instanceName, withKey } from '../lib/names';
import { useI18n, type Key } from '../lib/i18n';
import { alerts as buildAlerts, areaInsights, instanceRanking, isThroughput, queueInsights, summary as buildSummary, workerInsights, type Alert, type Level } from '../lib/insights';
import type { InstanceStat, MessageSummary, Selection, Topology } from '../types';

const props = defineProps<{ topology: Topology; stats: InstanceStat[]; messages: MessageSummary[]; now: number }>();
const emit = defineEmits<{ select: [Selection] }>();
const { t } = useI18n();

const TOP_QUEUES = 15;
const showAllQueues = ref(false);

const queues = computed(() => queueInsights(props.topology, props.messages, props.now));
const alerts = computed(() => buildAlerts(queues.value, props.messages, props.now));
const summary = computed(() => buildSummary(queues.value, alerts.value));
const areas = computed(() => areaInsights(queues.value, props.topology.categories, t('ins.no-area')));
const workers = computed(() => workerInsights(props.topology.workers, props.topology.queues));
const categoryOfQueue = computed(() => new Map(props.topology.queues.map((q) => [q.name, q.catalog?.category ?? null])));
const genericAreas = computed(() => new Set(props.topology.categories.filter((c) => c.generic).map((c) => c.key)));
const ranking = computed(() => instanceRanking(props.stats, props.messages, categoryOfQueue.value, genericAreas.value));
const areaName = (key: string | null): string => props.topology.categories.find((c) => c.key === key)?.name ?? '';
const areaColor = (key: string | null): string => props.topology.categories.find((c) => c.key === key)?.color ?? 'var(--border)';

const pressure = computed(() => {
    const main = queues.value.filter(isThroughput).sort((a, b) => b.ready - a.ready || b.unacked - a.unacked || a.label.localeCompare(b.label));
    return showAllQueues.value ? main : main.slice(0, TOP_QUEUES);
});
const mainQueueCount = computed(() => queues.value.filter(isThroughput).length);

const levelText = (l: Level): string => t(`level.${l}` as Key);
const alertText = (a: Alert): string =>
    t(`alert.${a.code}` as Key, { queue: a.label, n: number(a.value), time: duration(a.value) });
const percent = (share: number): string => `${decimal(share * 100, 0)}%`;
const shareWidth = (share: number): string => `${Math.max(2, Math.round(share * 100))}%`;
</script>

<template>
    <div class="insights">
        <div class="summary">
            <div class="card queue"><b>{{ number(summary.waiting) }}</b><span>{{ t('ins.summary.waiting') }}</span></div>
            <div class="card worker"><b>{{ number(summary.processing) }}</b><span>{{ t('ins.summary.processing') }}</span></div>
            <div class="card producer"><b>{{ number(Math.round(summary.ratePerMinute)) }}</b><span>{{ t('ins.summary.rate') }}</span></div>
            <div class="card"><b>{{ duration(summary.processingSeconds) }}</b><span>{{ t('ins.summary.time') }}</span></div>
            <div class="card" :class="summary.critical ? 'critical' : summary.warnings ? 'warning' : 'good'">
                <b>{{ number(summary.critical + summary.warnings) }}</b><span>{{ t('ins.summary.alerts') }}</span>
            </div>
        </div>
        <p class="muted note">{{ t('ins.estimate-note') }}</p>

        <section class="block">
            <h3>{{ t('ins.alerts') }}</h3>
            <p v-if="alerts.length === 0" class="ok-text">✓ {{ t('ins.alerts.none') }}</p>
            <ul v-else class="alerts">
                <li v-for="(a, i) in alerts" :key="i" :class="a.level">
                    <span class="level">{{ levelText(a.level) }}</span>
                    <a v-if="a.queue" @click="emit('select', { kind: 'queue', id: a.queue })">{{ alertText(a) }}</a>
                    <span v-else>{{ alertText(a) }}</span>
                </li>
            </ul>
        </section>

        <section class="block">
            <h3>{{ t('ins.instances') }}</h3>
            <p class="muted">{{ t('ins.instances.help') }}</p>
            <p v-if="ranking.length === 0" class="muted">{{ t('ins.instances.empty') }}</p>
            <table v-else>
                <thead>
                    <tr>
                        <th>{{ t('ins.col.rank') }}</th><th>{{ t('col.instance') }}</th><th>{{ t('ins.col.rate') }}</th><th>{{ t('ins.col.published') }}</th>
                        <th>{{ t('processing') }}</th><th>{{ t('waiting') }}</th><th>{{ t('ins.col.share') }}</th><th>{{ t('ins.col.area') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in ranking.slice(0, 20)" :key="r.key">
                        <td>{{ i + 1 }}</td>
                        <td>
                            <a :title="withKey(instanceName(r.key, t('no-instance')), r.key)" @click="emit('select', { kind: 'producer', id: r.key })">{{ instanceName(r.key, t('no-instance')) }}</a>
                            <span v-if="r.dominates" class="badge-warn">{{ t('ins.dominates') }}</span>
                        </td>
                        <td>{{ number(r.recent) }}</td>
                        <td>{{ number(r.total) }}</td>
                        <td>{{ number(r.processing) }}</td>
                        <td>{{ number(r.waiting) }}</td>
                        <td class="bar-cell"><span class="bar"><i :style="{ width: shareWidth(r.shareOfPending) }"></i></span>{{ percent(r.shareOfPending) }}</td>
                        <td>{{ areaName(r.topArea) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="block">
            <h3>{{ t('ins.areas') }}</h3>
            <p class="muted">{{ t('ins.areas.help') }}</p>
            <table>
                <thead>
                    <tr>
                        <th>{{ t('queue.area') }}</th><th>{{ t('ins.col.queues') }}</th><th>{{ t('waiting') }}</th><th>{{ t('processing') }}</th><th>{{ t('rate-in') }}</th>
                        <th>{{ t('ins.col.proc-time') }}</th><th>{{ t('ins.col.drain') }}</th><th>{{ t('ins.col.retry') }}</th><th>{{ t('ins.col.failures') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in areas" :key="a.key">
                        <td :title="a.description ?? ''"><i class="swatch" :style="{ background: a.color ?? 'var(--border)' }"></i>{{ a.name }}</td>
                        <td>{{ number(a.queues) }}</td>
                        <td>{{ number(a.ready) }}</td>
                        <td>{{ number(a.unacked) }}</td>
                        <td>{{ decimal(a.rateIn) }}/s</td>
                        <td>{{ duration(a.processingSeconds) }}</td>
                        <td :class="a.drainSeconds === Infinity ? 'bad' : ''">{{ duration(a.drainSeconds) }}</td>
                        <td :class="a.retry ? 'warn' : ''">{{ number(a.retry) }}</td>
                        <td :class="a.failures ? 'bad' : ''">{{ number(a.failures) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="block">
            <h3>{{ t('ins.queues') }}</h3>
            <p class="muted">{{ t('ins.queues.help') }}</p>
            <table>
                <thead>
                    <tr>
                        <th>{{ t('kind.queue') }}</th><th>{{ t('queue.area') }}</th><th>{{ t('waiting') }}</th><th>{{ t('processing') }}</th><th>{{ t('consumers') }}</th>
                        <th>{{ t('ins.col.proc-time') }}</th><th>{{ t('ins.col.drain') }}</th><th :title="t('ins.col.wait.title')">{{ t('ins.col.wait') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="q in pressure" :key="q.name" :class="q.level">
                        <td :title="q.name"><a @click="emit('select', { kind: 'queue', id: q.name })">{{ q.label }}</a></td>
                        <td><i class="swatch" :style="{ background: areaColor(q.category) }"></i>{{ areaName(q.category) }}</td>
                        <td>{{ number(q.ready) }}</td>
                        <td>{{ number(q.unacked) }}</td>
                        <td :class="q.consumers === 0 && q.ready > 0 ? 'bad' : ''">{{ number(q.consumers) }}</td>
                        <td>{{ duration(q.processingSeconds) }}</td>
                        <td :class="q.drainSeconds === Infinity ? 'bad' : ''">{{ duration(q.drainSeconds) }}</td>
                        <td>{{ duration(q.waitSeconds) }}</td>
                    </tr>
                </tbody>
            </table>
            <button v-if="mainQueueCount > TOP_QUEUES" type="button" class="more-btn" @click="showAllQueues = !showAllQueues">
                {{ showAllQueues ? t('ins.show-top') : t('ins.show-all') }} ({{ number(mainQueueCount) }})
            </button>
        </section>

        <section class="block">
            <h3>{{ t('ins.workers') }}</h3>
            <p class="muted">{{ t('ins.workers.help') }}</p>
            <table>
                <thead>
                    <tr>
                        <th>{{ t('col.worker') }}</th><th>{{ t('consumers') }}</th><th>{{ t('queues') }}</th><th>{{ t('processing') }}</th>
                        <th>{{ t('ins.col.capacity') }}</th><th>{{ t('ins.col.busy') }}</th><th>{{ t('ins.col.proc-time') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="w in workers" :key="w.name">
                        <td><a :title="w.name" @click="emit('select', { kind: 'worker', id: w.name })">{{ w.label }}</a></td>
                        <td>{{ number(w.consumers) }}</td>
                        <td>{{ number(w.queues) }}</td>
                        <td>~{{ number(w.processing) }}</td>
                        <td>{{ w.capacity === null ? t('unlimited') : number(w.capacity) }}</td>
                        <td class="bar-cell">
                            <template v-if="w.saturation !== null"><span class="bar"><i :class="{ hot: w.saturation > 0.9 }" :style="{ width: Math.min(100, Math.round(w.saturation * 100)) + '%' }"></i></span>{{ percent(w.saturation) }}</template>
                            <template v-else>—</template>
                        </td>
                        <td>{{ duration(w.processingSeconds) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
        <p class="muted note">{{ t('last') }} {{ clock(now) }}</p>
    </div>
</template>
