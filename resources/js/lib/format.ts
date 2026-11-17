import type { Queue } from '../types';

let locale = 'pt-BR';

/** Set once by useI18n(), so numbers and dates follow the interface language. */
export function setLocale(next: string): void {
    locale = next;
}

export const number = (n: number): string => new Intl.NumberFormat(locale).format(n);

export const decimal = (n: number, digits = 1): string => new Intl.NumberFormat(locale, { maximumFractionDigits: digits }).format(n);

export const rate = (n: number): string => `${decimal(n, n >= 10 ? 0 : 1)}/s`;

export const jsonArgs = (args: Record<string, unknown>): string =>
    Object.entries(args)
        .map(([k, v]) => `${k}=${typeof v === 'object' ? JSON.stringify(v) : String(v)}`)
        .join(', ');

export const prefetchText = (n: number, unlimited: string): string => (n > 0 ? String(n) : unlimited);

/** Stable colour per instance, so a producer, its queue bubbles and its messages share one hue. */
export function instanceColor(key: string): string {
    if (!key) return '#7b8199';
    let h = 0;
    for (const c of key) h = (h * 31 + c.charCodeAt(0)) % 360;
    return `hsl(${h} 75% 62%)`;
}

export const clock = (ms: number): string => new Date(ms).toLocaleTimeString(locale, { hour12: false });

/** "há 5 min", "agora", "ontem": follows the interface language. */
export function relativeTime(ms: number, now: number): string {
    const seconds = Math.round((ms - now) / 1000);
    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    const abs = Math.abs(seconds);
    if (abs < 5) return formatter.format(0, 'second');
    if (abs < 60) return formatter.format(seconds, 'second');
    if (abs < 3600) return formatter.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return formatter.format(Math.round(seconds / 3600), 'hour');
    return formatter.format(Math.round(seconds / 86400), 'day');
}

export const dateTime = (ms: number): string => new Date(ms).toLocaleString(locale);

/** "850 ms", "12 s", "3 min 20 s", "2 h 5 min". Infinity (nothing is leaving) is "∞". */
export function duration(seconds: number | null): string {
    if (seconds === null) return '—';
    if (!Number.isFinite(seconds)) return '∞';
    if (seconds < 1) return `${Math.round(seconds * 1000)} ms`;
    if (seconds < 10) return `${decimal(seconds, 1)} s`;
    if (seconds < 60) return `${Math.round(seconds)} s`;
    if (seconds < 3600) {
        const s = Math.round(seconds % 60);
        return s ? `${Math.floor(seconds / 60)} min ${s} s` : `${Math.floor(seconds / 60)} min`;
    }
    const m = Math.round((seconds % 3600) / 60);
    return m ? `${Math.floor(seconds / 3600)} h ${m} min` : `${Math.floor(seconds / 3600)} h`;
}

/** The business name of a queue when the catalog has one, otherwise its technical name. */
export const queueLabel = (q: Pick<Queue, 'name' | 'catalog'>): string => q.catalog?.name ?? q.name;

/** 0.734 → "73%", 0.0042 → "0,4%". */
export const percent = (ratio: number): string =>
    new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: ratio > 0 && ratio < 0.1 ? 1 : 0 }).format(ratio);
