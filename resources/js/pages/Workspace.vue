<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import Connections from '../Components/Connections.vue';
import Dashboard from '../Components/Dashboard.vue';
import Details from '../Components/Details.vue';
import Diagram from '../Components/Diagram.vue';
import Icon from '../Components/Icon.vue';
import Insights from '../Components/Insights.vue';
import InstanceList from '../Components/InstanceList.vue';
import Logo from '../Components/Logo.vue';
import MessageList from '../Components/MessageList.vue';
import Panel from '../Components/Panel.vue';
import QueueLanes from '../Components/QueueLanes.vue';
import RabbitLoader from '../Components/RabbitLoader.vue';
import { active, activeId, apiBase, brokers, brokersLoaded, loadBrokers, request, setActive } from '../lib/brokers';
import { estimateQueues, instanceStats } from '../lib/estimate';
import { relativeTime } from '../lib/format';
import { useI18n, type Key } from '../lib/i18n';
import { learnInstanceNames, resetInstanceNames } from '../lib/names';
import { enablePanning } from '../lib/pan';
import { go, panelSection, section, watchHash } from '../lib/section';
import { applyTheme, effectiveTheme, theme, toggleTheme } from '../lib/theme';
import { useMessages } from '../lib/useMessages';
import type { MessageDetail, Selection, Topology } from '../types';

const { t } = useI18n();
const auth = computed(() => (usePage().props.auth ?? { enabled: false, user: null }) as { enabled: boolean; user: { name: string; email: string } | null });
const STORAGE_SIDEBAR = 'warren.sidebar';
const STORAGE_NAV = 'warren.nav';

const topology = ref<Topology | null>(null);
/** When the screen last got fresh numbers: what "live" means to someone looking at it. */
const lastUpdate = ref<number | null>(null);
const error = ref('');
const query = ref('');
const showKeys = ref(true);
const paused = ref(false);
const navOpen = ref(false);
const navCollapsed = ref(read(STORAGE_NAV) === 'collapsed');
const canvas = ref<HTMLElement | null>(null);
let stopPanning: (() => void) | undefined;
const panels = computed(() => topology.value?.panels ?? []);
const selected = ref<Selection | null>(null);
const messageDetail = ref<MessageDetail | { error: string } | null>(null);
const queueFilter = ref('');
// closed until asked for: most of the time nobody is following specific instances, and the diagram needs the width
const instancesOpen = ref(read(STORAGE_SIDEBAR) === '1');
const ticked = ref<ReadonlySet<string>>(new Set());

const { all: allMessages, tap, now, poll } = useMessages(apiBase);
watch(allMessages, (list) => learnInstanceNames(list), { immediate: true });

function read(key: string): string | null {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}
function write(key: string, value: string): void {
    try {
        localStorage.setItem(key, value);
    } catch {
        /* storage unavailable (private mode): the view still works, it just is not remembered */
    }
}
function parse(raw: string | null): string[] {
    try {
        const value = JSON.parse(raw ?? '[]');
        return Array.isArray(value) ? value.filter((v): v is string => typeof v === 'string') : [];
    } catch {
        return [];
    }
}

/** The ticked instances belong to one connection: another RabbitMQ has other instances. */
const tickedKey = computed(() => `warren.instances.${activeId.value ?? 'none'}`);

// declared before the immediate watch below, which already polls
let timers: ReturnType<typeof setInterval>[] = [];
let busyTopology = false;
let busyEvents = false;

// --- the connection being looked at
watch(
    apiBase,
    () => {
        topology.value = null;
        lastUpdate.value = null;
        error.value = '';
        selected.value = null;
        messageDetail.value = null;
        queueFilter.value = '';
        resetInstanceNames();
        ticked.value = new Set(parse(read(tickedKey.value)));
        void loadTopology();
        void loadEvents();
    },
    { immediate: true },
);

// --- derived data: one pass over the messages feeds every view
const estimates = computed(() => (topology.value ? estimateQueues(allMessages.value, topology.value.queues) : new Map()));
const stats = computed(() => instanceStats(allMessages.value, estimates.value, now.value));
const visible = computed(() => (ticked.value.size ? allMessages.value.filter((m) => ticked.value.has(m.instance ?? '')) : allMessages.value));

