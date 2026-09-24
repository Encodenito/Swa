<script setup lang="ts">
import ClearanceBadge from '@/components/shop/ClearanceBadge.vue';
import { formatPeso } from '@/lib/money';
import type { ReservationLine } from '@/types';

/** Reservation lines with server-computed unit prices and line totals. */
defineProps<{ lines: ReservationLine[] }>();
</script>

<template>
    <p v-if="lines.length === 0" class="text-sm text-muted-foreground">This reservation has no items.</p>
    <ul v-else class="divide-y divide-border">
        <li v-for="line in lines" :key="line.id" class="flex items-start justify-between gap-3 py-2.5 text-sm">
            <span class="min-w-0">
                <span class="block font-medium">
                    <span class="tabular-nums">{{ line.quantity }} ×</span> {{ line.item_name ?? 'Removed item' }}
                </span>
                <span class="mt-0.5 flex flex-wrap items-center gap-2 text-muted-foreground">
                    <span class="tabular-nums">{{ formatPeso(line.unit_price) }} each</span>
                    <ClearanceBadge v-if="line.clearance" />
                </span>
            </span>
            <span class="shrink-0 font-semibold tabular-nums">{{ formatPeso(line.line_total) }}</span>
        </li>
    </ul>
</template>
