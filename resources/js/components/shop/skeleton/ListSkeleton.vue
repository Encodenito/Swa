<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

/** Placeholder rows shaped like the staff/admin tables (and their stacked phone layout). */
withDefaults(defineProps<{ rows?: number }>(), { rows: 6 });

// Varied widths read as text; identical bars read as a loading bar.
const widths = ['w-3/4', 'w-1/2', 'w-2/3', 'w-5/12', 'w-7/12', 'w-1/3'];
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-border bg-card">
        <div class="hidden h-11 items-center gap-6 border-b border-border bg-muted/50 px-4 md:flex">
            <Skeleton class="h-3 w-20" />
            <Skeleton class="h-3 w-24" />
            <Skeleton class="h-3 w-16" />
            <Skeleton class="ml-auto h-3 w-16" />
        </div>
        <ul class="divide-y divide-border">
            <li v-for="n in rows" :key="n" class="flex items-center gap-4 px-4 py-4">
                <div class="min-w-0 flex-1 space-y-2">
                    <Skeleton :class="['h-4', widths[n % widths.length]]" />
                    <Skeleton :class="['h-3', widths[(n + 2) % widths.length]]" />
                </div>
                <Skeleton class="hidden h-5 w-20 rounded-full md:block" />
                <Skeleton class="h-4 w-16" />
            </li>
        </ul>
    </div>
</template>
