<script setup lang="ts">
import type { ReservationStatus } from '@/types';
import { computed } from 'vue';

const props = defineProps<{ status: ReservationStatus; label: string }>();

const tone = computed(
    () =>
        ({
            pending_payment: 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100',
            confirmed: 'bg-blue-100 text-blue-900 dark:bg-blue-900/40 dark:text-blue-100',
            processing: 'bg-indigo-100 text-indigo-900 dark:bg-indigo-900/40 dark:text-indigo-100',
            completed: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100',
            cancelled: 'bg-muted text-muted-foreground',
            expired: 'bg-muted text-muted-foreground',
            forfeited: 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100',
            voided: 'bg-muted text-muted-foreground',
        })[props.status],
);
</script>

<template>
    <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', tone]">{{ label }}</span>
</template>
