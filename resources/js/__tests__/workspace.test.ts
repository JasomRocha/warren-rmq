import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { estimateQueues } from '../lib/estimate';
import type { Topology as TopologyData, Worker } from '../types';
import { goTo, installApi, navLink, openWorkspace, resetApp, settle, TAP_UP, broker } from './harness';
import { pageProps, router } from './inertia-mock';
import { message, topology } from './fixtures';

vi.mock('@inertiajs/vue3', async () => (await import('./inertia-mock')).inertiaModule());

const nodes = (w: Awaited<ReturnType<typeof openWorkspace>>, kind: string) => w.findAll(`.node.${kind} .node-name`).map((n) => n.text());
const now = Date.now();
const messages = [
    message({ instance: 'acme', exchange: 'events', routingKey: 'orders.eu.created', queues: ['orders', 'orders.created'], ts: now - 5000 }),
    message({ instance: 'globex', exchange: 'jobs', routingKey: 'pdf', queues: ['pdf'], ts: now - 4000, job: 'render' }),
];

describe('Warren: estrutura e navegação', () => {
    beforeEach(() => {
        resetApp();
        pageProps.value = { locale: 'pt-BR', auth: { enabled: false, user: null } };
        installApi({ messages });
    });
    afterEach(() => vi.unstubAllGlobals());

    it('tem o menu principal em português, com o painel como primeira tela', async () => {
        const w = await openWorkspace();

        expect(w.findAll('.nav-list a').map((a) => a.text())).toEqual(['Painel', 'Fluxo', 'Filas', 'Insights', 'Mensagens', 'Detalhes', 'Conexões']);
        expect(navLink(w, 'Painel').attributes('aria-current')).toBe('page');
        expect(w.find('.brand').text()).toContain('Warren');
        expect(w.find('.topbar h1').text()).toBe('Painel');
        w.unmount();
    });

    it('trocar de tela muda o título e o endereço, e marca o item atual', async () => {
        const w = await openWorkspace();
        await goTo(w, 'Insights');

        expect(w.find('.topbar h1').text()).toBe('Insights');
        expect(navLink(w, 'Insights').attributes('aria-current')).toBe('page');
        expect(navLink(w, 'Painel').attributes('aria-current')).toBeUndefined();
        expect(location.hash).toBe('#/insights');
        w.unmount();
    });

    it('abre direto na tela do endereço (um link compartilhado)', async () => {
        const w = await openWorkspace('mensagens');
        expect(w.find('.topbar h1').text()).toBe('Mensagens');
        w.unmount();
    });

    it('um endereço que não existe cai no painel', async () => {
        const w = await openWorkspace('qualquer-coisa');
        expect(w.find('.topbar h1').text()).toBe('Painel');
        w.unmount();
    });

    it('mostra a conexão em uso e a versão do RabbitMQ na barra de cima', async () => {
        const w = await openWorkspace();

        expect(w.find('#connection').element.querySelector('option')?.textContent).toBe('Produção');
        expect(w.find('.topbar').text()).toContain('RabbitMQ 4.0');
        expect(w.find('.topbar .tap-text').text()).toContain('Escuta ativa');
        expect(w.find('.topbar .crumb').text()).toBe('Produção');
        expect(w.find('.topbar .live').text()).toContain('Ao vivo');
        w.unmount();
    });

    it('pausar e retomar a atualização', async () => {
        const w = await openWorkspace();
        const button = () => w.find('.topbar .ghost');

        expect(button().text()).toContain('Pausar');
        await button().trigger('click');
        expect(button().text()).toContain('Retomar');
        expect(button().attributes('aria-pressed')).toBe('true');
        w.unmount();
    });

    it('o tema escuro liga e desliga, e fica lembrado', async () => {
        const w = await openWorkspace();

        await w.find('button[aria-label="Mudar para o tema escuro"]').trigger('click');
        expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
        expect(localStorage.getItem('warren.theme')).toBe('dark');

        await w.find('button[aria-label="Mudar para o tema claro"]').trigger('click');
        expect(document.documentElement.getAttribute('data-theme')).toBe('light');
        w.unmount();
    });

    it('o menu pode ser recolhido e a escolha fica lembrada', async () => {
        const w = await openWorkspace();
        expect(w.find('.shell').classes()).not.toContain('nav-collapsed');

        await w.find('.nav-collapse').trigger('click');
        expect(w.find('.shell').classes()).toContain('nav-collapsed');
        expect(localStorage.getItem('warren.nav')).toBe('collapsed');
        w.unmount();
    });

    it('fala português por padrão e inglês quando pedido', async () => {
        pageProps.value = { auth: { enabled: false, user: null } };
        const pt = await openWorkspace();
        expect(pt.find('.nav-list').text()).toContain('Mensagens');
        pt.unmount();

        pageProps.value = { locale: 'en', auth: { enabled: false, user: null } };
        const en = await openWorkspace();
        expect(en.find('.nav-list').text()).toContain('Messages');
        en.unmount();
    });
});