function toggleInstance(key: string, on: boolean): void {
    const next = new Set(ticked.value);
    if (on) next.add(key);
    else next.delete(key);
    setTicked(next);
}
function setTicked(next: Set<string>): void {
    ticked.value = next;
    write(tickedKey.value, JSON.stringify([...next]));
}
function tickBusiest(): void {
    const top = [...stats.value]
        .filter((s) => s.processing + s.waiting > 0)
        .sort((a, b) => b.processing - a.processing || b.waiting - a.waiting)
        .slice(0, 5);
    setTicked(new Set(top.map((s) => s.key)));
}
function toggleNav(): void {
    navCollapsed.value = !navCollapsed.value;
    write(STORAGE_NAV, navCollapsed.value ? 'collapsed' : 'expanded');
}
function toggleInstances(): void {
    instancesOpen.value = !instancesOpen.value;
    write(STORAGE_SIDEBAR, instancesOpen.value ? '1' : '0');
}

// --- navigation
interface NavItem {
    section: string;
    label: string;
    icon: string;
    title?: string;
}

const mainNav = computed<NavItem[]>(() => [
    { section: 'painel', label: t('nav.dashboard'), icon: 'dashboard' },
    { section: 'fluxo', label: t('tab.flow'), icon: 'flow' },
    { section: 'filas', label: t('nav.lanes'), icon: 'lanes' },
    { section: 'insights', label: t('tab.insights'), icon: 'insights' },
    { section: 'mensagens', label: t('tab.messages'), icon: 'messages' },
    { section: 'detalhes', label: t('tab.details'), icon: 'info' },
]);
const panelNav = computed<NavItem[]>(() =>
    panels.value.map((p) => ({ section: panelSection(p.id), label: p.title, icon: p.kind === 'erros' ? 'alert' : 'inbox', title: p.description ?? p.title })),
);

const noConnection = computed(() => brokersLoaded.value && brokers.value.length === 0);

/** What is on screen: the address, unless it points nowhere (no connection yet, or a panel that does not exist). */
const current = computed(() => {
    if (noConnection.value) return 'conexoes';
    const s = section.value;
    if (s.startsWith('panel:') && topology.value && !panels.value.some((p) => panelSection(p.id) === s)) return 'painel';
    const known = ['painel', 'fluxo', 'filas', 'insights', 'mensagens', 'detalhes', 'conexoes'];
    return known.includes(s) || s.startsWith('panel:') ? s : 'painel';
});

const titles: Record<string, Key> = {
    painel: 'nav.dashboard',
    fluxo: 'tab.flow',
    filas: 'nav.lanes',
    insights: 'tab.insights',
    mensagens: 'tab.messages',
    detalhes: 'tab.details',
    conexoes: 'nav.connections',
};
const currentTitle = computed(() => {
    const s = current.value;
    if (s.startsWith('panel:')) return panels.value.find((p) => panelSection(p.id) === s)?.title ?? '';
    return t(titles[s] ?? 'nav.dashboard');
});

function navigate(next: string): void {
    go(next);
    navOpen.value = false;
}

function pickConnection(event: Event): void {
    const id = Number((event.target as HTMLSelectElement).value);
    if (Number.isFinite(id)) setActive(id);
}

// `selected` is a reactive proxy, so compare by value, never by identity.
const stillOnMessage = (id: string): boolean => selected.value?.kind === 'message' && selected.value.id === id;

async function select(s: Selection): Promise<void> {
    selected.value = s;
    navigate('detalhes');
    if (s.kind !== 'message' || apiBase.value === null) return;

    messageDetail.value = null;
    try {
        const result = await request<MessageDetail & { message?: string }>('GET', `${apiBase.value}/messages/${s.id}`);
        if (!stillOnMessage(s.id)) return; // the user moved on while it loaded
        messageDetail.value = result.ok ? result.data : { error: result.data?.message ?? `HTTP ${result.status}` };
    } catch (e) {
        if (stillOnMessage(s.id)) messageDetail.value = { error: e instanceof Error ? e.message : String(e) };
    }
}

// --- polling
async function loadTopology(): Promise<void> {
    const base = apiBase.value;
    if (base === null || paused.value || document.hidden || busyTopology) return;
    busyTopology = true;
    try {
        const result = await request<Topology & { message?: string }>('GET', `${base}/topology`);
        if (base !== apiBase.value || result.status === 401) return;
        if (!result.ok) throw new Error(result.data?.message ?? `HTTP ${result.status}`);
        topology.value = result.data;
        lastUpdate.value = Date.now();
        error.value = '';
    } catch (e) {
        if (base === apiBase.value) error.value = `${t('error')}: ${e instanceof Error ? e.message : String(e)}`;
    } finally {
        busyTopology = false;
    }
}

