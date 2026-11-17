import { vi } from 'vitest';
import { defineComponent, reactive } from 'vue';

/** Stands in for @inertiajs/vue3 in the page tests (see `vi.mock` at the top of each test file). */
export const router = { visit: vi.fn(), post: vi.fn() };
export const pageProps: { value: Record<string, unknown> } = { value: { locale: 'pt-BR', auth: { enabled: false, user: null } } };
export const lastForm: { current: any } = { current: null };

export const inertiaModule = () => ({
    usePage: () => ({ props: pageProps.value }),
    Head: defineComponent({ render: () => null }),
    router,
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({ ...data, errors: {} as Record<string, string>, processing: false, post: vi.fn(), reset: vi.fn() });
        lastForm.current = form;
        return form;
    },
});
