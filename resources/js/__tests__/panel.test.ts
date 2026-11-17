import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PanelData, PanelItem, Topology as TopologyData } from '../types';
import { goTo, installApi, navLink, openWorkspace, resetApp, settle, TAP_UP } from './harness';
import { pageProps, router } from './inertia-mock';
import { topology } from './fixtures';

vi.mock('@inertiajs/vue3', async () => (await import('./inertia-mock')).inertiaModule());

const queuedAt = Date.parse('2026-11-17T14:03:22');
const item = (over: Partial<PanelItem>): PanelItem => ({
    id: 1, queuedAt, status: 'sucesso', title: 'nota fiscal: geração de PDF', text: 'Terminou com sucesso: geração de PDF (nota fiscal) da loja Acme.',
    facts: [{ label: 'Arquivo', value: 'notas_lote_95564.zip' }], instance: 'Acme', routingKey: 'loja.faturamento.x.acme.pdf.gerar.success', exchange: 'faturamento_resultados', queue: 'resultados_faturamento', ...over,
});

const withPanels: TopologyData = {
    ...topology,
    panels: [
        { id: 'resultados-faturamento', title: 'Resultados do faturamento', kind: 'resultados', description: 'Avisos do faturamento.', queues: ['resultados_faturamento'] },
        { id: 'erros-pdf', title: 'Erros do gerador de PDF', kind: 'erros', description: 'Falhas.', queues: ['falhas'] },
    ],
};

let panels: Record<string, PanelData>;
let tap = TAP_UP;

