import { ref } from 'vue';

export type Theme = 'system' | 'light' | 'dark';

const STORAGE = 'warren.theme';

function stored(): Theme {
    try {
        const value = localStorage.getItem(STORAGE);
        return value === 'light' || value === 'dark' ? value : 'system';
    } catch {
        return 'system';
    }
}

export const theme = ref<Theme>(stored());

/** What is on screen right now, resolving "system" to the OS preference. */
export function effectiveTheme(): 'light' | 'dark' {
    if (theme.value !== 'system') return theme.value;
    return typeof matchMedia === 'function' && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function applyTheme(): void {
    const root = document.documentElement;
    if (theme.value === 'system') root.removeAttribute('data-theme');
    else root.setAttribute('data-theme', theme.value);
}

/** Flips between light and dark (the first click leaves "system" for the opposite of what is shown). */
export function toggleTheme(): void {
    theme.value = effectiveTheme() === 'dark' ? 'light' : 'dark';
    try {
        localStorage.setItem(STORAGE, theme.value);
    } catch {
        /* not remembered */
    }
    applyTheme();
}
