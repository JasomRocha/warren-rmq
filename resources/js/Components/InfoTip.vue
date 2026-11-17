<script setup lang="ts">
import { onBeforeUnmount, ref, useId } from 'vue';
import { useI18n } from '../lib/i18n';

/**
 * A "?" that explains how a number is worked out. Opens on hover, on keyboard focus and on tap
 * (touch screens have no hover); Escape or a tap elsewhere closes it.
 */
defineProps<{ text: string; label?: string }>();
const { t } = useI18n();

const open = ref(false);
const id = `tip-${useId()}`;
const root = ref<HTMLElement | null>(null);

function outside(event: PointerEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) close();
}
function show(): void {
    open.value = true;
    document.addEventListener('pointerdown', outside);
}
function close(): void {
    open.value = false;
    document.removeEventListener('pointerdown', outside);
}
const toggle = (): void => (open.value ? close() : show());
onBeforeUnmount(close);
</script>

<template>
    <span ref="root" class="info-tip" @mouseenter="show" @mouseleave="close">
        <button
            type="button"
            class="info-tip-button"
            :aria-label="label ?? t('info.more')"
            :aria-expanded="open"
            :aria-describedby="open ? id : undefined"
            @click.stop="toggle"
            @focus="show"
            @blur="close"
            @keydown.esc="close"
        >
            <span v-if="label" class="info-tip-text">{{ label }}</span>
            <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true">
                <circle cx="8" cy="8" r="6.75" fill="none" stroke="currentColor" stroke-width="1.3" />
                <path d="M6.3 6.2a1.8 1.8 0 0 1 3.5.5c0 1.2-1.8 1.5-1.8 2.6" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
                <circle cx="8" cy="11.6" r=".8" fill="currentColor" />
            </svg>
        </button>
        <span v-if="open" :id="id" class="info-tip-pop" role="tooltip">{{ text }}</span>
    </span>
</template>
