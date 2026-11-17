<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import Icon from './Icon.vue';
import Skeleton from './Skeleton.vue';
import {
    activeId,
    brokers,
    brokersLoaded,
    deleteBroker,
    emptyForm,
    formFrom,
    loadBrokers,
    saveBroker,
    setActive,
    testBroker,
    type Broker,
    type BrokerForm,
    type TestResult,
} from '../lib/brokers';
import { number, relativeTime } from '../lib/format';
import { useI18n } from '../lib/i18n';

const emit = defineEmits<{ open: [id: number] }>();
const { t } = useI18n();

const form = ref<BrokerForm | null>(null);
const editing = computed(() => brokers.value.find((b) => b.id === form.value?.id) ?? null);
const errors = ref<Record<string, string>>({});
const saving = ref(false);
const testing = ref(false);
const result = ref<TestResult | null>(null);
const failure = ref('');
const firstField = ref<HTMLInputElement | null>(null);

async function startForm(next: BrokerForm): Promise<void> {
    form.value = next;
    errors.value = {};
    result.value = null;
    failure.value = '';
    await nextTick();
    firstField.value?.focus();
}

const add = (): Promise<void> => startForm(emptyForm());
const edit = (b: Broker): Promise<void> => startForm(formFrom(b));

function close(): void {
    form.value = null;
}

/** Laravel answers 422 with `{ errors: { field: [message] } }`. */
function collectErrors(data: unknown): Record<string, string> {
    const raw = (data as { errors?: Record<string, string[]> } | null)?.errors ?? {};
    return Object.fromEntries(Object.entries(raw).map(([field, messages]) => [field, messages[0] ?? '']));
}

async function test(): Promise<void> {
    if (!form.value) return;
    testing.value = true;
    result.value = null;
    errors.value = {};
    try {
        const response = await testBroker(form.value);
        if (response.status === 422) errors.value = collectErrors(response.data);
        else result.value = response.data;
    } catch (e) {
        result.value = { ok: false, message: e instanceof Error ? e.message : String(e) };
    } finally {
        testing.value = false;
    }
}

async function save(): Promise<void> {
    if (!form.value) return;
    saving.value = true;
    failure.value = '';
    errors.value = {};
    try {
        const creating = form.value.id === undefined;
        const response = await saveBroker(form.value);
        if (response.status === 422) {
            errors.value = collectErrors(response.data);
            return;
        }
        if (!response.ok) {
            failure.value = (response.data as { message?: string } | null)?.message ?? t('conn.error');
            return;
        }
        await loadBrokers();
        if (creating || activeId.value === null) setActive(response.data.id);
        close();
    } catch (e) {
        failure.value = e instanceof Error ? e.message : String(e);
    } finally {
        saving.value = false;
    }
}

async function remove(b: Broker): Promise<void> {
    if (!window.confirm(t('conn.remove.confirm', { name: b.name }))) return;
    const response = await deleteBroker(b.id);
    if (response.ok) {
        if (form.value?.id === b.id) close();
        await loadBrokers();
    }
}

function healthLabel(b: Broker): string {
    if (b.health?.ok === true) return t('health.ok');
    if (b.health?.ok === false) return t('health.down');
    return t('health.unknown');
}

const healthClass = (b: Broker): string => (b.health?.ok === true ? 'ok' : b.health?.ok === false ? 'down' : 'unknown');
</script>

