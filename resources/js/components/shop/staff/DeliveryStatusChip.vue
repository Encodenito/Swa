<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: string }>();

const chip = computed(
    () =>
        ({
            ordered: { label: 'Ordered', tone: 'bg-warning-chip text-warning-strong' },
            received: { label: 'Received', tone: 'bg-success-chip text-success-strong' },
            cancelled: { label: 'Cancelled', tone: 'bg-muted text-muted-foreground' },
        })[props.status] ?? { label: props.status, tone: 'bg-muted text-muted-foreground' },
);
</script>

<template>
    <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', chip.tone]">{{ chip.label }}</span>
</template>
