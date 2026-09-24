<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: string }>();

const chip = computed(
    () =>
        ({
            ordered: { label: 'Ordered', tone: 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100' },
            received: { label: 'Received', tone: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100' },
            cancelled: { label: 'Cancelled', tone: 'bg-muted text-muted-foreground' },
        })[props.status] ?? { label: props.status, tone: 'bg-muted text-muted-foreground' },
);
</script>

<template>
    <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', chip.tone]">{{ chip.label }}</span>
</template>
