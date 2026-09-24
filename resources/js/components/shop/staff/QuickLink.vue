<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

defineProps<{ href: string; title: string; description: string; icon: LucideIcon }>();
</script>

<template>
    <Link
        :href="href"
        class="group flex items-start gap-4 rounded-lg border border-border bg-card p-4 transition-colors hover:border-foreground/30 hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    >
        <span class="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
            <component :is="icon" class="size-5" aria-hidden="true" />
        </span>
        <span class="min-w-0">
            <span class="block font-semibold group-hover:underline">{{ title }}</span>
            <span class="mt-0.5 block text-sm text-muted-foreground">{{ description }}</span>
        </span>
    </Link>
</template>
