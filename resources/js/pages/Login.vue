<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import Logo from '../Components/Logo.vue';
import { useI18n } from '../lib/i18n';
import { applyTheme } from '../lib/theme';

defineProps<{ noUsers: boolean }>();
const { t } = useI18n();

onMounted(applyTheme);

const form = useForm({ email: '', password: '', remember: false });
const submit = (): void => {
    form.post('/login', { onFinish: () => form.reset('password') });
};
</script>

<template>
    <Head :title="t('login.title')" />
    <main class="login">
        <form class="login-card" @submit.prevent="submit">
            <div class="login-brand">
                <Logo :size="44" />
                <h1>Warren</h1>
                <p class="muted">{{ t('login.subtitle') }}</p>
            </div>

            <label class="field" for="email">
                <span>{{ t('login.email') }}</span>
                <input id="email" v-model="form.email" type="email" name="email" autocomplete="username" autofocus required :aria-invalid="!!form.errors.email" />
            </label>

            <label class="field" for="password">
                <span>{{ t('login.password') }}</span>
                <input id="password" v-model="form.password" type="password" name="password" autocomplete="current-password" required />
            </label>

            <p v-if="form.errors.email" class="field-error" role="alert">{{ form.errors.email }}</p>

            <label class="check remember"><input v-model="form.remember" type="checkbox" /> {{ t('login.remember') }}</label>

            <button type="submit" class="primary" :disabled="form.processing">{{ t('login.submit') }}</button>

            <div v-if="noUsers" class="hint">
                <p>{{ t('login.no-users') }}</p>
                <code>docker compose exec app php artisan warren:user you@example.com</code>
                <code>php artisan warren:user you@example.com</code>
            </div>
        </form>
    </main>
</template>
