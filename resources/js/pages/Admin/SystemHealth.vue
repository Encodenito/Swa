<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatDateTime } from '@/lib/dates';
import type { HealthCheck } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, OctagonAlert, RefreshCw, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    checks: HealthCheck[];
    summary: Partial<Record<'ok' | 'warn' | 'fail', number>>;
    checkedAt: string;
}>();

const breadcrumbs = [
    { title: 'Admin dashboard', href: route('admin.home') },
    { title: 'System health', href: route('admin.system-health') },
];

const groups = computed(() => {
    const byGroup = new Map<string, HealthCheck[]>();
    for (const check of props.checks) {
        byGroup.set(check.group, [...(byGroup.get(check.group) ?? []), check]);
    }

    return [...byGroup.entries()].map(([name, checks]) => ({ name, checks }));
});

const statusText: Record<HealthCheck['status'], string> = { ok: 'Pass', warn: 'Warning', fail: 'Problem' };
</script>

<template>
    <Head title="System health" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl px-4 py-6">
            <Heading
                title="System health"
                description="The same checks as `php artisan swa:preflight`. Fix every problem before going live; warnings are fine while testing."
            />

            <div class="mb-6 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <p>
                    <span class="font-semibold tabular-nums">{{ summary.fail ?? 0 }}</span> problems
                </p>
                <p>
                    <span class="font-semibold tabular-nums">{{ summary.warn ?? 0 }}</span> warnings
                </p>
                <p>
                    <span class="font-semibold tabular-nums">{{ summary.ok ?? 0 }}</span> passed
                </p>
                <p class="text-muted-foreground">Checked {{ formatDateTime(checkedAt) }}</p>
                <Link
                    :href="route('admin.system-health')"
                    preserve-scroll
                    class="inline-flex h-9 items-center gap-1.5 rounded-md border border-border bg-card px-3 font-medium hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <RefreshCw class="size-4" aria-hidden="true" />
                    Check again
                </Link>
            </div>

            <section v-for="group in groups" :key="group.name" :aria-labelledby="`group-${group.name}`" class="mb-6">
                <h3 :id="`group-${group.name}`" class="mb-2 font-semibold">{{ group.name }}</h3>
                <ul class="divide-y divide-border rounded-lg border border-border bg-card">
                    <li v-for="check in group.checks" :key="check.key" class="flex gap-3 p-4 text-sm">
                        <CheckCircle2
                            v-if="check.status === 'ok'"
                            class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                            aria-hidden="true"
                        />
                        <TriangleAlert
                            v-else-if="check.status === 'warn'"
                            class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400"
                            aria-hidden="true"
                        />
                        <OctagonAlert v-else class="mt-0.5 size-5 shrink-0 text-red-600 dark:text-red-400" aria-hidden="true" />
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ check.label }}
                                <span class="ml-1 text-xs font-normal text-muted-foreground">{{ statusText[check.status] }}</span>
                            </p>
                            <p class="text-muted-foreground">{{ check.detail }}</p>
                            <p v-if="check.fix && check.status !== 'ok'" class="mt-1 break-words">What to do: {{ check.fix }}</p>
                        </div>
                    </li>
                </ul>
            </section>

            <p class="text-sm text-muted-foreground">Step-by-step launch instructions are in <span class="font-mono">docs/DEPLOYMENT.md</span>.</p>
        </div>
    </AdminLayout>
</template>
