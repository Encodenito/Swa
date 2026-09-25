<script setup lang="ts">
/** Available units with a text label for low and out of stock (not colour alone). */
defineProps<{ available: number; lowStock: boolean }>();
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1.5">
        <span class="font-semibold tabular-nums">{{ available }}</span>
        <span v-if="available <= 0" class="rounded-full bg-danger-chip px-2 py-0.5 text-xs font-semibold text-danger-strong">Out of stock</span>
        <span v-else-if="lowStock" class="rounded-full bg-warning-chip px-2 py-0.5 text-xs font-semibold text-warning-strong">Low stock</span>
    </span>
</template>
