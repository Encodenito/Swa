<script setup lang="ts">
import { formatPeso } from '@/lib/money';
import { computed } from 'vue';

/**
 * The shop's signature element: a shelf price tag. Clearance prices show the regular price struck through.
 * Display only — amounts come from the server.
 */
const props = withDefaults(
    defineProps<{
        price: string;
        regularPrice?: string | null;
        clearance?: boolean;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { regularPrice: null, clearance: false, size: 'md' },
);

const sizeClass = computed(() => ({ sm: 'text-sm px-1.5 py-0.5', md: 'text-base px-2 py-0.5', lg: 'text-2xl px-3 py-1' })[props.size]);
</script>

<template>
    <span class="inline-flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <span :class="['rounded-sm bg-tag font-bold tabular-nums text-tag-foreground', sizeClass]">
            <span v-if="clearance" class="sr-only">Clearance price </span>{{ formatPeso(price) }}
        </span>
        <span v-if="clearance && regularPrice" class="text-sm tabular-nums text-muted-foreground line-through">
            <span class="sr-only">Regular price </span>{{ formatPeso(regularPrice) }}
        </span>
    </span>
</template>
