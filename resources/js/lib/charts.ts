/** Small, dependency-free helpers for the SVG charts. All pure, so they are easy to test. */

/** Round-number ticks from 0 up to a "nice" maximum that fits `max`: 0, 50, 100, 150... */
export function niceTicks(max: number, count = 4): number[] {
    if (!Number.isFinite(max) || max <= 0) return [0, 1];
    const rough = max / count;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= rough) ?? 10 * magnitude;
    const ticks: number[] = [];
    for (let v = 0; v < max + step; v += step) {
        ticks.push(Number(v.toFixed(10)));
        if (v >= max) break;
    }
    return ticks;
}

export function linePath(xs: number[], ys: number[]): string {
    return xs.map((x, i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${ys[i].toFixed(1)}`).join(' ');
}

/** A filled area between the line and the baseline (a y coordinate or per-point lower edge). */
export function areaPath(xs: number[], tops: number[], bottoms: number[] | number): string {
    if (xs.length === 0) return '';
    const floor = (i: number): number => (typeof bottoms === 'number' ? bottoms : bottoms[i]);
    const up = xs.map((x, i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${tops[i].toFixed(1)}`).join(' ');
    const down = [...xs].map((x, i) => `L${x.toFixed(1)} ${floor(i).toFixed(1)}`).reverse().join(' ');
    return `${up} ${down} Z`;
}

/** Cumulative sums, series by series: what a stacked area needs (each layer's top edge). */
export function stack(series: number[][]): number[][] {
    const totals: number[] = [];
    return series.map((values) =>
        values.map((v, i) => {
            totals[i] = (totals[i] ?? 0) + v;
            return totals[i];
        }),
    );
}

/** Index of the value in a sorted list that is closest to `x`. */
export function nearestIndex(sorted: number[], x: number): number {
    if (sorted.length === 0) return -1;
    let low = 0;
    let high = sorted.length - 1;
    while (low < high) {
        const mid = (low + high) >> 1;
        if (sorted[mid] < x) low = mid + 1;
        else high = mid;
    }
    return low > 0 && x - sorted[low - 1] <= sorted[low] - x ? low - 1 : low;
}

/** 1.2 mil, 3,4 mi: short numbers for axes. */
export function compact(n: number, locale: string): string {
    return new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(n);
}

/** "14:05" for a day or less, "17/11 14:05" beyond it. */
export function timeLabel(seconds: number, locale: string, spanSeconds: number): string {
    const date = new Date(seconds * 1000);
    const time = date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', hour12: false });
    return spanSeconds <= 86400 ? time : `${date.toLocaleDateString(locale, { day: '2-digit', month: '2-digit' })} ${time}`;
}

/** Evenly spread positions (indexes) for `count` labels along `length` points, always including the ends. */
export function labelIndexes(length: number, count: number): number[] {
    if (length <= 0) return [];
    if (length <= count) return Array.from({ length }, (_, i) => i);
    return Array.from({ length: count }, (_, i) => Math.round((i * (length - 1)) / (count - 1)));
}

/** The change between the first and the last value, as a signed number (for the "since the start of the range" hint). */
export function delta(values: number[]): number {
    return values.length < 2 ? 0 : values[values.length - 1] - values[0];
}

/** Steps a reader thinks in when the axis is a length of time: seconds, then whole minutes, then whole hours. */
const DURATION_STEPS = [1, 2, 5, 10, 15, 20, 30, 60, 120, 300, 600, 900, 1200, 1800, 3600, 7200, 10800, 21600, 43200, 86400];

/** Ticks for a time axis (in seconds): 0, 30 s, 1 min, 90 s... rather than 0, 50, 100 that read as "1 min 40 s". */
export function durationTicks(max: number, count = 4): number[] {
    if (!Number.isFinite(max) || max <= 0) return [0, 1];
    const step = DURATION_STEPS.find((s) => s >= max / count) ?? Math.ceil(max / count / 86400) * 86400;
    const ticks: number[] = [];
    for (let v = 0; ; v += step) {
        ticks.push(v);
        if (v >= max) break;
    }
    return ticks;
}

/** A short label for a tick on a time axis: "45 s", "2 min", "90 s", "3 h". */
export function durationTick(seconds: number): string {
    if (seconds === 0) return '0';
    if (seconds % 3600 === 0) return `${seconds / 3600} h`;
    if (seconds % 60 === 0) return `${seconds / 60} min`;
    return `${seconds} s`;
}
