<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

/** Placeholder cards with the same footprint as ItemCard, so nothing jumps when items arrive. */
withDefaults(defineProps<{ count?: number }>(), { count: 8 });
</script>

<template>
    <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <li v-for="n in count" :key="n" class="flex flex-col overflow-hidden rounded-lg border border-border bg-card">
            <Skeleton class="aspect-[4/3] rounded-none" />
            <div class="flex flex-1 flex-col gap-2 p-4">
                <Skeleton class="h-3 w-1/3" />
                <Skeleton class="h-4 w-4/5" />
                <Skeleton class="h-3 w-1/2" />
                <Skeleton class="mt-4 h-7 w-24" />
            </div>
        </li>
    </ul>
</template>
