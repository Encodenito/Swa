<script setup lang="ts">
import type { HealthCheck } from '@/types';
import { AlertTriangle, OctagonAlert } from 'lucide-vue-next';

/** Problems with background services (scheduler, queue worker, failed jobs). Shown only when there are any. */
defineProps<{ checks: HealthCheck[] }>();
</script>

<template>
    <section v-if="checks.length" aria-labelledby="health-heading" class="mb-6 rounded-lg border border-danger-border bg-danger-surface p-4">
        <h2 id="health-heading" class="flex items-center gap-2 font-semibold text-danger-strong">
            <OctagonAlert class="size-5 shrink-0" aria-hidden="true" />
            Something in the background needs attention
        </h2>
        <ul class="mt-3 space-y-3 text-sm">
            <li v-for="check in checks" :key="check.key" class="flex gap-2">
                <AlertTriangle :class="['mt-0.5 size-4 shrink-0', check.status === 'fail' ? 'text-danger' : 'text-warning']" aria-hidden="true" />
                <div class="min-w-0">
                    <p class="font-medium text-foreground">
                        {{ check.label }}
                        <span class="sr-only">({{ check.status === 'fail' ? 'problem' : 'warning' }})</span>
                    </p>
                    <p class="text-muted-foreground">{{ check.detail }}</p>
                    <p v-if="check.fix" class="mt-1 break-words text-foreground">What to do: {{ check.fix }}</p>
                </div>
            </li>
        </ul>
    </section>
</template>
