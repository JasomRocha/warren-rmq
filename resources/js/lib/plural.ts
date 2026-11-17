/**
 * Singular and plural without "(s)" or "(ns)".
 *
 * In the texts: {n|mensagem|mensagens} picks the word for the value of {n}, and a third form covers zero:
 * {n|nenhuma mensagem|# mensagem|# mensagens}, where # is the number as it was passed in.
 * The forms can be whole phrases, so the verb agrees too: {n|não chegou|não chegaram}.
 *
 * Only 1 (and -1) takes the singular, in both languages: "0 mensagens", "1,5 mensagens", as people write them
 * (CLDR would make 0 and 1,5 singular in Portuguese).
 */

/** The number a text variable stands for: a number as is, or a string formatted for the interface ("1.420", "1,5"). */
export function toCount(value: string | number, locale: string): number {
    if (typeof value === 'number') return value;
    const parts = new Intl.NumberFormat(locale).formatToParts(12345.6);
    const group = parts.find((p) => p.type === 'group')?.value ?? ',';
    const decimal = parts.find((p) => p.type === 'decimal')?.value ?? '.';
    const cleaned = value.trim().split(group).join('').replace(decimal, '.').replace(/[^\d.+-]/g, '');
    const n = Number(cleaned);
    return cleaned === '' || Number.isNaN(n) ? NaN : n;
}

/** The form that goes with `n`: [one, other] or [zero, one, other]. */
export function pick(n: number, forms: string[]): string {
    const [zero, one, other] = forms.length >= 3 ? forms : [undefined, forms[0], forms[1] ?? forms[0]];
    if (n === 0 && zero !== undefined) return zero;
    return Math.abs(n) === 1 ? one : other;
}

/** For code: plural(3, 'fila', 'filas') is "filas". */
export const plural = (n: number, one: string, other: string): string => pick(n, [one, other]);

/**
 * Fills {name} and {name|one|other} (or {name|zero|one|other}) in a text.
 * A plural whose variable is missing or not a number is left as the "other" form.
 */
export function interpolate(text: string, vars: Record<string, string | number>, locale: string): string {
    return text.replace(/\{([\w.-]+)((?:\|[^{}|]*){2,3})?\}/g, (whole, name: string, forms?: string) => {
        const value = vars[name];
        if (forms === undefined) return value === undefined ? whole : String(value);
        const options = forms.slice(1).split('|');
        const n = value === undefined ? NaN : toCount(value, locale);
        const chosen = Number.isNaN(n) ? options[options.length - 1] : pick(n, options);
        return chosen.replaceAll('#', value === undefined ? '' : String(value));
    });
}
