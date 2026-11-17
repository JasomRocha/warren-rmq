<script setup lang="ts">
import PriorityTag from './PriorityTag.vue';
import { computed } from 'vue';
import { situation } from '../lib/estimate';
import { instanceName, withKey, workerLabel } from '../lib/names';
import { clock, dateTime, instanceColor, jsonArgs, number, prefetchText, queueLabel, rate } from '../lib/format';
import { useI18n } from '../lib/i18n';
import type { Binding, InstanceStat, MessageDetail, MessageSummary, QueueEstimate, Selection, Topology } from '../types';

const props = defineProps<{
    topology: Topology;
    selected: Selection | null;
    stats: InstanceStat[];
    messages: MessageSummary[];
    estimates: Map<string, QueueEstimate>;
    message: MessageDetail | { error: string } | null;
    ticked: ReadonlySet<string>;
}>();
const emit = defineEmits<{ select: [Selection]; toggle: [key: string, on: boolean] }>();
const { t } = useI18n();

const label = (key: string | null): string => instanceName(key, t('no-instance'));
const labelOf = (queueName: string): string => {
    const found = props.topology.queues.find((x) => x.name === queueName);
    return found ? queueLabel(found) : queueName;
};
const producer = computed(() => (props.selected?.kind === 'producer' ? props.stats.find((s) => s.key === props.selected?.id) : undefined));
/** The queue the message's priority is read against: the first one it went to. */
const messageQueue = computed(() => {
    const m = props.message;
    return m && !('error' in m) ? props.topology.queues.find((q) => q.name === m.queues[0]) : undefined;
});
const producerMessages = computed(() => (producer.value ? props.messages.filter((m) => (m.instance ?? '') === producer.value?.key) : []));
const producerQueues = computed(() =>
    producer.value
        ? [...producer.value.queues].map((name) => {
              const e = props.estimates.get(name);
              const mine = (e?.messages ?? []).filter((m) => (m.instance ?? '') === producer.value?.key);
              const processing = e ? mine.filter((m) => e.unknown + (e.index.get(m.id) ?? 0) < e.unacked).length : 0;
              return { name, waiting: mine.length - processing, processing };
          })
        : [],
);
const latest = (list: MessageSummary[]): MessageSummary[] => list.slice(-15).reverse();

/** Who published into an exchange or a queue, with the routing keys they used. */
function publishersOf(match: (m: MessageSummary) => boolean): { key: string; count: number; keys: string[] }[] {
    const map = new Map<string, { key: string; count: number; keys: Set<string> }>();
    for (const m of props.messages.filter(match)) {
        const key = m.instance ?? '';
        const row = map.get(key) ?? { key, count: 0, keys: new Set<string>() };
        row.count++;
        row.keys.add(m.routingKey);
        map.set(key, row);
    }
    return [...map.values()].map((r) => ({ ...r, keys: [...r.keys].slice(0, 40) })).sort((a, b) => b.count - a.count);
}
const exchangePublishers = computed(() => (exchange.value ? publishersOf((m) => m.exchange === exchange.value?.name) : []));
const queuePublishers = computed(() => (queue.value ? publishersOf((m) => m.queues.includes(queue.value?.name ?? '')) : []));

const exchange = computed(() => (props.selected?.kind === 'exchange' ? props.topology.exchanges.find((e) => e.name === props.selected?.id) : undefined));
const queue = computed(() => (props.selected?.kind === 'queue' ? props.topology.queues.find((q) => q.name === props.selected?.id) : undefined));
const worker = computed(() => (props.selected?.kind === 'worker' ? props.topology.workers.find((w) => w.name === props.selected?.id) : undefined));

const bindingsOut = computed<Binding[]>(() => (exchange.value ? props.topology.bindings.filter((b) => b.source === exchange.value?.name) : []));
const bindingsIn = computed<Binding[]>(() =>
    queue.value ? props.topology.bindings.filter((b) => b.destination === queue.value?.name && b.destinationType === 'queue') : [],
);
const queueWorkers = computed(() =>
    queue.value
        ? props.topology.workers.flatMap((w) => w.queues.filter((q) => q.queue === queue.value?.name).map((q) => ({ worker: w, q })))
        : [],
);
const statusText = (m: MessageSummary): string => {
    const s = situation(m, props.estimates);
    return s.kind === 'delivered' ? t('status.delivered', { time: clock(s.at) }) : t(`status.${s.kind}` as 'status.left');
};
const prettyBody = (m: MessageDetail): string => {
    try {
        return JSON.stringify(JSON.parse(m.body), null, 2);
    } catch {
        return m.body;
    }
};
const argRows = (a: Record<string, unknown>): [string, string][] => Object.entries(a).map(([k, v]) => [k, typeof v === 'object' ? JSON.stringify(v) : String(v)]);
</script>

