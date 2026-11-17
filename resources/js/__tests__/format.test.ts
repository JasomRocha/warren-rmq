import { describe, expect, it } from 'vitest';
import { dateTime, relativeTime, setLocale } from '../lib/format';

describe('tempo relativo e datas', () => {
    const now = Date.parse('2026-11-17T14:10:00');

    it('fala em português do Brasil', () => {
        setLocale('pt-BR');

        expect(relativeTime(now - 2_000, now)).toBe('agora');
        expect(relativeTime(now - 30_000, now)).toBe('há 30 segundos');
        expect(relativeTime(now - 5 * 60_000, now)).toBe('há 5 minutos');
        expect(relativeTime(now - 3 * 3600_000, now)).toBe('há 3 horas');
        expect(relativeTime(now - 86_400_000, now)).toBe('ontem');
    });

    it('formata a data e a hora no padrão brasileiro e muda com o idioma', () => {
        setLocale('pt-BR');
        expect(dateTime(Date.parse('2026-11-17T14:03:22'))).toMatch(/^17\/11\/2026,? 14:03:22$/);

        setLocale('en');
        expect(dateTime(Date.parse('2026-11-17T14:03:22'))).toContain('11/17/2026');
        setLocale('pt-BR');
    });
});
