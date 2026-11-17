<script setup lang="ts">
/** The Warren rabbit. It takes its colour from the text colour around it (`color: var(--accent)` in most places). */
defineProps<{ size?: number }>();
</script>

<template>
    <svg class="logo-mark" :width="size ?? 28" :height="size ?? 28" viewBox="0 0 64 64" role="img" aria-label="Warren">
        <g fill="currentColor" fill-rule="evenodd">
            <path transform="rotate(-8 22.8 19.5)" d="M17.2 19.5a5.6 16.5 0 1 0 11.2 0a5.6 16.5 0 1 0 -11.2 0zM20.1 24a2.7 10.3 0 1 0 5.4 0a2.7 10.3 0 1 0 -5.4 0z" />
            <path transform="rotate(8 41.2 19.5)" d="M35.6 19.5a5.6 16.5 0 1 0 11.2 0a5.6 16.5 0 1 0 -11.2 0zM38.5 24a2.7 10.3 0 1 0 5.4 0a2.7 10.3 0 1 0 -5.4 0z" />
            <path d="M14 44.5a18 16 0 1 0 36 0a18 16 0 1 0 -36 0zM23.4 43.6a2.3 2.7 0 1 0 4.6 0a2.3 2.7 0 1 0 -4.6 0zM36 43.6a2.3 2.7 0 1 0 4.6 0a2.3 2.7 0 1 0 -4.6 0zM29.9 50a2.1 1.6 0 1 0 4.2 0a2.1 1.6 0 1 0 -4.2 0z" />
        </g>
    </svg>
</template>
