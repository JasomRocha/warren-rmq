import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { broker, installApi, openWorkspace, resetApp, settle } from './harness';
import { pageProps } from './inertia-mock';

vi.mock('@inertiajs/vue3', async () => (await import('./inertia-mock')).inertiaModule());

const field = (w: Awaited<ReturnType<typeof openWorkspace>>, name: string) => w.find(`.conn-form [name="${name}"]`);
const button = (w: Awaited<ReturnType<typeof openWorkspace>>, label: string) => w.findAll('button').find((b) => b.text().includes(label))!;

describe('Conexões (credenciais do RabbitMQ)', () => {
    beforeEach(() => {
        resetApp();
        pageProps.value = { locale: 'pt-BR', auth: { enabled: false, user: null } };
    });
    afterEach(() => vi.unstubAllGlobals());

    it('lista as conexões com a saúde de cada uma e marca a que está em exibição', async () => {
        installApi({ brokers: [broker(), broker({ id: 2, name: 'Homologação', health: { ok: false, message: 'Usuário ou senha não aceitos', at: null } })] });
        const w = await openWorkspace('conexoes');
        const cards = w.findAll('.conn-card');

        expect(cards).toHaveLength(2);
        expect(cards[0].text()).toContain('Produção');
        expect(cards[0].text()).toContain('Em exibição');
        expect(cards[0].text()).toContain('4 filas');
        expect(cards[1].find('.status-dot').classes()).toContain('down');
        expect(cards[1].text()).toContain('Usuário ou senha não aceitos');
        w.unmount();
    });

    it('nunca mostra a senha de uma conexão salva', async () => {
        installApi();
        const w = await openWorkspace('conexoes');
        await button(w, 'Editar').trigger('click');

        expect((field(w, 'password').element as HTMLInputElement).value).toBe('');
        expect(w.html()).not.toContain('segredo');
        expect(w.find('.conn-form').text()).toContain('Deixe em branco para manter a salva');
        w.unmount();
    });

    it('adiciona uma conexão: testa, mostra o resultado e salva com a senha digitada', async () => {
        const api = installApi();
        const w = await openWorkspace('conexoes');
        await button(w, 'Adicionar conexão').trigger('click');

        await field(w, 'name').setValue('Homologação');
        await field(w, 'url').setValue('https://hom.example.com');
        await field(w, 'username').setValue('ana');
        await field(w, 'password').setValue('segredo-longo');
        await field(w, 'vhost').setValue('/hom');

        await button(w, 'Testar conexão').trigger('click');
        await settle();
        const result = w.find('.test-result');
        expect(result.classes()).toContain('ok');
        expect(result.text()).toContain('Conectado ao RabbitMQ 4.1.1');
        expect(result.text()).toContain('tag monitoring');
        expect(result.text()).toContain('acessível');

        await w.find('.conn-form').trigger('submit');
        await settle();

        const post = api.calls.find((c) => c.method === 'POST' && c.url === '/api/brokers')!;
        expect(post.body).toMatchObject({ name: 'Homologação', url: 'https://hom.example.com', username: 'ana', password: 'segredo-longo', vhost: '/hom', amqp_port: 5672, tap_enabled: true });
        expect(w.find('.conn-form').exists()).toBe(false);
        expect(w.findAll('.conn-card').map((c) => c.find('b').text())).toContain('Homologação');
        w.unmount();
    });

    it('explica por que o teste falhou', async () => {
        installApi({ testResult: { ok: false, message: 'Usuário ou senha não aceitos pelo RabbitMQ (resposta 401)' } });
        const w = await openWorkspace('conexoes');
        await button(w, 'Adicionar conexão').trigger('click');
        await button(w, 'Testar conexão').trigger('click');
        await settle();

        expect(w.find('.test-result').classes()).toContain('down');
        expect(w.find('.test-result').text()).toContain('Não foi possível conectar');
        expect(w.find('.test-result').text()).toContain('Usuário ou senha não aceitos');
        w.unmount();
    });

    it('avisa quando a porta AMQP está fechada, porque a escuta não vai funcionar', async () => {
        installApi({ testResult: { ok: true, version: '4', tags: ['monitoring'], canMonitor: true, amqp: { ok: false, host: 'x', port: 5672 } } });
        const w = await openWorkspace('conexoes');
        await button(w, 'Adicionar conexão').trigger('click');
        await button(w, 'Testar conexão').trigger('click');
        await settle();

        expect(w.find('.test-result .warn-text').text()).toContain('inacessível');
        w.unmount();
    });

    it('mostra os erros de validação do servidor ao lado de cada campo', async () => {
        installApi({ writeErrors: { name: ['O campo nome é obrigatório.'], url: ['Informe um endereço da API válido.'] } });
        const w = await openWorkspace('conexoes');
        await button(w, 'Adicionar conexão').trigger('click');
        await w.find('.conn-form').trigger('submit');
        await settle();

        const errors = w.findAll('.conn-form .field-error').map((e) => e.text());
        expect(errors).toContain('O campo nome é obrigatório.');
        expect(errors).toContain('Informe um endereço da API válido.');
        expect(w.find('.conn-form').exists()).toBe(true); // continua aberto para corrigir
        w.unmount();
    });

    it('edita sem trocar a senha: manda a senha em branco, que o servidor interpreta como "manter"', async () => {
        const api = installApi();
        const w = await openWorkspace('conexoes');
        await button(w, 'Editar').trigger('click');
        await field(w, 'name').setValue('Produção (novo nome)');
        await w.find('.conn-form').trigger('submit');
        await settle();

        const put = api.calls.find((c) => c.method === 'PUT')!;
        expect(put.url).toBe('/api/brokers/1');
        expect(put.body).toMatchObject({ name: 'Produção (novo nome)', password: '' });
        w.unmount();
    });

    it('remove só depois de confirmar', async () => {
        const api = installApi({ brokers: [broker(), broker({ id: 2, name: 'Outra' })] });
        const w = await openWorkspace('conexoes');
        const remove = () => w.findAll('.conn-card')[1].findAll('button').find((b) => b.text().includes('Remover'))!;

        vi.stubGlobal('confirm', vi.fn(() => false));
        await remove().trigger('click');
        await settle();
        expect(api.calls.some((c) => c.method === 'DELETE')).toBe(false);

        vi.stubGlobal('confirm', vi.fn(() => true));
        await remove().trigger('click');
        await settle();
        expect(api.calls.some((c) => c.method === 'DELETE' && c.url === '/api/brokers/2')).toBe(true);
        expect(w.findAll('.conn-card')).toHaveLength(1);
        w.unmount();
    });

    it('"Abrir" troca a conexão em exibição', async () => {
        const api = installApi({ brokers: [broker(), broker({ id: 2, name: 'Outra' })] });
        const w = await openWorkspace('conexoes');
        await w.findAll('.conn-card')[1].findAll('button').find((b) => b.text() === 'Abrir')!.trigger('click');
        await settle();

        expect(localStorage.getItem('warren.broker')).toBe('2');
        expect(api.urls()).toContain('GET /api/b/2/topology');
        w.unmount();
    });
});
