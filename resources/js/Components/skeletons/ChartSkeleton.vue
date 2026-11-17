<script setup lang="ts">
import Skeleton from '../Skeleton.vue';

withDefaults(defineProps<{ height?: number }>(), { height: 220 });
</script>

<template>
    <div class="chart-skeleton" role="status" aria-busy="true" :style="{ height: height + 'px' }">
        <div class="chart-skeleton-axis"><Skeleton v-for="i in 4" :key="i" width="28px" height="10px" /></div>
        <div class="chart-skeleton-bars">
            <Skeleton v-for="(h, i) in [38, 52, 44, 70, 58, 80, 66, 48, 62, 90, 74, 56]" :key="i" width="100%" :height="h + '%'" radius="4px" />
        </div>
    </div>
</template>