<template>
    <section class="connections" :aria-label="t('nav.connections')">
        <header class="page-head">
            <p class="page-intro">{{ t('conn.subtitle') }}</p>
            <button type="button" class="primary" @click="add"><Icon name="plus" :size="16" />{{ t('conn.add') }}</button>
        </header>

        <div class="conn-layout" :class="{ 'with-form': form }">
            <div class="conn-list">
                <div v-if="!brokersLoaded" class="conn-cards" role="status" aria-busy="true">
                    <div v-for="i in 2" :key="i" class="conn-card"><Skeleton width="45%" height="16px" /><Skeleton :lines="2" /></div>
                </div>
                <div v-else-if="brokers.length === 0 && !form" class="empty-state">
                    <Icon name="plug" :size="28" />
                    <p>{{ t('conn.empty') }}</p>
                    <button type="button" class="primary" @click="add"><Icon name="plus" :size="16" />{{ t('conn.add') }}</button>
                </div>
                <ul v-else class="conn-cards">
                    <li v-for="b in brokers" :key="b.id" class="conn-card" :class="{ current: b.id === activeId, editing: b.id === form?.id }">
                        <div class="conn-title">
                            <span class="status-dot" :class="healthClass(b)" :title="healthLabel(b)"></span>
                            <b>{{ b.name }}</b>
                            <span v-if="b.id === activeId" class="tag-soft">{{ t('conn.current') }}</span>
                        </div>
                        <code class="conn-url">{{ b.url }} · {{ b.vhost }}</code>
                        <p class="conn-meta muted">
                            <span :class="['health', healthClass(b)]">{{ healthLabel(b) }}</span>
                            <template v-if="b.health?.at"> · {{ relativeTime(b.health.at * 1000, Date.now()) }}</template>
                            <template v-if="b.health?.ok && b.health.queues !== undefined">
                                · {{ t('conn.stats', { queues: number(b.health.queues ?? 0), ready: number(b.health.ready ?? 0), workers: number(b.health.workers ?? 0) }) }}
                            </template>
                        </p>
                        <p v-if="b.health?.ok === false && b.health.message" class="conn-error">{{ b.health.message }}</p>
                        <p v-if="b.tap" class="conn-meta muted">
                            {{ !b.tap.enabled ? t('tap.disabled') : b.tap.active ? t('tap.active') : t('tap.off') }}
                        </p>
                        <div class="conn-actions">
                            <button v-if="b.id !== activeId" type="button" @click="setActive(b.id); emit('open', b.id)">{{ t('conn.open') }}</button>
                            <button type="button" @click="edit(b)"><Icon name="edit" :size="15" />{{ t('conn.edit') }}</button>
                            <button type="button" class="danger-ghost" @click="remove(b)"><Icon name="trash" :size="15" />{{ t('conn.remove') }}</button>
                        </div>
                    </li>
                </ul>
            </div>

            <form v-if="form" class="conn-form" novalidate @submit.prevent="save">
                <header>
                    <h3>{{ editing ? t('conn.form.edit', { name: editing.name }) : t('conn.form.new') }}</h3>
                    <button type="button" class="icon-btn" :aria-label="t('conn.cancel')" @click="close"><Icon name="x" /></button>
                </header>

                <label class="field">
                    <span>{{ t('conn.field.name') }}</span>
                    <input ref="firstField" v-model="form.name" name="name" required maxlength="80" :aria-invalid="!!errors.name" />
                    <small v-if="errors.name" class="field-error">{{ errors.name }}</small>
                    <small v-else class="muted">{{ t('conn.field.name.hint') }}</small>
                </label>

                <label class="field">
                    <span>{{ t('conn.field.url') }}</span>
                    <input v-model="form.url" name="url" type="url" required inputmode="url" spellcheck="false" :aria-invalid="!!errors.url" />
                    <small v-if="errors.url" class="field-error">{{ errors.url }}</small>
                    <small v-else class="muted">{{ t('conn.field.url.hint') }}</small>
                </label>

                <div class="field-row">
                    <label class="field">
                        <span>{{ t('conn.field.username') }}</span>
                        <input v-model="form.username" name="username" required autocomplete="off" spellcheck="false" :aria-invalid="!!errors.username" />
                        <small v-if="errors.username" class="field-error">{{ errors.username }}</small>
                    </label>
                    <label class="field">
                        <span>{{ t('conn.field.password') }}</span>
                        <input v-model="form.password" name="password" type="password" autocomplete="new-password" :required="form.id === undefined" :aria-invalid="!!errors.password" />
                        <small v-if="errors.password" class="field-error">{{ errors.password }}</small>
                        <small v-else-if="form.id !== undefined" class="muted">{{ t('conn.field.password.keep') }}</small>
                    </label>
                </div>

                <label class="field">
                    <span>{{ t('conn.field.vhost') }}</span>
                    <input v-model="form.vhost" name="vhost" spellcheck="false" :aria-invalid="!!errors.vhost" />
                    <small v-if="errors.vhost" class="field-error">{{ errors.vhost }}</small>
                </label>

                <fieldset>
                    <legend>{{ t('conn.field.amqp') }}</legend>
                    <label class="check">
                        <input v-model="form.tap_enabled" type="checkbox" name="tap_enabled" />
                        <span>{{ t('conn.field.tap') }}<small class="muted">{{ t('conn.field.tap.hint') }}</small></span>
                    </label>
                    <div class="field-row">
                        <label class="field grow">
                            <span>{{ t('conn.field.amqp-host') }}</span>
                            <input v-model="form.amqp_host" name="amqp_host" spellcheck="false" :aria-invalid="!!errors.amqp_host" />
                            <small v-if="errors.amqp_host" class="field-error">{{ errors.amqp_host }}</small>
                            <small v-else class="muted">{{ t('conn.field.amqp-host.hint') }}</small>
                        </label>
                        <label class="field port">
                            <span>{{ t('conn.field.amqp-port') }}</span>
                            <input v-model.number="form.amqp_port" name="amqp_port" type="number" min="1" max="65535" :aria-invalid="!!errors.amqp_port" />
                            <small v-if="errors.amqp_port" class="field-error">{{ errors.amqp_port }}</small>
                        </label>
                    </div>
                    <label class="check">
                        <input v-model="form.amqp_ssl" type="checkbox" name="amqp_ssl" />
                        <span>{{ t('conn.field.amqp-ssl') }}</span>
                    </label>
                </fieldset>

                <div v-if="result" class="test-result" :class="result.ok ? 'ok' : 'down'" role="status">
                    <p>
                        <Icon :name="result.ok ? 'check' : 'alert'" :size="16" />
                        <b>{{ result.ok ? t('conn.test.ok', { version: result.version ?? '' }) : t('conn.test.fail') }}</b>
                    </p>
                    <p v-if="!result.ok && result.message">{{ result.message }}</p>
                    <p v-if="result.ok && result.canMonitor === false" class="muted">{{ t('conn.test.monitor') }}</p>
                    <p v-if="result.amqp" :class="result.amqp.ok ? 'muted' : 'warn-text'">
                        {{ t(result.amqp.ok ? 'conn.test.amqp-ok' : 'conn.test.amqp-fail', { host: result.amqp.host, port: result.amqp.port }) }}
                    </p>
                </div>
                <p v-if="failure" class="field-error" role="alert">{{ failure }}</p>

                <footer>
                    <button type="button" :disabled="testing" @click="test"><Icon name="refresh" :size="15" />{{ testing ? t('conn.testing') : t('conn.test') }}</button>
                    <span class="push"></span>
                    <button type="button" @click="close">{{ t('conn.cancel') }}</button>
                    <button type="submit" class="primary" :disabled="saving">{{ saving ? t('conn.saving') : t('conn.save') }}</button>
                </footer>
            </form>
        </div>
    </section>
</template>
