<script setup lang="ts">
import type { ReservationStatus } from '@/types';
import { computed } from 'vue';

const props = defineProps<{ status: ReservationStatus; label: string }>();

const tone = computed(
    () =>
        ({
            pending_payment: 'bg-warning-chip text-warning-strong',
            confirmed: 'bg-info-chip text-info-strong',
            processing: 'bg-indigo-100 text-indigo-900 dark:bg-indigo-900/40 dark:text-indigo-100',
            completed: 'bg-success-chip text-success-strong',
            cancelled: 'bg-muted text-muted-foreground',
            expired: 'bg-muted text-muted-foreground',
            forfeited: 'bg-danger-chip text-danger-strong',
            voided: 'bg-muted text-muted-foreground',
        })[props.status],
);
</script>

<template>
    <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', tone]">{{ label }}</span>
</template>
