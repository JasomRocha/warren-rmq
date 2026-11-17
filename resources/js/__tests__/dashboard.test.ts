import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installApi, openWorkspace, resetApp, settle } from './harness';
import { pageProps } from './inertia-mock';

vi.mock('@inertiajs/vue3', async () => (await import('./inertia-mock')).inertiaModule());

const t0 = Math.floor(Date.now() / 1000) - 3000;
const sample = (i: number, ready: number) => ({ t: t0 + i * 600, ready, unacked: 2 + i, consumers: 6, rateIn: 1.5, rateDeliver: 1.2, rateAck: 1.1, areas: { pedidos: ready, docs: 3 } });
const metrics = {
    range: '1h', from: t0, to: t0 + 3000,
    samples: [sample(0, 10), sample(1, 30), sample(2, 25), sample(3, 60), sample(4, 40)],
    areas: [{ key: 'pedidos', name: 'Pedidos', description: null, color: '#56b6f2', generic: false }, { key: 'docs', name: 'Documentos', description: null, color: null, generic: false }],
    instances: [{ key: 'acme.x', name: 'Acme', count: 12 }, { key: 'globex.x', name: 'Globex', count: 4 }, { key: '', name: null, count: 2 }],
    published: [0, 1, 2, 3, 4, 5].map((i) => ({ t: t0 + i * 600, count: i * 3 })),
};