describe('Warren: conexões e sessão', () => {
    beforeEach(() => {
        resetApp();
        pageProps.value = { locale: 'pt-BR', auth: { enabled: false, user: null } };
    });
    afterEach(() => vi.unstubAllGlobals());

    it('sem nenhuma conexão leva à tela de conexões e deixa o resto desativado', async () => {
        installApi({ brokers: [] });
        const w = await openWorkspace('fluxo');

        expect(w.find('.topbar h1').text()).toBe('Conexões');
        expect(navLink(w, 'Fluxo').attributes('aria-disabled')).toBe('true');
        expect(w.find('.empty-state').text()).toContain('Nenhuma conexão ainda');
        w.unmount();
    });

    it('trocar de conexão apaga o que estava na tela e carrega a nova', async () => {
        const api = installApi({
            brokers: [broker(), broker({ id: 2, name: 'Homologação' })],
            topology: (id) => (id === 2 ? { ...topology, queues: [{ ...topology.queues[0], name: 'so-na-homologacao' }], bindings: [] } : topology),
        });
        const w = await openWorkspace('fluxo');
        expect(nodes(w, 'queue')).toContain('pdf');

        await w.find('#connection').setValue('2');
        await settle();

        expect(api.urls()).toContain('GET /api/b/2/topology');
        expect(nodes(w, 'queue')).toEqual(['so-na-homologacao']);
        expect(localStorage.getItem('warren.broker')).toBe('2');
        w.unmount();
    });

    it('a escolha de instâncias é de cada conexão', async () => {
        installApi({ brokers: [broker(), broker({ id: 2, name: 'Outra' })], messages });
        localStorage.setItem('warren.instances.1', JSON.stringify(['acme']));
        const w = await openWorkspace('fluxo');
        expect(nodes(w, 'producer')).toEqual(['acme']);

        await w.find('#connection').setValue('2');
        await settle();
        expect(nodes(w, 'producer').sort()).toEqual(['acme', 'globex']); // nada marcado nessa conexão
        w.unmount();
    });

    it('a lista de instâncias começa fechada e abre pelo botão, que lembra a escolha', async () => {
        installApi({ messages });
        const w = await openWorkspace('fluxo');
        expect(w.find('.side').exists()).toBe(false);

        await w.find('.toolbar .ghost').trigger('click');
        expect(w.find('.side').exists()).toBe(true);
        expect(localStorage.getItem('warren.sidebar')).toBe('1');
        w.unmount();
    });

    it('mostra o coelho correndo enquanto a topologia não chegou e o diagrama depois', async () => {
        let release!: () => void;
        installApi({ holdTopology: new Promise<void>((r) => (release = r)) });
        const w = await openWorkspace('fluxo');

        expect(w.find('.rabbit-loader').exists()).toBe(true);
        expect(w.find('.node').exists()).toBe(false);

        release();
        await settle();
        expect(w.find('.rabbit-loader').exists()).toBe(false);
        expect(w.find('.node').exists()).toBe(true);
        w.unmount();
    });

    it('volta ao login quando a sessão acaba (401)', async () => {
        installApi({ status: { '/api/brokers': 401 } });
        const w = await openWorkspace();

        expect(router.visit).toHaveBeenCalledWith('/login');
        w.unmount();
    });

    it('mostra quem está conectado e sai pelo POST /logout; sem login ligado não há botão', async () => {
        installApi();
        pageProps.value = { locale: 'pt-BR', auth: { enabled: true, user: { name: 'Ana', email: 'ana@example.com' } } };
        const w = await openWorkspace();

        expect(w.find('.user').text()).toContain('Ana');
        await w.find('.logout').trigger('click');
        expect(router.post).toHaveBeenCalledWith('/logout');
        w.unmount();

        pageProps.value = { locale: 'pt-BR', auth: { enabled: false, user: null } };
        const open = await openWorkspace();
        expect(open.find('.logout').exists()).toBe(false);
        open.unmount();
    });

    it('avisa quando o RabbitMQ não responde, sem esconder a navegação', async () => {
        installApi({ status: { '/api/b/1/topology': 502 } });
        const w = await openWorkspace('fluxo');

        expect(w.find('.banner.danger').text()).toContain('Sem resposta do RabbitMQ');
        expect(w.find('.nav-list').exists()).toBe(true);
        w.unmount();
    });

    it('explica como iniciar a escuta quando ela não está rodando', async () => {
        installApi({ tap: { ...TAP_UP, active: false } });
        const w = await openWorkspace();

        expect(w.find('.banner.warn').text()).toContain('php artisan warren:tap');
        w.unmount();
    });
});

