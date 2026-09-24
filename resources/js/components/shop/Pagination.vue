<script setup lang="ts">
import type { PaginationLink } from '@/types';
import { Link } from '@inertiajs/vue3';

defineProps<{ links: PaginationLink[] }>();

function label(raw: string): string {
    return raw.replace('&laquo;', '‹').replace('&raquo;', '›');
}
</script>

<template>
    <nav v-if="links.length > 3" aria-label="Pagination" class="mt-6 flex flex-wrap items-center gap-1">
        <template v-for="(link, i) in links" :key="i">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                :aria-current="link.active ? 'page' : undefined"
                :class="[
                    'inline-flex h-10 min-w-10 items-center justify-center rounded-md border px-3 text-sm',
                    link.active ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card hover:bg-accent',
                ]"
                >{{ label(link.label) }}</Link
            >
            <span v-else class="inline-flex h-10 min-w-10 items-center justify-center px-3 text-sm text-muted-foreground">{{
                label(link.label)
            }}</span>
        </template>
    </nav>
</template>
