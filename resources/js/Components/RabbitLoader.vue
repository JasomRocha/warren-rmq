<script setup lang="ts">
import { useI18n } from '../lib/i18n';

/** Shown while a whole screen waits for its first data: the Warren rabbit, running. */
withDefaults(defineProps<{ label?: string; size?: number }>(), { size: 112 });
const { t } = useI18n();
</script>

<template>
    <div class="rabbit-loader" role="status" aria-live="polite">
        <svg :width="size" :height="size * 0.62" viewBox="0 0 130 80" aria-hidden="true">
            <path class="rl-ground" d="M4 74H126" />
            <g class="rl-runner">
                <g class="rl-body">
                    <!-- back legs push off, front legs reach -->
                    <g class="rl-leg rl-back"><path d="M44 52 q-8 10 -18 14" /></g>
                    <g class="rl-leg rl-front"><path d="M78 54 q6 8 14 10" /></g>
                    <ellipse class="rl-fill" cx="58" cy="46" rx="24" ry="14" />
                    <circle class="rl-tail" cx="34" cy="41" r="6" />
                    <g class="rl-ears">
                        <ellipse class="rl-fill" cx="81" cy="16" rx="4.5" ry="13" transform="rotate(-28 81 16)" />
                        <ellipse class="rl-fill" cx="88" cy="17" rx="4" ry="12" transform="rotate(-14 88 17)" />
                    </g>
                    <circle class="rl-fill" cx="86" cy="36" r="12" />
                    <circle class="rl-eye" cx="91" cy="33" r="1.8" />
                    <circle class="rl-nose" cx="97.5" cy="38" r="1.6" />
                </g>
            </g>
        </svg>
        <span class="rl-label">{{ label ?? t('loading') }}</span>
    </div>
</template>