describe('Warren: fluxo, detalhes e nomes', () => {
    let current: TopologyData;
    beforeEach(() => {
        resetApp();
        localStorage.setItem('warren.sidebar', '1'); // the instance list starts closed; these tests use it
        pageProps.value = { locale: 'pt-BR', auth: { enabled: false, user: null } };
        const worker = (name: string, queues: string[], over: Partial<Worker> = {}): Worker => ({
            id: name, name, user: name.split('@')[0], host: name.split('@')[1], consumers: queues.length, activeConsumers: queues.length, prefetchMin: 1, prefetchMax: 1, manualAck: true, processingEstimate: 0,
            connections: [{ name: 'a', host: name.split('@')[1], port: 5001, node: 'rabbit@n1', consumers: queues.length }], nodes: ['rabbit@n1'],
            catalog: { name: null, service: null, description: null },
            queues: queues.map((queue) => ({ queue, consumers: 1, activeConsumers: 1, prefetchTotal: 1, manualAck: true, tags: [] })), ...over,
        });
        current = {
            ...topology,
            workers: [
                worker('app-pdf@10.0.0.2', ['pdf', 'orders'], { catalog: { name: 'Gerador de PDF · 10.0.0.2', service: 'Gerador de PDF', description: 'Converte HTML em PDF.' }, nodes: ['rabbit@n1', 'rabbit@n2'],
                    connections: [{ name: 'a', host: '10.0.0.2', port: 5001, node: 'rabbit@n1', consumers: 1 }, { name: 'b', host: '10.0.0.2', port: 5002, node: 'rabbit@n2', consumers: 1 }] }),
                worker('app-pdf@10.0.0.3', ['pdf'], { catalog: { name: 'Gerador de PDF · 10.0.0.3', service: 'Gerador de PDF', description: null } }),
            ],
        };
        const acme = { ...message({ instance: 'acme.empresa.com.br', exchange: 'events', queues: ['orders'] }), instanceName: 'Acme' };
        installApi({ topology: () => current, messages: [acme, ...messages] });
    });
    afterEach(() => vi.unstubAllGlobals());

    it('desenha as quatro colunas, com um cartão por processo de worker', async () => {
        const w = await openWorkspace('fluxo');

        expect(nodes(w, 'producer')).toContain('Acme');
        expect(nodes(w, 'queue')).toContain('pdf');
        expect(nodes(w, 'worker')).toEqual(['Gerador de PDF · 10.0.0.2', 'Gerador de PDF · 10.0.0.3']);
        w.unmount();
    });

    it('as routing keys das mensagens de um produtor só aparecem quando o mouse está nele; as dos binds ficam sempre inteiras', async () => {
        const many = ['a', 'b', 'c'].map((k) => ({ ...message({ instance: 'acme', exchange: 'events', routingKey: `orders.eu.${k}.created`, queues: ['orders'], ts: now - 1000 }), instanceName: null }));
        installApi({ topology: () => current, messages: many });
        const w = await openWorkspace('fluxo');
        const chips = () => w.findAll('.chip text').map((c) => c.text());

        expect(chips().filter((c) => c.startsWith('orders.eu.'))).toEqual([]); // the diagram stays clean
        expect(chips()).toContain('orders.#'); // a binding's key, whole

        await w.find('.node.producer').trigger('pointerenter');
        expect(chips().filter((c) => c.startsWith('orders.eu.')).sort()).toEqual(['orders.eu.a.created', 'orders.eu.b.created', 'orders.eu.c.created']);

        await w.find('.node.producer').trigger('pointerleave');
        expect(chips().filter((c) => c.startsWith('orders.eu.'))).toEqual([]);
        w.unmount();
    });

    it('o produtor e a lista de instâncias mostram "Acme", com a chave técnica na dica', async () => {
        const w = await openWorkspace('fluxo');

        expect(w.find('.node.producer .node-head').attributes('title')).toBe('Acme (acme.empresa.com.br)');
        expect(w.findAll('.instances .name').map((n) => n.text())).toContain('Acme');
        const acme = w.findAll('.instances label').find((l) => l.text().includes('Acme'))!;
        expect(acme.attributes('title')).toBe('Acme (acme.empresa.com.br)');

        await w.find('.instances input[type=search]').setValue('acme.empresa');
        expect(w.findAll('.instances li')).toHaveLength(1);
        w.unmount();
    });

    it('marcar uma instância isola o fluxo dela e a escolha fica salva', async () => {
        const w = await openWorkspace('fluxo');
        const globex = w.findAll('.instances li').find((l) => l.text().includes('globex'))!;
        await globex.find('input[type=checkbox]').setValue(true);

        expect(nodes(w, 'producer')).toEqual(['globex']);
        expect(nodes(w, 'queue')).toEqual(['pdf']);
        expect(JSON.parse(localStorage.getItem('warren.instances.1')!)).toEqual(['globex']);
        w.unmount();
    });

    it('a mesma escolha vale na tela de mensagens', async () => {
        localStorage.setItem('warren.instances.1', JSON.stringify(['globex']));
        const w = await openWorkspace('mensagens');

        expect(w.findAll('.messages tbody tr')).toHaveLength(1);
        w.unmount();
    });

    it('clicar numa linha de mensagem abre os detalhes com o corpo', async () => {
        const w = await openWorkspace('mensagens');
        await w.findAll('.messages tbody tr')[0].trigger('click');
        await settle();

        expect(navLink(w, 'Detalhes').attributes('aria-current')).toBe('page');
        expect(w.find('.details').text()).toContain('"hello": "world"');
        w.unmount();
    });

    it('clicar numa fila mostra os binds com a routing key completa', async () => {
        const w = await openWorkspace('fluxo');
        await w.findAll('.node.queue').find((n) => n.find('.node-name').text() === 'orders.created')!.trigger('click');
        await settle();
        const text = w.find('.details').text();

        expect(text).toContain('Binds de entrada');
        expect(text).toContain('orders.*.created');
        w.unmount();
    });

    it('clicar no worker abre a ficha com IP, nós do RabbitMQ, conexões e descrição', async () => {
        const w = await openWorkspace('fluxo');
        await w.find('.node.worker').trigger('click');
        await settle();
        const text = w.find('.details').text();

        expect(w.find('.details h2').text()).toBe('Gerador de PDF · 10.0.0.2');
        expect(text).toContain('Converte HTML em PDF.');
        expect(text).toContain('Endereço IP');
        expect(text).toContain('app-pdf@10.0.0.2');
        expect(text).toContain('rabbit@n1, rabbit@n2');
        expect(text).toContain('10.0.0.2:5001');
        expect(text).toContain('10.0.0.2:5002');
        w.unmount();
    });

    it('ao passar o mouse num worker só as conexões dele ficam em destaque', async () => {
        const w = await openWorkspace('fluxo');
        expect(w.findAll('.edge.dim')).toHaveLength(0);

        await w.find('.node.worker').trigger('pointerenter');
        expect(w.findAll('.edge.active')).toHaveLength(2);
        expect(w.findAll('.edge.dim').length).toBeGreaterThan(0);
        expect(w.findAll('.node.worker')[1].classes()).toContain('dimmed');

        await w.find('.node.worker').trigger('pointerleave');
        expect(w.findAll('.edge.dim')).toHaveLength(0);
        w.unmount();
    });

    it('mostra a fila com o nome do catálogo e o nome técnico embaixo', async () => {
        current = {
            ...current,
            categories: [{ key: 'pdf', name: 'Geração de pedidos', description: null, color: '#56b6f2', generic: false }],
            queues: current.queues.map((q) => (q.name === 'pdf' ? { ...q, catalog: { ...q.catalog, name: 'Geração de PDF dos pedidos', category: 'pdf', description: 'Renderiza as notas fiscais.' } } : q)),
        };
        const w = await openWorkspace('fluxo');
        const card = w.findAll('.node.queue').find((n) => n.text().includes('Geração de PDF dos pedidos'))!;

        expect(card.find('.technical').text()).toBe('pdf');
        await card.trigger('click');
        await settle();
        expect(w.find('.details').text()).toContain('Renderiza as notas fiscais.');
        w.unmount();
    });

    it('a aba de insights mostra alertas e o ranking de instâncias', async () => {
        current = { ...current, queues: [...current.queues, { ...current.queues[0], name: 'sem.consumidor', ready: 7, unacked: 0, consumers: 0 }] };
        const w = await openWorkspace('insights');
        const text = w.find('.insights').text();

        expect(text).toContain('Precisa de atenção');
        expect(text).toContain('sem.consumidor: 7 mensagens esperando e ninguém consumindo');
        expect(w.findAll('.insights tbody tr').some((r) => r.text().includes('Acme'))).toBe(true);

        await w.find('.alerts a').trigger('click');
        await settle();
        expect(w.find('.details h2').text()).toBe('sem.consumidor');
        w.unmount();
    });

    it('o rascunho de estimativa das filas continua consistente com as mensagens', () => {
        expect(estimateQueues(messages, topology.queues).get('pdf')?.messages).toHaveLength(1);
    });
});
