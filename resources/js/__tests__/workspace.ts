import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { vi, type Mock } from 'vitest';
import { activeId, brokers, brokersLoaded, type Broker } from '../lib/brokers';
import { section, toHash } from '../lib/section';
import Workspace from '../pages/Workspace.vue';

export const BROKER: Broker = {
    id: 1, name: 'Produção', url: 'http://rabbit:15672', username: 'warren', hasPassword: true, vhost: '/',
    amqpHost: null, amqpPort: 5672, amqpSsl: false, tapEnabled: true,
    health: { ok: true, message: null, at: Math.floor(Date.now() / 1000), version: '4.1.0', queues: 3, ready: 5, workers: 1 },
};

type Reply = { ok: boolean; status: number; json: () => Promise<unknown> };
type Handler = (url: string, init?: RequestInit) => Promise<Reply>;

export const json = (body: unknown, status = 200): Promise<Reply> => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

/**
 * Answers /api/brokers with the given connections and hands everything under /api/b/{id}/ to `handler`
 * with the prefix taken off, so a handler can match "/api/topology" whatever connection is open.
 */
export function routeApi(handler: Handler, list: Broker[] = [BROKER]): Mock<Handler> {
    return vi.fn<Handler>((url: string, init?: RequestInit) => {
        if (url === '/api/brokers' && (init?.method ?? 'GET') === 'GET') return json(list);
        const scoped = url.match(/^\/api\/b\/\d+\/(.*)$/);
        return handler(scoped ? `/api/${scoped[1]}` : url, init);
    });
}

/** The connection list, the open connection and the screen live in modules, so each test starts them over. */
export function resetWorkspace(start = 'fluxo'): void {
    brokers.value = [];
    brokersLoaded.value = false;
    activeId.value = null;
    localStorage.clear();
    section.value = start;
    history.replaceState(null, '', toHash(start));
}

export async function openWorkspace(): Promise<VueWrapper> {
    const w = mount(Workspace, { attachTo: document.body });
    // brokers, then the topology and events of the connection picked from them
    for (let i = 0; i < 3; i++) await flushPromises();
    return w;
}

const navLink = (w: VueWrapper, label: string) => w.findAll('.nav-list a').find((a) => a.text() === label);

export async function goTo(w: VueWrapper, label: string): Promise<void> {
    const link = navLink(w, label);
    if (!link) throw new Error(`no menu entry "${label}"`);
    await link.trigger('click');
    await flushPromises();
}

export const isActive = (w: VueWrapper, label: string): boolean => navLink(w, label)?.classes().includes('active') ?? false;
export const navLabels = (w: VueWrapper): string[] => w.findAll('.nav-list a').map((a) => a.text());

/** jsdom delivers some navigations (hashchange, popstate) on a later task: drained here, they cannot land in the next test. */
export const settle = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 30));