describe('painéis especiais', () => {
    beforeEach(() => {
        resetApp();
        pageProps.value = { locale: 'pt-BR', auth: { enabled: false, user: null } };
        tap = TAP_UP;
        panels = {
            'resultados-faturamento': {
                panel: withPanels.panels[0],
                queues: [{ name: 'resultados_faturamento', label: 'Resultados do faturamento', ready: 1420, unacked: 0, consumers: 0, type: 'quorum', ttlMs: 86_400_000 }],
                items: [
                    item({ id: 3, status: 'erro', text: 'Deu erro, mas o sistema vai tentar de novo em 30 s: geração de PDF.', instance: 'Pied Piper', facts: [{ label: 'Motivo (técnico)', value: 'Timeout' }] }),
                    item({ id: 2 }),
                    item({ id: 1, text: 'Terminou com sucesso: folhas de resposta da loja Globex.', instance: 'Globex' }),
                ],
                total: 3, counts: { sucesso: 2, erro: 1 }, tap,
            },
            'erros-pdf': {
                panel: withPanels.panels[1],
                queues: [{ name: 'falhas', label: 'Erros do gerador de PDF', ready: 616, unacked: 0, consumers: 0, type: 'quorum', ttlMs: null }],
                items: [], total: 0, counts: {}, tap,
            },
        };
    });
    afterEach(() => vi.unstubAllGlobals());

    const events = () => ({ topology: withPanels, panels });

    it('cria no menu um item para cada painel do catálogo, com o nome dado a ele', async () => {
        installApi(events());
        const w = await openWorkspace();

        expect(w.findAll('.nav-list a').map((a) => a.text())).toEqual(['Painel', 'Fluxo', 'Filas', 'Insights', 'Mensagens', 'Detalhes', 'Resultados do faturamento', 'Erros do gerador de PDF', 'Conexões']);
        expect(w.findAll('.nav-group').map((g) => g.text())).toEqual(['Monitorar', 'Visões do catálogo']);
        w.unmount();
    });

    it('não cria itens extras quando o catálogo não define painéis', async () => {
        installApi({ topology });
        const w = await openWorkspace();

        expect(w.findAll('.nav-group').map((g) => g.text())).toEqual(['Monitorar']);
        w.unmount();
    });

    it('mostra cada mensagem como uma frase, com a hora em que foi colocada na fila', async () => {
        installApi(events());
        const w = await openWorkspace();
        await goTo(w, 'Resultados do faturamento');
        const rows = w.findAll('.events li');

        expect(w.find('.topbar h1').text()).toBe('Resultados do faturamento');
        expect(rows).toHaveLength(3);
        expect(rows[1].find('.event-text').text()).toBe('Terminou com sucesso: geração de PDF (nota fiscal) da loja Acme.');
        const time = rows[1].find('.event-time');
        expect(time.text()).toContain('Colocada na fila em');
        expect(time.find('time').text()).toMatch(/17\/11\/2026/);
        expect(time.find('time').text()).toContain('14:03:22');
        expect(time.find('time').attributes('datetime')).toBe(new Date(queuedAt).toISOString());
        expect(rows[1].find('.facts').text()).toContain('notas_lote_95564.zip');
        w.unmount();
    });

    it('explica o que a fila é: tamanho, sem consumidor de propósito, quanto tempo guarda e por que não é aberta', async () => {
        installApi(events());
        const w = await openWorkspace();
        await goTo(w, 'Resultados do faturamento');
        const text = w.find('.panel').text();

        expect(text).toContain('1.420');
        expect(text).toContain('sem consumidor, de propósito');
        expect(text).toContain('guardadas por 24 h');
        expect(text).toContain('nunca é aberta');
        w.unmount();
    });

    it('filtra por situação, por loja e por texto', async () => {
        installApi(events());
        const w = await openWorkspace();
        await goTo(w, 'Resultados do faturamento');
        const filter = (label: string) => w.findAll('.panel .toolbar button').find((b) => b.text() === label)!;

        await filter('Erro').trigger('click');
        expect(w.findAll('.events li')).toHaveLength(1);
        expect(w.find('.events li').classes()).toContain('st-erro');

        await filter('Todas').trigger('click');
        await w.find('.panel select').setValue('Globex');
        expect(w.findAll('.events li')).toHaveLength(1);

        await w.find('.panel select').setValue('');
        await w.find('.panel input[type=search]').setValue('timeout');
        expect(w.find('.events li').text()).toContain('Pied Piper');
        w.unmount();
    });

    it('abre os detalhes técnicos da mensagem', async () => {
        installApi({ ...events(), messages: [] });
        const w = await openWorkspace();
        await goTo(w, 'Resultados do faturamento');
        await w.find('.events li .link-btn').trigger('click');
        await settle();

        expect(navLink(w, 'Detalhes').attributes('aria-current')).toBe('page');
        expect(w.find('.details').text()).toContain('"hello": "world"');
        w.unmount();
    });

    it('no painel de erros avisa das mensagens antigas e de que falta ligar o rastreio', async () => {
        tap = { ...TAP_UP, tracing: false };
        panels['erros-pdf'].tap = tap;
        installApi({ ...events(), tap });
        const w = await openWorkspace();
        await goTo(w, 'Erros do gerador de PDF');
        const notice = w.find('.notice-inline').text();

        expect(notice).toContain('616 mensagens');
        expect(notice).toContain('anteriores à escuta');
        expect(notice).toContain('rabbitmqctl trace_on');
        expect(w.find('.empty').text()).toContain('Nenhuma mensagem');
        w.unmount();
    });

    it('avisa quando a escuta não está rodando', async () => {
        tap = { ...TAP_UP, active: false };
        panels['resultados-faturamento'].tap = tap;
        installApi({ ...events(), tap });
        const w = await openWorkspace();
        await goTo(w, 'Resultados do faturamento');

        expect(w.find('.notice-inline').text()).toContain('A escuta não está rodando');
        w.unmount();
    });

    it('volta ao login quando a sessão acaba', async () => {
        installApi({ ...events(), status: { '/api/b/1/paineis': 401 } });
        const w = await openWorkspace();
        await goTo(w, 'Resultados do faturamento');

        expect(router.visit).toHaveBeenCalledWith('/login');
        w.unmount();
    });

    it('um painel que não existe no catálogo cai no painel geral', async () => {
        installApi(events());
        const w = await openWorkspace('panel:nao-existe');

        expect(w.find('.topbar h1').text()).toBe('Painel');
        w.unmount();
    });
});
