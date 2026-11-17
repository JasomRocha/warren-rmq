<script setup lang="ts">
/**
 * A grey placeholder in the shape of what is loading, so the page keeps its layout while data arrives.
 * It pulses gently (and stays still for people who asked for less motion). Screen readers are told the area is busy.
 */
withDefaults(defineProps<{ width?: string; height?: string; radius?: string; lines?: number }>(), { width: '100%', height: '14px', radius: '6px', lines: 1 });
</script>

<template>
    <span v-if="lines === 1" class="sk" :style="{ width, height, borderRadius: radius }" aria-hidden="true"></span>
    <span v-else class="sk-lines" aria-hidden="true">
        <span v-for="i in lines" :key="i" class="sk" :style="{ width: i === lines ? '62%' : width, height, borderRadius: radius }"></span>
    </span>
</template>