<template>
    <div class="details">
        <p v-if="!producer && !exchange && !queue && !worker && selected?.kind !== 'message'" class="muted">{{ t('pick') }}</p>

        <template v-if="selected?.kind === 'message'">
            <p v-if="!message" class="muted">{{ t('loading') }}</p>
            <p v-else-if="'error' in message" class="muted">{{ message.error }}</p>
            <template v-else>
                <div class="head"><span class="kind message">{{ t('kind.message') }}</span><h2>{{ message.job || message.routingKey }}</h2></div>
                <dl>
                    <dt>{{ t('detail.time') }}</dt><dd>{{ dateTime(message.ts) }}</dd>
                    <dt>{{ t('detail.instance') }}</dt><dd><a @click="emit('select', { kind: 'producer', id: message.instance ?? '' })">{{ label(message.instance) }}</a></dd>
                    <dt>{{ t('detail.exchange') }}</dt><dd><a @click="emit('select', { kind: 'exchange', id: message.exchange })">{{ message.exchange || t('default-exchange') }}</a></dd>
                    <dt>{{ t('routing-key') }}</dt><dd>{{ message.routingKey }}</dd>
                    <dt>{{ t('detail.queues') }}</dt>
                    <dd v-if="message.queues.length"><template v-for="(q, i) in message.queues" :key="q"><a @click="emit('select', { kind: 'queue', id: q })">{{ q }}</a>{{ i < message.queues.length - 1 ? ', ' : '' }}</template></dd>
                    <dd v-else>{{ t('detail.no-queues') }}</dd>
                    <dt>{{ t('detail.priority') }}</dt>
                    <dd class="prio-detail">
                        <PriorityTag :priority="message.priority" :queue="messageQueue" :broker-version="topology.broker.version" />
                        <span class="muted">{{ message.priority === null ? t('priority.none') : t('priority.number', { n: message.priority }) }}</span>
                    </dd>
                    <template v-if="message.uuid"><dt>UUID</dt><dd>{{ message.uuid }}</dd></template>
                    <dt>{{ t('detail.size') }}</dt><dd>{{ number(message.size) }} {{ t('bytes') }}</dd>
                    <template v-if="message.properties.replyTo"><dt>reply_to</dt><dd>{{ message.properties.replyTo }}</dd></template>
                    <template v-if="message.properties.correlationId"><dt>correlation_id</dt><dd>{{ message.properties.correlationId }}</dd></template>
                    <template v-if="message.properties.expiration"><dt>expiration</dt><dd>{{ message.properties.expiration }}</dd></template>
                    <dt>{{ t('detail.persistent') }}</dt><dd>{{ message.properties.persistent ? t('yes') : t('no') }}</dd>
                    <dt>{{ t('detail.deliveries') }}</dt>
                    <dd v-if="message.deliveries.length">
                        <div v-for="(d, i) in message.deliveries" :key="i">{{ d.queue }} {{ clock(d.ts) }}{{ d.redelivered ? ` (${t('redelivered')})` : '' }}</div>
                    </dd>
                    <dd v-else>{{ t('detail.no-trace') }}</dd>
                    <template v-if="Object.keys(message.headers).length"><dt>{{ t('detail.headers') }}</dt><dd>{{ JSON.stringify(message.headers) }}</dd></template>
                </dl>
                <pre>{{ prettyBody(message) }}{{ message.bodyTruncated ? '\n' + t('detail.truncated') : '' }}</pre>
            </template>
        </template>

        <template v-else-if="producer">
            <div class="head">
                <span class="kind producer">{{ t('kind.producer') }}</span><h2>{{ label(producer.key) }}</h2><span class="dot big" :style="{ background: instanceColor(producer.key) }"></span>
                <label><input type="checkbox" :checked="ticked.has(producer.key)" @change="emit('toggle', producer.key, ($event.target as HTMLInputElement).checked)" /> {{ t('show-in-flow') }}</label>
            </div>
            <p class="muted">{{ t('explain.producer') }}</p>
            <div class="cards">
                <div class="card worker"><b>{{ number(producer.processing) }}</b><span>{{ t('processing') }}</span></div>
                <div class="card queue"><b>{{ number(producer.waiting) }}</b><span>{{ t('waiting') }}</span></div>
                <div class="card producer"><b>{{ number(producer.recent) }}</b><span>{{ t('published') }}{{ t('per-min') }}</span></div>
                <div class="card producer"><b>{{ number(producer.total) }}</b><span>{{ t('published') }}</span></div>
            </div>
            <h3>{{ t('exchanges-keys') }}</h3>
            <table>
                <thead><tr><th>{{ t('col.exchange') }}</th><th>{{ t('routing-key') }}</th></tr></thead>
                <tbody>
                    <tr v-for="[exchangeName, keys] in producer.exchanges" :key="exchangeName">
                        <td><a @click="emit('select', { kind: 'exchange', id: exchangeName })">{{ exchangeName || t('default-exchange') }}</a></td>
                        <td><div v-for="[k, n] in [...keys].sort((a, b) => b[1] - a[1]).slice(0, 40)" :key="k"><code>{{ k }}</code> <span class="muted">×{{ number(n) }}</span></div></td>
                    </tr>
                </tbody>
            </table>
            <h3>{{ t('queues-reached') }} ({{ number(producerQueues.length) }})</h3>
            <table v-if="producerQueues.length">
                <thead><tr><th>{{ t('col.queue') }}</th><th>{{ t('waiting') }}</th><th>{{ t('processing') }}</th></tr></thead>
                <tbody><tr v-for="q in producerQueues" :key="q.name"><td><a :title="q.name" @click="emit('select', { kind: 'queue', id: q.name })">{{ labelOf(q.name) }}</a></td><td>{{ number(q.waiting) }}</td><td>{{ number(q.processing) }}</td></tr></tbody>
            </table>
            <p v-else class="muted">{{ t('none') }}</p>
            <h3>{{ t('last-messages') }}</h3>
            <table>
                <thead><tr><th>{{ t('col.time') }}</th><th>{{ t('col.key') }}</th><th>{{ t('col.status') }}</th></tr></thead>
                <tbody>
                    <tr v-for="m in latest(producerMessages)" :key="m.id">
                        <td><a @click="emit('select', { kind: 'message', id: String(m.id) })">{{ clock(m.ts) }}</a></td>
                        <td><code>{{ m.routingKey }}</code></td><td>{{ statusText(m) }}</td>
                    </tr>
                </tbody>
            </table>
        </template>

        <template v-else-if="exchange">
            <div class="head"><span class="kind exchange">{{ t('kind.exchange') }}</span><h2>{{ exchange.name || t('default-exchange') }}</h2><span class="pill">{{ exchange.type }}</span></div>
            <p class="muted">{{ t('explain.exchange') }}</p>
            <div class="cards">
                <div class="card"><b>{{ rate(exchange.rateIn) }}</b><span>{{ t('rate-in') }}</span></div>
                <div class="card"><b>{{ rate(exchange.rateOut) }}</b><span>{{ t('rate-out') }}</span></div>
                <div class="card"><b>{{ number(bindingsOut.length) }}</b><span>{{ t('binds') }}</span></div>
            </div>
            <h3>{{ t('bindings-out') }} ({{ number(bindingsOut.length) }})</h3>
            <table v-if="bindingsOut.length">
                <thead><tr><th>{{ t('destination') }}</th><th>{{ t('type') }}</th><th>{{ t('routing-key') }}</th><th>{{ t('arguments') }}</th></tr></thead>
                <tbody>
                    <tr v-for="(b, i) in bindingsOut" :key="i">
                        <td><a @click="emit('select', { kind: b.destinationType === 'queue' ? 'queue' : 'exchange', id: b.destination })">{{ b.destinationType === 'queue' ? labelOf(b.destination) : b.destination }}</a></td>
                        <td>{{ b.destinationType }}</td>
                        <td><code>{{ b.routingKey || '—' }}</code></td>
                        <td><code>{{ jsonArgs(b.arguments) }}</code></td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="muted">{{ t('none') }}</p>
            <h3>{{ t('producers-publishing') }}</h3>
            <table v-if="exchangePublishers.length">
                <thead><tr><th>{{ t('col.instance') }}</th><th>{{ t('times') }}</th><th>{{ t('routing-key') }}</th></tr></thead>
                <tbody>
                    <tr v-for="p in exchangePublishers" :key="p.key">
                        <td><a @click="emit('select', { kind: 'producer', id: p.key })">{{ label(p.key) }}</a></td>
                        <td>{{ number(p.count) }}</td>
                        <td><div v-for="k in p.keys" :key="k"><code>{{ k }}</code></div></td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="muted">{{ t('none') }}</p>
            <h3>{{ t('arguments') }}</h3>
            <table v-if="argRows(exchange.arguments).length"><tbody><tr v-for="[k, v] in argRows(exchange.arguments)" :key="k"><td><code>{{ k }}</code></td><td><code>{{ v }}</code></td></tr></tbody></table>
            <p v-else class="muted">{{ t('none') }}</p>
        </template>

        <template v-else-if="queue">
            <div class="head">
                <span class="kind queue">{{ t('kind.queue') }}</span><h2>{{ queueLabel(queue) }}</h2>
                <span class="pill">{{ queue.type }}</span><span v-if="queue.durable" class="pill">{{ t('durable') }}</span>
                <span v-if="queue.catalog.role !== 'principal'" class="pill" :class="queue.catalog.role">{{ t(`queue.role.${queue.catalog.role}` as 'queue.role.espera') }}</span>
            </div>
            <dl v-if="queue.catalog.name || queue.catalog.category" class="about">
                <template v-if="queue.catalog.name"><dt>{{ t('queue.technical') }}</dt><dd>{{ queue.name }}</dd></template>
                <template v-if="queue.catalog.category"><dt>{{ t('queue.area') }}</dt><dd>{{ topology.categories.find((c) => c.key === queue?.catalog.category)?.name ?? queue.catalog.category }}</dd></template>
            </dl>
            <p v-if="queue.catalog.description" class="muted">{{ queue.catalog.description }}</p>
            <p class="muted">{{ t('explain.queue') }}</p>
            <div class="cards">
                <div class="card queue"><b>{{ number(queue.ready) }}</b><span>{{ t('waiting') }}</span></div>
                <div class="card worker"><b>{{ number(queue.unacked) }}</b><span>{{ t('processing') }}</span></div>
                <div class="card worker"><b>{{ number(queue.consumers) }}</b><span>{{ t('consumers') }}</span></div>
                <div class="card"><b>{{ rate(queue.rateIn) }}</b><span>{{ t('rate-in') }}</span></div>
                <div class="card"><b>{{ rate(queue.rateDeliver) }}</b><span>{{ t('rate-out') }}</span></div>
                <div class="card"><b>{{ rate(queue.rateAck) }}</b><span>{{ t('ack') }}</span></div>
            </div>
            <h3>{{ t('bindings-in') }} ({{ number(bindingsIn.length) }})</h3>
            <table v-if="bindingsIn.length">
                <thead><tr><th>{{ t('source') }}</th><th>{{ t('routing-key') }}</th><th>{{ t('arguments') }}</th></tr></thead>
                <tbody>
                    <tr v-for="(b, i) in bindingsIn" :key="i">
                        <td><a @click="emit('select', { kind: 'exchange', id: b.source })">{{ b.source }}</a></td>
                        <td><code>{{ b.routingKey || '—' }}</code></td>
                        <td><code>{{ jsonArgs(b.arguments) }}</code></td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="muted">{{ t('none') }}</p>
            <h3>{{ t('workers') }} ({{ number(queueWorkers.length) }})</h3>
            <table v-if="queueWorkers.length">
                <thead><tr><th>{{ t('col.worker') }}</th><th>{{ t('consumers') }}</th><th>{{ t('prefetch') }}</th><th>{{ t('ack-mode') }}</th></tr></thead>
                <tbody>
                    <tr v-for="r in queueWorkers" :key="r.worker.name">
                        <td><a :title="r.worker.name" @click="emit('select', { kind: 'worker', id: r.worker.name })">{{ workerLabel(r.worker) }}</a></td>
                        <td>{{ number(r.q.consumers) }}</td>
                        <td>{{ prefetchText(r.q.prefetchTotal / r.q.consumers, t('unlimited')) }}</td>
                        <td>{{ r.q.manualAck ? t('manual-ack') : t('auto-ack') }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="muted">{{ t('none') }}</p>
            <h3>{{ t('producers-publishing') }}</h3>
            <table v-if="queuePublishers.length">
                <thead><tr><th>{{ t('col.instance') }}</th><th>{{ t('times') }}</th><th>{{ t('routing-key') }}</th></tr></thead>
                <tbody>
                    <tr v-for="p in queuePublishers" :key="p.key">
                        <td><a @click="emit('select', { kind: 'producer', id: p.key })">{{ label(p.key) }}</a></td>
                        <td>{{ number(p.count) }}</td>
                        <td><div v-for="k in p.keys" :key="k"><code>{{ k }}</code></div></td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="muted">{{ t('none') }}</p>
            <h3>{{ t('arguments') }}</h3>
            <table v-if="argRows(queue.arguments).length"><tbody><tr v-for="[k, v] in argRows(queue.arguments)" :key="k"><td><code>{{ k }}</code></td><td><code>{{ v }}</code></td></tr></tbody></table>
            <p v-else class="muted">{{ t('none') }}</p>
        </template>

        <template v-else-if="worker">
            <div class="head">
                <span class="kind worker">{{ t('kind.worker') }}</span><h2>{{ workerLabel(worker) }}</h2>
                <span class="pill ok">{{ worker.manualAck ? t('manual-ack') : t('auto-ack') }}</span>
            </div>
            <p v-if="worker.catalog.description" class="about-text">{{ worker.catalog.description }}</p>
            <p class="muted">{{ t('explain.worker') }}</p>
            <div class="cards">
                <div class="card worker"><b>~{{ number(worker.processingEstimate) }}</b><span>{{ t('processing') }} ({{ t('estimated') }})</span></div>
                <div class="card queue"><b>{{ number(worker.queues.length) }}</b><span>{{ t('queues') }}</span></div>
                <div class="card worker"><b>{{ number(worker.consumers) }}</b><span>{{ t('consumers') }}</span></div>
                <div class="card"><b>{{ number(worker.connections.length) }}</b><span>{{ t('worker.connections') }}</span></div>
            </div>

            <h3>{{ t('worker.identification') }}</h3>
            <dl>
                <dt>{{ t('worker.technical-name') }}</dt><dd>{{ worker.name }}</dd>
                <template v-if="worker.catalog.service"><dt>{{ t('worker.service') }}</dt><dd>{{ worker.catalog.service }}</dd></template>
                <dt>{{ t('worker.ip') }}</dt><dd>{{ worker.host ?? '?' }}</dd>
                <dt>{{ t('user') }}</dt><dd>{{ worker.user ?? '?' }}</dd>
                <dt>{{ t('worker.nodes', { n: worker.nodes.length }) }}</dt><dd>{{ worker.nodes.join(', ') || '?' }}</dd>
                <dt>{{ t('prefetch') }}</dt><dd>{{ worker.prefetchMin === worker.prefetchMax ? prefetchText(worker.prefetchMax, t('unlimited')) : `${prefetchText(worker.prefetchMin, t('unlimited'))} – ${prefetchText(worker.prefetchMax, t('unlimited'))}` }}</dd>
            </dl>

            <h3>{{ t('worker.connections') }} ({{ number(worker.connections.length) }})</h3>
            <table>
                <thead><tr><th>{{ t('worker.from') }}</th><th>{{ t('worker.node') }}</th><th>{{ t('consumers') }}</th></tr></thead>
                <tbody>
                    <tr v-for="c in worker.connections" :key="c.name">
                        <td><code>{{ c.host ?? '?' }}<template v-if="c.port">:{{ c.port }}</template></code></td>
                        <td>{{ c.node ?? '?' }}</td>
                        <td>{{ number(c.consumers) }}</td>
                    </tr>
                </tbody>
            </table>

            <h3>{{ t('queues') }} ({{ number(worker.queues.length) }})</h3>
            <table>
                <thead><tr><th>{{ t('col.queue') }}</th><th>{{ t('consumers') }}</th><th>{{ t('prefetch') }}</th><th>{{ t('ack-mode') }}</th></tr></thead>
                <tbody>
                    <tr v-for="q in worker.queues" :key="q.queue">
                        <td><a :title="q.queue" @click="emit('select', { kind: 'queue', id: q.queue })">{{ labelOf(q.queue) }}</a></td>
                        <td>{{ number(q.consumers) }}</td>
                        <td>{{ prefetchText(q.prefetchTotal / q.consumers, t('unlimited')) }}</td>
                        <td>{{ q.manualAck ? t('manual-ack') : t('auto-ack') }}</td>
                    </tr>
                </tbody>
            </table>
        </template>
    </div>
</template>
