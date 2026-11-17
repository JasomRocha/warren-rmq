import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { vi } from 'vitest';
import { activeId, brokers, brokersLoaded } from '../lib/brokers';
import { resetInstanceNames } from '../lib/names';
import { go } from '../lib/section';
import Workspace from '../pages/Workspace.vue';
import type { MessageSummary, PanelData, TapStatus, Topology } from '../types';
import { router } from './inertia-mock';
import { topology as defaultTopology } from './fixtures';

export const TAP_UP: TapStatus = { enabled: true, active: true, error: null, since: 1, tracing: true, received: 5 };

export const broker = (over: Record<string, unknown> = {}) => ({
    id: 1, name: 'Produção', url: 'https://rabbit.example.com', username: 'ana', hasPassword: true, vhost: '/', amqpHost: null, amqpPort: 5672, amqpSsl: false, tapEnabled: true,
    health: { ok: true, message: null, at: Math.floor(Date.now() / 1000), version: '4.1', queues: 4, ready: 9, workers: 1 }, tap: TAP_UP, ...over,
});

export interface ApiSetup {
    brokers?: ReturnType<typeof broker>[];
    topology?: Topology | ((brokerId: number) => Topology);
    messages?: MessageSummary[];
    tap?: TapStatus;
    panels?: Record<string, PanelData>;
    metrics?: Record<string, unknown>;
    /** Make a path prefix answer with a status instead of data (e.g. { '/api/b/1/topology': 502 }). */
    status?: Record<string, number>;
    testResult?: Record<string, unknown>;
    writeErrors?: Record<string, string[]>;
    /** Resolve the topology request only when this promise settles (to look at the loading state). */
    holdTopology?: Promise<void>;
}

export interface Call {
    method: string;
    url: string;
    body: any;
}

export function installApi(setup: ApiSetup = {}) {
    const calls: Call[] = [];
    let list = setup.brokers ?? [broker()];
    const reply = (body: unknown, status = 200) => ({ ok: status < 400, status, json: () => (status === 204 ? Promise.reject(new Error('no body')) : Promise.resolve(body)) });

    const fetchMock = vi.fn(async (url: string, init: RequestInit = {}) => {
        const method = init.method ?? 'GET';
        const body = init.body ? JSON.parse(String(init.body)) : undefined;
        calls.push({ method, url, body });

        const forced = Object.entries(setup.status ?? {}).find(([prefix]) => url.startsWith(prefix));
        if (forced) return reply({ message: 'x' }, forced[1]);

        if (url === '/api/brokers' && method === 'GET') return reply(list);
        if (url === '/api/brokers' && method === 'POST') {
            if (setup.writeErrors) return reply({ errors: setup.writeErrors }, 422);
            const created = broker({ id: 99, name: body.name, url: body.url, username: body.username, vhost: body.vhost });
            list = [...list, created];
            return reply(created, 201);
        }
        if (url === '/api/brokers/test') return reply(setup.testResult ?? { ok: true, version: '4.1.1', cluster: 'rabbit@x', tags: ['management'], canMonitor: false, amqp: { ok: true, host: 'rabbit.example.com', port: 5672 } });
        const one = url.match(/^\/api\/brokers\/(\d+)$/);
        if (one && method === 'PUT') {
            list = list.map((b) => (b.id === Number(one[1]) ? { ...b, name: body.name, url: body.url } : b));
            return reply(list.find((b) => b.id === Number(one[1])));
        }
        if (one && method === 'DELETE') {
            list = list.filter((b) => b.id !== Number(one[1]));
            return reply(null, 204);
        }

        const scoped = url.match(/^\/api\/b\/(\d+)(\/.*)$/);
        if (!scoped) return reply({}, 404);
        const [, id, rest] = scoped;
        if (rest === '/topology') {
            if (setup.holdTopology) await setup.holdTopology;
            const t = setup.topology ?? defaultTopology;
            return reply(typeof t === 'function' ? t(Number(id)) : t);
        }
        if (rest.startsWith('/events')) return reply({ rev: setup.messages?.length ?? 0, firstId: 0, messages: setup.messages ?? [], tap: setup.tap ?? TAP_UP });
        if (rest.startsWith('/metrics')) {
            return reply(setup.metrics ?? { range: '1h', from: 0, to: 1, samples: [], areas: [], instances: [], published: [] });
        }
        const panel = rest.match(/^\/paineis\/([^?]+)/);
        if (panel) return setup.panels?.[panel[1]] ? reply(setup.panels[panel[1]]) : reply({ message: 'x' }, 404);
        const message = rest.match(/^\/messages\/(\d+)/);
        if (message) {
            const found = setup.messages?.find((m) => m.id === Number(message[1]));
            const base = { id: Number(message[1]), rev: 1, ts: Date.now(), origin: 'copy', exchange: 'faturamento_resultados', routingKey: 'k', instance: null, instanceName: null, priority: null, job: null, uuid: null, size: 5, queues: [], deliveries: [] };
            return reply({ ...base, ...(found ?? {}), properties: { persistent: true }, headers: {}, body: '{"hello":"world"}', bodyTruncated: false });
        }

        return reply({}, 404);
    });
    vi.stubGlobal('fetch', fetchMock);

    return { fetchMock, calls, urls: () => calls.map((c) => `${c.method} ${c.url}`) };
}

/** Clears everything that survives between tests: stored choices, the connection list, the address. */
export function resetApp(): void {
    localStorage.clear();
    brokers.value = [];
    brokersLoaded.value = false;
    activeId.value = null;
    resetInstanceNames();
    document.documentElement.removeAttribute('data-theme');
    history.replaceState(null, '', '/');
    go('painel');
    router.visit.mockClear();
    router.post.mockClear();
}

export const settle = async (): Promise<void> => {
    for (let i = 0; i < 6; i++) await flushPromises();
};

export async function openWorkspace(section = 'painel'): Promise<VueWrapper> {
    go(section);
    const wrapper = mount(Workspace, { attachTo: document.body });
    await settle();
    return wrapper;
}

export const navLink = (w: VueWrapper, label: string) => w.findAll('.nav-list a').find((a) => a.text() === label)!;

export async function goTo(w: VueWrapper, label: string): Promise<void> {
    await navLink(w, label).trigger('click');
    await settle();
}
