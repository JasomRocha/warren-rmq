import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { TapStatus } from '../types';

export interface BrokerHealth {
    ok: boolean | null;
    message: string | null;
    at: number | null;
    version?: string;
    queues?: number;
    ready?: number;
    workers?: number;
}

export interface Broker {
    id: number;
    name: string;
    url: string;
    username: string;
    hasPassword: boolean;
    vhost: string;
    amqpHost: string | null;
    amqpPort: number;
    amqpSsl: boolean;
    tapEnabled: boolean;
    health?: BrokerHealth;
    tap?: TapStatus;
}

export interface BrokerForm {
    id?: number;
    name: string;
    url: string;
    username: string;
    password: string;
    vhost: string;
    amqp_host: string;
    amqp_port: number | '';
    amqp_ssl: boolean;
    tap_enabled: boolean;
}

export interface TestResult {
    ok: boolean;
    message?: string;
    version?: string | null;
    cluster?: string | null;
    tags?: string[];
    canMonitor?: boolean;
    amqp?: { ok: boolean; host: string; port: number };
}

const STORAGE = 'warren.broker';

function remembered(): number | null {
    try {
        const raw = localStorage.getItem(STORAGE);
        return raw === null ? null : Number(raw);
    } catch {
        return null;
    }
}

/** Every connection Warren follows, and the one being looked at. */
export const brokers = ref<Broker[]>([]);
export const brokersLoaded = ref(false);
export const activeId = ref<number | null>(remembered());
export const active = computed(() => brokers.value.find((b) => b.id === activeId.value) ?? null);

/** Root of the API for the connection being looked at, or null before there is one. */
export const apiBase = computed(() => (activeId.value === null ? null : `/api/b/${activeId.value}`));

export function setActive(id: number): void {
    activeId.value = id;
    try {
        localStorage.setItem(STORAGE, String(id));
    } catch {
        /* private mode: it just is not remembered */
    }
}

/** Laravel sets the CSRF token in a cookie; writes must send it back in a header. */
function csrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export interface ApiResult<T> {
    ok: boolean;
    status: number;
    data: T;
}

export async function request<T = unknown>(method: 'GET' | 'POST' | 'PUT' | 'DELETE', url: string, body?: unknown): Promise<ApiResult<T>> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            ...(method !== 'GET' ? { 'X-XSRF-TOKEN': csrf() } : {}),
        },
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (response.status === 401) {
        router.visit('/login'); // the session ended
    }
    let data: unknown = null;
    try {
        data = await response.json();
    } catch {
        /* 204 and friends have no body */
    }
    return { ok: response.ok, status: response.status, data: data as T };
}

export async function loadBrokers(): Promise<void> {
    const result = await request<Broker[]>('GET', '/api/brokers');
    if (!result.ok) return;
    brokers.value = result.data;
    brokersLoaded.value = true;
    if (!brokers.value.some((b) => b.id === activeId.value)) {
        if (brokers.value.length) setActive(brokers.value[0].id);
        else activeId.value = null;
    }
}

const payload = (form: BrokerForm) => ({
    name: form.name,
    url: form.url,
    username: form.username,
    password: form.password,
    vhost: form.vhost,
    amqp_host: form.amqp_host,
    amqp_port: form.amqp_port === '' ? 5672 : form.amqp_port,
    amqp_ssl: form.amqp_ssl,
    tap_enabled: form.tap_enabled,
});

export const saveBroker = (form: BrokerForm) =>
    form.id === undefined ? request<Broker>('POST', '/api/brokers', payload(form)) : request<Broker>('PUT', `/api/brokers/${form.id}`, payload(form));

export const testBroker = (form: BrokerForm) => request<TestResult>('POST', '/api/brokers/test', { id: form.id, ...payload(form) });

export const deleteBroker = (id: number) => request('DELETE', `/api/brokers/${id}`);

export const emptyForm = (): BrokerForm => ({
    name: '', url: 'http://', username: '', password: '', vhost: '/', amqp_host: '', amqp_port: 5672, amqp_ssl: false, tap_enabled: true,
});

export const formFrom = (b: Broker): BrokerForm => ({
    id: b.id, name: b.name, url: b.url, username: b.username, password: '', vhost: b.vhost, amqp_host: b.amqpHost ?? '', amqp_port: b.amqpPort, amqp_ssl: b.amqpSsl, tap_enabled: b.tapEnabled,
});