describe('Painel (dashboard)', () => {
    beforeEach(() => {
        resetApp();
        pageProps.value = { locale: 'pt-BR', auth: { enabled: false, user: null } };
    });
    afterEach(() => vi.unstubAllGlobals());

    it('mostra os indicadores do período e os gráficos de vazão, acúmulo e espera', async () => {
        installApi({ metrics });
        const w = await openWorkspace('painel');
        const strip = w.find('.stat-strip').text();

        expect(w.findAll('.stat')).toHaveLength(5);
        expect(strip).toContain('Processadas');
        expect(strip).toContain('Espera estimada agora');
        expect(strip).toContain('Reentregas');
        expect(w.find('.stat-processed .stat-value').text()).toBe('2.640'); // 1,1/s durante 2.400 s (5 pontos, 600 s entre eles)
        expect(w.findAll('.line-chart').length).toBeGreaterThanOrEqual(3); // vazão, acúmulo, espera
        expect(w.find('.focal .block-foot').text()).toContain('chegaram 3.600 mensagens e foram processadas 2.640');
        w.unmount();
    });

    it('conta só as filas com consumidor: as de resultados não viram acúmulo', async () => {
        installApi({ metrics });
        const w = await openWorkspace('painel');
        const rows = w.findAll('.data-table tbody tr').map((r) => r.find('td').text());

        expect(rows).toEqual(['orders', 'pdf']); // orders.created e audit não têm consumidor
        expect(w.find('.stat-backlog .stat-value').text()).toBe('6'); // 3 + 2 de orders, 1 de pdf
        w.unmount();
    });

    it('a tabela de filas mostra a situação em palavras e abre a fila ao clicar', async () => {
        installApi({ metrics });
        const w = await openWorkspace('painel');
        const orders = w.findAll('.data-table tbody tr')[0];

        expect(orders.find('.trend').text()).toBe('Parada'); // tem mensagens e nada sai
        expect(orders.findAll('td')[6].text()).toBe('∞');
        await orders.trigger('click');
        await settle();
        expect(w.find('.details h2').text()).toBe('orders');
        w.unmount();
    });

    it('lista quem mais enviou com o nome amigável, e as mensagens sem instância à parte', async () => {
        installApi({ metrics });
        const w = await openWorkspace('painel');
        const labels = w.findAll('.bar-label').map((l) => l.text());

        expect(labels).toContain('Acme');
        expect(labels).toContain('Globex');
        expect(labels.indexOf('Acme')).toBeLessThan(labels.indexOf('Globex')); // os mais ativos primeiro
        w.unmount();
    });

    it('troca o período e pede o histórico daquele período', async () => {
        const api = installApi({ metrics });
        const w = await openWorkspace('painel');
        expect(api.urls()).toContain('GET /api/b/1/metrics?range=1h');

        await w.findAll('.segmented button')[2].trigger('click');
        await settle();

        expect(api.urls().some((u) => u.includes('/metrics?range=24h'))).toBe(true);
        expect(w.findAll('.segmented button')[2].classes()).toContain('active');
        w.unmount();
    });

    it('antes do primeiro ponto do histórico explica que está coletando, em vez de um gráfico vazio', async () => {
        installApi({ metrics: { ...metrics, samples: [], published: [], instances: [] } });
        const w = await openWorkspace('painel');

        expect(w.findAll('.empty-chart').length).toBeGreaterThan(0);
        expect(w.find('.line-chart').exists()).toBe(false);
        w.unmount();
    });

    it('mostra o coelho correndo até a primeira leitura chegar, e o painel depois', async () => {
        let release!: () => void;
        installApi({ metrics, holdTopology: new Promise<void>((r) => (release = r)) });
        const w = await openWorkspace('painel');

        expect(w.find('.rabbit-loader[role="status"]').text()).toBe('Carregando…');
        expect(w.find('.stat-strip').exists()).toBe(false);
        release();
        await settle();
        expect(w.find('.rabbit-loader').exists()).toBe(false);
        expect(w.find('.stat-strip').exists()).toBe(true);
        w.unmount();
    });

    it('avisa quando o histórico não pôde ser lido, sem derrubar o resto', async () => {
        installApi({ status: { '/api/b/1/metrics': 500 } });
        const w = await openWorkspace('painel');

        expect(w.find('.banner.warn[role="alert"]').exists()).toBe(true);
        expect(w.find('.stat-strip').exists()).toBe(true);
        w.unmount();
    });

    it('ao trocar o período mantém os gráficos (esmaecidos) em vez de piscar esqueletos', async () => {
        installApi({ metrics });
        const w = await openWorkspace('painel');
        const charts = w.findAll('.line-chart').length;

        await w.findAll('.segmented button')[1].trigger('click');
        expect(w.find('.dashboard').classes()).toContain('refetching');
        expect(w.findAll('.line-chart')).toHaveLength(charts);
        expect(w.find('.chart-skeleton').exists()).toBe(false);
        await settle();
        expect(w.find('.dashboard').classes()).not.toContain('refetching');
        w.unmount();
    });

    it('cada gráfico tem uma tabela com os mesmos valores, para ler sem passar o mouse', async () => {
        installApi({ metrics });
        const w = await openWorkspace('painel');
        const table = w.find('.focal .chart-table');

        expect(table.find('summary').text()).toBe('Ver como tabela');
        expect(table.findAll('thead th').map((th) => th.text())).toEqual(['Horário', 'Processadas', 'Entrada']);
        expect(table.findAll('tbody tr')).toHaveLength(5);
        w.unmount();
    });

    it('a cor de cada área segue a ordem do catálogo, não o ranking; além de seis, o resto vira "Outras áreas"', async () => {
        const keys = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];
        const areas = keys.map((key) => ({ key, name: key.toUpperCase(), description: null, color: null, generic: false }));
        const many = { ...metrics, areas, samples: metrics.samples.map((s, i) => ({ ...s, areas: Object.fromEntries(keys.map((k, j) => [k, (j + 1) * (i + 1)])) })) };
        installApi({ metrics: many });
        const w = await openWorkspace('painel');
        const legend = w.findAll('.chart-legend.areas').at(-1)!.findAll('li');

        expect(legend.map((l) => l.text())).toEqual(['A', 'B', 'C', 'D', 'E', 'Outras áreas']);
        expect(legend[0].find('i').attributes('style')).toContain('var(--series-1)');
        w.unmount();
    });

    it('as barras de quem publica usam uma cor só: o comprimento já diz quanto', async () => {
        installApi({ metrics });
        const w = await openWorkspace('painel');
        const colors = new Set(w.findAll('.bar-track i').map((i) => i.attributes('style')?.match(/background: ([^;]+)/)?.[1]));

        expect(colors.size).toBe(1);
        w.unmount();
    });

    it('as explicações de método ficam num "?": aparecem no foco ou no toque e somem com Esc', async () => {
        installApi({ metrics });
        const w = await openWorkspace('painel');
        const wait = w.find('.stat-wait .info-tip-button');

        expect(w.text()).not.toContain('Lei de Little');
        await wait.trigger('focus');
        expect(w.find('.stat-wait [role="tooltip"]').text()).toContain('Lei de Little');
        expect(wait.attributes('aria-describedby')).toBe(w.find('.stat-wait [role="tooltip"]').attributes('id'));
        await wait.trigger('keydown', { key: 'Escape' });
        expect(w.find('.stat-wait [role="tooltip"]').exists()).toBe(false);

        const how = w.find('.filter-row .info-tip-button');
        expect(how.text()).toBe('Como calculamos');
        await how.trigger('click');
        expect(w.find('.filter-row [role="tooltip"]').text()).toContain('Contam só as filas com consumidor');
        w.unmount();
    });
});
