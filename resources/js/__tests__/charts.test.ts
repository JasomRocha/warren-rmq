import { describe, expect, it } from 'vitest';
import { areaPath, compact, delta, durationTick, durationTicks, labelIndexes, linePath, nearestIndex, niceTicks, stack, timeLabel } from '../lib/charts';
import { parseHash, toHash } from '../lib/section';

describe('eixos dos gráficos', () => {
    it('as marcas são números redondos que cobrem o máximo', () => {
        expect(niceTicks(87)).toEqual([0, 25, 50, 75, 100]);
        expect(niceTicks(1000)).toEqual([0, 250, 500, 750, 1000]);
        expect(niceTicks(7)).toEqual([0, 2, 4, 6, 8]);
        expect(niceTicks(0.8)).toEqual([0, 0.2, 0.4, 0.6, 0.8]);
    });

    it('sem dados ainda desenha um eixo válido', () => {
        expect(niceTicks(0)).toEqual([0, 1]);
        expect(niceTicks(NaN)).toEqual([0, 1]);
        expect(niceTicks(-5)).toEqual([0, 1]);
    });

    it('o último valor das marcas nunca é menor que o máximo', () => {
        for (const max of [1, 3, 14, 99, 101, 5000, 123456]) {
            const ticks = niceTicks(max);
            expect(ticks[ticks.length - 1]).toBeGreaterThanOrEqual(max);
            expect(ticks[0]).toBe(0);
        }
    });
});

describe('traçado', () => {
    it('a linha liga os pontos na ordem', () => {
        expect(linePath([0, 10, 20], [5, 6, 7])).toBe('M0.0 5.0 L10.0 6.0 L20.0 7.0');
    });

    it('a área fecha pela linha de base', () => {
        expect(areaPath([0, 10], [5, 6], 20)).toBe('M0.0 5.0 L10.0 6.0 L10.0 20.0 L0.0 20.0 Z');
        expect(areaPath([], [], 20)).toBe('');
    });

    it('a área empilhada fecha pela borda da camada de baixo', () => {
        expect(areaPath([0, 10], [3, 3], [8, 9])).toBe('M0.0 3.0 L10.0 3.0 L10.0 9.0 L0.0 8.0 Z');
    });

    it('empilhar soma as séries ponto a ponto', () => {
        expect(stack([[1, 2, 3], [10, 20, 30], [100, 200, 300]])).toEqual([[1, 2, 3], [11, 22, 33], [111, 222, 333]]);
    });
});

describe('cursor do gráfico', () => {
    const times = [0, 10, 20, 40, 80];

    it('acha o ponto mais próximo', () => {
        expect(nearestIndex(times, -5)).toBe(0);
        expect(nearestIndex(times, 4)).toBe(0);
        expect(nearestIndex(times, 6)).toBe(1);
        expect(nearestIndex(times, 31)).toBe(3);
        expect(nearestIndex(times, 70)).toBe(4);
        expect(nearestIndex(times, 999)).toBe(4);
        expect(nearestIndex([], 5)).toBe(-1);
    });

    it('espalha os rótulos do eixo incluindo as pontas', () => {
        expect(labelIndexes(100, 5)).toEqual([0, 25, 50, 74, 99]);
        expect(labelIndexes(3, 5)).toEqual([0, 1, 2]);
        expect(labelIndexes(0, 5)).toEqual([]);
    });
});

describe('textos', () => {
    it('números curtos no eixo, em português', () => {
        expect(compact(1200, 'pt-BR').replace(/\s/g, ' ')).toBe('1,2 mil');
        expect(compact(950, 'pt-BR')).toBe('950');
        expect(compact(3_400_000, 'pt-BR')).toMatch(/3,4\s?mi/);
    });

    it('a hora aparece sozinha até um dia e com a data depois disso', () => {
        const t = Date.parse('2026-11-17T14:05:00') / 1000;
        expect(timeLabel(t, 'pt-BR', 3600)).toBe('14:05');
        expect(timeLabel(t, 'pt-BR', 86400)).toBe('14:05');
        expect(timeLabel(t, 'pt-BR', 259200)).toBe('17/11 14:05');
    });

    it('a variação é do primeiro ao último valor', () => {
        expect(delta([10, 40, 25])).toBe(15);
        expect(delta([5])).toBe(0);
        expect(delta([])).toBe(0);
    });
});

describe('endereço da tela', () => {
    it('lê e escreve o hash', () => {
        expect(parseHash('')).toBe('painel');
        expect(parseHash('#/fluxo')).toBe('fluxo');
        expect(parseHash('#/panel:erros-pdf')).toBe('panel:erros-pdf');
        expect(toHash('insights')).toBe('#/insights');
    });
});

describe('eixo de tempo', () => {
    it('usa degraus que se leem como tempo, e não 0, 50, 100 que viram "1 min 40 s"', () => {
        expect(durationTicks(100)).toEqual([0, 30, 60, 90, 120]);
        expect(durationTicks(500)).toEqual([0, 300, 600]);
        expect(durationTicks(9)).toEqual([0, 5, 10]);
        expect(durationTicks(0)).toEqual([0, 1]);
    });

    it('rótulos curtos que cabem ao lado do gráfico', () => {
        expect([0, 30, 90, 120, 600, 7200].map(durationTick)).toEqual(['0', '30 s', '90 s', '2 min', '10 min', '2 h']);
    });
});