async function loadEvents(): Promise<void> {
    if (paused.value || document.hidden || busyEvents) return;
    busyEvents = true;
    try {
        await poll();
    } catch {
        /* the topology poll already reports connection trouble */
    } finally {
        busyEvents = false;
    }
}

const broker = computed(() => topology.value?.broker ?? null);
const host = computed(() => {
    const url = active.value?.url;
    if (!url) return '';
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
});

const liveTone = computed(() => (paused.value ? 'paused' : error.value ? 'down' : lastUpdate.value ? 'on' : 'waiting'));
const liveText = computed(() => {
    if (paused.value) return t('live.paused');
    if (error.value) return t('live.down');
    if (lastUpdate.value === null) return t('loading');
    return t('live.updated', { ago: relativeTime(lastUpdate.value, Math.max(now.value, lastUpdate.value)) });
});

const tapState = computed(() => {
    const s = tap.value;
    if (!s) return null;
    if (!s.enabled) return { tone: 'muted', text: t('tap.disabled') };
    if (!s.active) return { tone: 'warn', text: `${t('tap.off')}${s.error ? `: ${s.error}` : ''}` };
    return { tone: 'ok', text: `${t('tap.active')} · ${s.tracing ? t('tap.trace-on') : t('tap.trace-off')}` };
});

const healthTone = computed(() => (active.value?.health?.ok === true ? 'ok' : active.value?.health?.ok === false ? 'down' : 'unknown'));
const themeLabel = computed(() => {
    void theme.value; // re-evaluate when the theme changes
    return effectiveTheme() === 'dark' ? t('theme.to-light') : t('theme.to-dark');
});
const themeIcon = computed(() => {
    void theme.value;
    return effectiveTheme() === 'dark' ? 'sun' : 'moon';
});

watch(canvas, (el) => {
    stopPanning?.();
    stopPanning = el ? enablePanning(el) : undefined;
});

let stopHash: (() => void) | undefined;
onMounted(() => {
    applyTheme();
    stopHash = watchHash();
    void loadBrokers();
    timers = [
        setInterval(() => void loadTopology(), 2000),
        setInterval(() => void loadEvents(), 1000),
        // health of every connection, for the dots in the menu and the connections screen
        setInterval(() => !document.hidden && void loadBrokers(), 15_000),
    ];
});
onUnmounted(() => {
    timers.forEach(clearInterval);
    stopPanning?.();
    stopHash?.();
});
</script>

