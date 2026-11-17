import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, reactive } from 'vue';
import Login from '../pages/Login.vue';

const form = vi.hoisted(() => ({ current: null as unknown }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en' } }),
    Head: defineComponent({ render: () => null }),
    useForm: (data: Record<string, unknown>) => {
        const f = reactive({ ...data, errors: {} as Record<string, string>, processing: false, post: vi.fn(), reset: vi.fn() });
        form.current = f;
        return f;
    },
}));

describe('Login page', () => {
    beforeEach(() => {
        form.current = null;
    });

    it('posts the credentials to /login', async () => {
        const w = mount(Login, { props: { noUsers: false } });
        await w.find('#email').setValue('ana@example.com');
        await w.find('#password').setValue('secret-pass');
        await w.find('form').trigger('submit');

        const f = form.current as { post: ReturnType<typeof vi.fn>; email: string; password: string };
        expect(f.post).toHaveBeenCalledWith('/login', expect.any(Object));
        expect([f.email, f.password]).toEqual(['ana@example.com', 'secret-pass']);
    });

    it('shows the server error next to the form', async () => {
        const w = mount(Login, { props: { noUsers: false } });
        (form.current as { errors: Record<string, string> }).errors = { email: 'These credentials do not match our records.' };
        await w.vm.$nextTick();

        expect(w.find('[role=alert]').text()).toContain('do not match');
    });

    it('explains how to create the first user only when there are none', () => {
        expect(mount(Login, { props: { noUsers: true } }).find('.hint').text()).toContain('warren:user');
        expect(mount(Login, { props: { noUsers: false } }).find('.hint').exists()).toBe(false);
    });

    it('uses proper autocomplete hints so password managers work', () => {
        const w = mount(Login, { props: { noUsers: false } });
        expect(w.find('#email').attributes('autocomplete')).toBe('username');
        expect(w.find('#password').attributes('autocomplete')).toBe('current-password');
    });
});
