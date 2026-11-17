import { describe, expect, it } from 'vitest';
import { readPriority } from '../lib/priority';

const quorum = { type: 'quorum', arguments: {} };
const classic = (max?: number) => ({ type: 'classic', arguments: max === undefined ? {} : { 'x-max-priority': max } });

describe('prioridade em palavras', () => {
    it('fila quorum: 0 a 31, sem prioridade conta como 4 (normal)', () => {
        expect([0, 1, 2, 3, 4, 5, 15, 16, 31, 99].map((p) => readPriority(p, quorum).level)).toEqual([
            'very-low', 'very-low', 'low', 'low', 'normal', 'high', 'high', 'very-high', 'very-high', 'very-high',
        ]);
        expect(readPriority(null, quorum)).toEqual({ level: 'normal', effective: 4, implicit: true });
        expect(readPriority(99, quorum).effective).toBe(31);
    });

    it('fila classic: a escala vai até o x-max-priority, e sem ele a fila ignora a prioridade', () => {
        expect(readPriority(null, classic(10))).toEqual({ level: 'normal', effective: 0, implicit: true });
        expect(readPriority(3, classic(10)).level).toBe('high');
        expect(readPriority(9, classic(10)).level).toBe('very-high');
        expect(readPriority(50, classic(10)).effective).toBe(10);
        expect(readPriority(5, classic()).level).toBe('ignored');
    });

    it('sem saber a fila, usa a escala quorum', () => {
        expect(readPriority(31).level).toBe('very-high');
        expect(readPriority(2).level).toBe('low');
    });

    it('antes do RabbitMQ 4.3 a fila quorum só tem dois níveis: até 4 normal, 5 ou mais alta', () => {
        expect([1, 4, 5, 31].map((p) => readPriority(p, quorum, '4.1.1').level)).toEqual(['normal', 'normal', 'high', 'high']);
        expect(readPriority(2, quorum, '4.3.6').level).toBe('low');
        expect(readPriority(2, quorum, null).level).toBe('low');
    });
});