<template>
    <Head :title="currentTitle ? `${currentTitle} · Warren` : 'Warren'" />
    <div class="shell" :class="{ 'nav-open': navOpen, 'nav-collapsed': navCollapsed }">
        <aside class="nav" :aria-label="t('nav.main')">
            <div class="brand">
                <Logo :size="26" />
                <span class="brand-name nav-label">Warren</span>
                <button type="button" class="icon-btn nav-close" :aria-label="t('nav.close')" @click="navOpen = false"><Icon name="x" /></button>
            </div>

            <div class="switcher" :class="{ empty: !brokers.length }">
                <button
                    type="button"
                    class="switcher-compact"
                    :title="active ? `${active.name} · ${t(`health.${healthTone}` as Key)}` : t('connection.label')"
                    :aria-label="t('nav.expand')"
                    @click="toggleNav"
                ><Icon name="server" /><span class="status-dot" :class="healthTone"></span></button>
                <template v-if="brokers.length">
                    <span class="switcher-icon" aria-hidden="true"><Icon name="server" :size="18" /></span>
                    <span class="switcher-text nav-label">
                        <b>{{ active?.name ?? t('connection.label') }}</b>
                        <small><span class="health-text" :class="healthTone">{{ t(`health.${healthTone}` as Key) }}</span><template v-if="host"> · {{ host }}</template></small>
                    </span>
                    <Icon name="chevron" :size="16" class="switcher-chevron nav-label" />
                    <select id="connection" class="switcher-select" :aria-label="t('connection.label')" :value="activeId ?? ''" @change="pickConnection">
                        <option v-for="b in brokers" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                </template>
                <span v-else class="switcher-text nav-label"><small>{{ brokersLoaded ? t('connection.none') : t('loading') }}</small></span>
            </div>

            <nav class="nav-list">
                <span class="nav-group"><span class="nav-label">{{ t('nav.monitor') }}</span></span>
                <a
                    v-for="item in mainNav"
                    :key="item.section"
                    :href="`#/${item.section}`"
                    :class="{ active: current === item.section, disabled: noConnection }"
                    :aria-current="current === item.section ? 'page' : undefined"
                    :aria-disabled="noConnection || undefined"
                    :title="navCollapsed ? item.label : undefined"
                    @click.prevent="!noConnection && navigate(item.section)"
                ><Icon :name="item.icon" /><span class="nav-label">{{ item.label }}</span></a>

                <template v-if="panelNav.length">
                    <span class="nav-group"><span class="nav-label">{{ t('nav.views') }}</span></span>
                    <a
                        v-for="item in panelNav"
                        :key="item.section"
                        :href="`#/painel/${item.section.slice(6)}`"
                        :title="navCollapsed ? item.label : item.title"
                        :class="{ active: current === item.section }"
                        :aria-current="current === item.section ? 'page' : undefined"
                        @click.prevent="navigate(item.section)"
                    ><Icon :name="item.icon" /><span class="nav-label">{{ item.label }}</span></a>
                </template>

                <span class="nav-spacer"></span>
                <a
                    href="#/conexoes"
                    :class="{ active: current === 'conexoes' }"
                    :aria-current="current === 'conexoes' ? 'page' : undefined"
                    :title="navCollapsed ? t('nav.connections') : undefined"
                    @click.prevent="navigate('conexoes')"
                ><Icon name="plug" /><span class="nav-label">{{ t('nav.connections') }}</span></a>
            </nav>

            <div class="nav-foot">
                <div v-if="auth.enabled && auth.user" class="user">
                    <span class="avatar" aria-hidden="true" :title="navCollapsed ? `${auth.user.name} (${auth.user.email})` : undefined">{{ auth.user.name.slice(0, 1).toUpperCase() }}</span>
                    <span class="user-text nav-label">
                        <b class="user-name">{{ auth.user.name }}</b>
                        <small>{{ auth.user.email }}</small>
                    </span>
                    <button type="button" class="icon-btn logout" :aria-label="t('logout')" :title="t('logout')" @click="router.post('/logout')">
                        <Icon name="logout" :size="18" />
                    </button>
                </div>
                <div class="foot-actions">
                    <button type="button" class="nav-button" :aria-label="themeLabel" :title="themeLabel" @click="toggleTheme">
                        <Icon :name="themeIcon" :size="18" /><span class="nav-label">{{ themeLabel }}</span>
                    </button>
                    <button
                        type="button"
                        class="icon-btn nav-collapse"
                        :aria-label="navCollapsed ? t('nav.expand') : t('nav.collapse')"
                        :title="navCollapsed ? t('nav.expand') : t('nav.collapse')"
                        :aria-expanded="!navCollapsed"
                        @click="toggleNav"
                    ><Icon name="sidebar" :size="18" /></button>
                </div>
            </div>
        </aside>
        <div class="nav-scrim" aria-hidden="true" @click="navOpen = false"></div>

        <div class="main">
            <header class="topbar">
                <button type="button" class="icon-btn nav-toggle" :aria-label="t('nav.open')" :aria-expanded="navOpen" @click="navOpen = true"><Icon name="menu" /></button>
                <div class="topbar-title">
                    <div class="crumbs">
                        <span v-if="active && current !== 'conexoes'" class="crumb">{{ active.name }}</span>
                        <span v-if="active && current !== 'conexoes'" class="crumb-sep" aria-hidden="true">/</span>
                        <h1>{{ currentTitle }}</h1>
                    </div>
                    <div v-if="broker && current !== 'conexoes'" class="topbar-meta">
                        <span>RabbitMQ {{ broker.version }}</span>
                        <span>vhost <code>{{ broker.vhost }}</code></span>
                        <span v-if="host">{{ host }}</span>
                    </div>
                </div>
                <template v-if="current !== 'conexoes' && active">
                    <div class="status-group" role="status">
                        <span class="live" :class="liveTone">
                            <i class="live-mark" aria-hidden="true"></i>{{ liveText }}
                        </span>
                        <span v-if="tapState" class="tap-text" :class="tapState.tone">{{ tapState.text }}</span>
                    </div>
                    <button type="button" class="ghost pause-btn" :aria-pressed="paused" @click="paused = !paused">
                        <Icon :name="paused ? 'play' : 'pause'" :size="16" />{{ paused ? t('resume') : t('pause') }}
                    </button>
                </template>
            </header>

            <div v-if="error && current !== 'conexoes'" class="banner danger" role="alert">{{ error }}</div>
            <div v-else-if="tap && !tap.active && tap.enabled && current !== 'conexoes'" class="banner warn">{{ t('tap.hint') }}</div>

            <main class="content">
                <div v-if="current === 'painel'" class="scroll-area">
                    <Dashboard v-if="topology || error" :topology="topology" @select="select" />
                    <RabbitLoader v-else />
                </div>

                <section v-else-if="current === 'fluxo'" class="pane with-side">
                    <aside v-if="instancesOpen" class="side" :aria-label="t('instances')">
                        <InstanceList
                            :stats="stats"
                            :selected="ticked"
                            @toggle="toggleInstance"
                            @clear="setTicked(new Set())"
                            @top="tickBusiest"
                            @details="(key) => select({ kind: 'producer', id: key })"
                        />
                    </aside>
                    <div class="pane-main">
                        <div class="toolbar">
                            <button type="button" class="ghost" :aria-pressed="instancesOpen" @click="toggleInstances">
                                <Icon name="users" :size="16" />{{ t('instances') }}<span v-if="ticked.size" class="count">{{ ticked.size }}</span>
                            </button>
                            <label class="search">
                                <Icon name="search" :size="16" />
                                <input v-model="query" type="search" :placeholder="t('search')" :aria-label="t('search')" />
                            </label>
                            <label class="check"><input v-model="showKeys" type="checkbox" /> {{ t('show-keys') }}</label>
                        </div>
                        <div ref="canvas" class="canvas" :title="t('pan.hint')">
                            <Diagram
                                v-if="topology"
                                :topology="topology"
                                :query="query"
                                :show-keys="showKeys"
                                :selected="selected"
                                :stats="stats"
                                :messages="visible"
                                :estimates="estimates"
                                :ticked="ticked"
                                @select="select"
                            />
                            <RabbitLoader v-else-if="!error" />
                        </div>
                    </div>
                </section>

                <div v-else-if="current === 'filas'" class="scroll-area">
                    <QueueLanes v-if="topology" :topology="topology" :estimates="estimates" @open="(id) => select({ kind: 'message', id: String(id) })" />
                    <RabbitLoader v-else-if="!error" />
                </div>

                <div v-else-if="current === 'insights'" class="scroll-area">
                    <Insights v-if="topology" :topology="topology" :stats="stats" :messages="allMessages" :now="now" @select="select" />
                    <RabbitLoader v-else-if="!error" />
                </div>

                <template v-else-if="current.startsWith('panel:')">
                    <div v-for="p in panels" v-show="current === panelSection(p.id)" :key="p.id" class="scroll-area">
                        <Panel :panel="p" :active="current === panelSection(p.id)" :now="now" @select="(id) => select({ kind: 'message', id: String(id) })" />
                    </div>
                    <div v-if="!topology" class="scroll-area"><RabbitLoader v-if="!error" /></div>
                </template>

                <section v-else-if="current === 'mensagens'" class="pane with-side">
                    <aside v-if="instancesOpen" class="side" :aria-label="t('instances')">
                        <InstanceList
                            :stats="stats"
                            :selected="ticked"
                            @toggle="toggleInstance"
                            @clear="setTicked(new Set())"
                            @top="tickBusiest"
                            @details="(key) => select({ kind: 'producer', id: key })"
                        />
                    </aside>
                    <div class="pane-main">
                        <div class="toolbar">
                            <button type="button" class="ghost" :aria-pressed="instancesOpen" @click="toggleInstances">
                                <Icon name="users" :size="16" />{{ t('instances') }}<span v-if="ticked.size" class="count">{{ ticked.size }}</span>
                            </button>
                        </div>
                        <MessageList
                            v-model:queue="queueFilter"
                            :messages="visible"
                            :estimates="estimates"
                            :selected-id="selected?.kind === 'message' ? Number(selected.id) : null"
                            :focused="ticked.size > 0"
                            :focus-count="ticked.size"
                            :queues="topology?.queues ?? []"
                            :broker-version="topology?.broker.version"
                            @select="(id) => select({ kind: 'message', id: String(id) })"
                        />
                    </div>
                </section>

                <div v-else-if="current === 'detalhes'" class="scroll-area">
                    <Details
                        v-if="topology"
                        :topology="topology"
                        :selected="selected"
                        :stats="stats"
                        :messages="allMessages"
                        :estimates="estimates"
                        :message="messageDetail"
                        :ticked="ticked"
                        @select="select"
                        @toggle="toggleInstance"
                    />
                    <RabbitLoader v-else-if="!error" />
                </div>

                <div v-else-if="current === 'conexoes'" class="scroll-area">
                    <Connections @open="navigate('painel')" />
                </div>
            </main>
        </div>
    </div>
</template>
