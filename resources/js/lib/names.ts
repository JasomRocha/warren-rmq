import { reactive } from 'vue';
import type { MessageSummary, Worker } from '../types';

/**
 * Names people recognise for what the broker only knows by a technical key.
 * The server resolves them (the catalog knows "acme.empresa.com.br" is "Acme"); here they are remembered so
 * every screen can ask "what is this instance called?" without each one receiving the whole table.
 */
const instances = reactive<Record<string, string>>({});

export function learnInstanceNames(messages: MessageSummary[]): void {
    for (const m of messages) {
        if (m.instance && m.instanceName && instances[m.instance] !== m.instanceName) instances[m.instance] = m.instanceName;
    }
}

export function resetInstanceNames(): void {
    for (const key of Object.keys(instances)) delete instances[key];
}

/** The display name of an instance key; `empty` is shown for messages that belong to no instance. */
export const instanceName = (key: string | null | undefined, empty: string): string => (key ? (instances[key] ?? key) : empty);

/** "Gerador de PDF · 10.0.0.2" when the catalog names the process, the technical "app-pdf@10.0.0.2" otherwise. */
export const workerLabel = (w: Pick<Worker, 'name' | 'catalog'>): string => w.catalog?.name ?? w.name;

/** The service a worker belongs to ("Gerador de PDF"): from the catalog, or the part of the name before the "@". */
export const workerService = (w: Pick<Worker, 'name' | 'catalog'>): string => w.catalog?.service ?? w.name.split('@')[0];

/** Shows the technical key next to the name only when they differ, so a tooltip never repeats itself. */
export const withKey = (label: string, key: string): string => (label === key || !key ? label : `${label} (${key})`);
