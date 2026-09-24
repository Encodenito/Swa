<script setup lang="ts">
/** Available units with a text label for low and out of stock (not colour alone). */
defineProps<{ available: number; lowStock: boolean }>();
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1.5">
        <span class="font-semibold tabular-nums">{{ available }}</span>
        <span
            v-if="available <= 0"
            class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-900 dark:bg-red-900/40 dark:text-red-100"
            >Out of stock</span
        >
        <span
            v-else-if="lowStock"
            class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-100"
            >Low stock</span
        >
    </span>
</template>
