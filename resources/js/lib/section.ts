import { ref } from 'vue';

export const BASE_SECTIONS = ['painel', 'fluxo', 'filas', 'insights', 'mensagens', 'detalhes', 'conexoes'] as const;

/** Panels get an address of their own: "panel:<id>" is how the screen knows them, "painel/<id>" is how the link reads. */
export const panelSection = (id: string): string => `panel:${id}`;

/** "#/fluxo", "#/painel/erros-pdf": the address of the screen, so reloading or sharing a link lands on it. */
export function parseHash(hash: string): string {
    const clean = decodeURIComponent(hash.replace(/^#\/?/, ''));
    if (clean === '') return 'painel';
    return clean.startsWith('painel/') ? panelSection(clean.slice('painel/'.length)) : clean;
}

export const toHash = (section: string): string =>
    section.startsWith('panel:') ? `#/painel/${encodeURIComponent(section.slice('panel:'.length))}` : `#/${section}`;

export const section = ref<string>(typeof location === 'undefined' ? 'painel' : parseHash(location.hash));

export function go(next: string): void {
    section.value = next;
    // pushState, so the browser's back button returns to the previous screen (going back fires hashchange).
    if (typeof history !== 'undefined' && location.hash !== toHash(next)) history.pushState(null, '', toHash(next));
}

export function watchHash(): () => void {
    const onChange = (): void => {
        // An empty address carries no information (and some environments report one right after a replaceState).
        if (location.hash !== '') section.value = parseHash(location.hash);
    };
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
}
