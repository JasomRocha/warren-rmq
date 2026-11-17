<script setup lang="ts">
import Skeleton from '../Skeleton.vue';

withDefaults(defineProps<{ count?: number }>(), { count: 4 });
</script>

<template>
    <div class="kpis" role="status" aria-busy="true">
        <div v-for="i in count" :key="i" class="kpi">
            <Skeleton width="55%" height="12px" />
            <Skeleton width="40%" height="30px" radius="8px" />
            <Skeleton width="80%" height="20px" />
        </div>
    </div>
</template>
