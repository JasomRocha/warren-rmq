import { describe, expect, it } from 'vitest';
import { interpolate, pick, plural, toCount } from '../lib/plural';

describe('plural sem "(s)"', () => {
    it('só 1 fica no singular, como se escreve: 0 mensagens, 1,5 mensagens', () => {
        expect([0, 1, 2, 1.5, -1].map((n) => plural(n, 'mensagem', 'mensagens'))).toEqual(['mensagens', 'mensagem', 'mensagens', 'mensagens', 'mensagem']);
        expect(pick(0, ['nenhuma', 'uma', 'várias'])).toBe('nenhuma');
    });

    it('lê o número já formatado para a tela, em português e em inglês', () => {
        expect(toCount('1.420', 'pt-BR')).toBe(1420);
        expect(toCount('1,5', 'pt-BR')).toBe(1.5);
        expect(toCount('1,420', 'en')).toBe(1420);
        expect(toCount(7, 'pt-BR')).toBe(7);
    });

    it('preenche a frase inteira, com o verbo concordando', () => {
        const text = '{n} {n|mensagem publicada|mensagens publicadas} {n|não chegou|não chegaram} a nenhuma fila';
        expect(interpolate(text, { n: 1 }, 'pt-BR')).toBe('1 mensagem publicada não chegou a nenhuma fila');
        expect(interpolate(text, { n: '1.420' }, 'pt-BR')).toBe('1.420 mensagens publicadas não chegaram a nenhuma fila');
    });

    it('com três formas a primeira é o zero, e # vira o número', () => {
        const text = '{n|nenhuma fila|# fila|# filas}';
        expect([0, 1, 3].map((n) => interpolate(text, { n }, 'pt-BR'))).toEqual(['nenhuma fila', '1 fila', '3 filas']);
    });

    it('deixa em paz variáveis que não vieram e chaves que não são de plural', () => {
        expect(interpolate('{a} e {b}', { a: 'x' }, 'pt-BR')).toBe('x e {b}');
        expect(interpolate('{n|item|itens}', {}, 'pt-BR')).toBe('itens');
    });
});
