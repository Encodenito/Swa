<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/shop/Pagination.vue';
import FollowUpItem from '@/components/shop/crm/FollowUpItem.vue';
import type { FollowUpRow } from '@/components/shop/crm/crm';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

type Tab = 'overdue' | 'today' | 'upcoming' | 'done';

const props = defineProps<{
    tab: Tab;
    followUps: Paginated<FollowUpRow>;
    counts: { overdue: number; today: number; upcoming: number };
}>();

const breadcrumbs = [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Follow-ups', href: route('staff.follow-ups.index') },
];

const tabs = computed(() => [
    { value: 'overdue' as const, label: 'Overdue', count: props.counts.overdue as number | null },
    { value: 'today' as const, label: 'Due today', count: props.counts.today as number | null },
    { value: 'upcoming' as const, label: 'Upcoming', count: props.counts.upcoming as number | null },
    { value: 'done' as const, label: 'Done', count: null },
]);

const empty: Record<Tab, { title: string; description: string }> = {
    overdue: { title: 'Nothing overdue', description: 'Every follow-up past its due date has been done.' },
    today: { title: 'Nothing due today', description: 'Follow-ups due today appear here.' },
    upcoming: { title: 'Nothing scheduled', description: 'Add a follow-up from a customer’s page to plan a call or message.' },
    done: { title: 'No finished follow-ups yet', description: 'Follow-ups you mark done appear here, newest first.' },
};
</script>

<template>
    <Head title="Follow-ups" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading title="Follow-ups" description="Calls and messages staff planned for customers." />

            <nav aria-label="Follow-up lists" class="-mx-4 mb-6 overflow-x-auto px-4">
                <ul class="inline-flex min-w-full gap-1 rounded-md border border-border bg-muted p-1 sm:min-w-0">
                    <li v-for="t in tabs" :key="t.value" class="flex-1 sm:flex-none">
                        <Link
                            :href="route('staff.follow-ups.index', { tab: t.value })"
                            preserve-scroll
                            :aria-current="tab === t.value ? 'page' : undefined"
                            :class="[
                                'flex h-9 items-center justify-center gap-1.5 whitespace-nowrap rounded px-3 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                tab === t.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                            ]"
                        >
                            {{ t.label }}
                            <span
                                v-if="t.count !== null"
                                :class="[
                                    'rounded-full px-1.5 text-xs tabular-nums',
                                    t.value === 'overdue' && t.count > 0
                                        ? 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100'
                                        : 'bg-background/70 text-muted-foreground',
                                ]"
                                >{{ t.count }}</span
                            >
                        </Link>
                    </li>
                </ul>
            </nav>

            <EmptyState v-if="followUps.data.length === 0" :title="empty[tab].title" :description="empty[tab].description">
                <Link :href="route('staff.customers.index')" class="text-sm font-medium underline underline-offset-4">Find a customer</Link>
            </EmptyState>

            <template v-else>
                <p class="mb-2 text-sm text-muted-foreground">Showing {{ followUps.from }}–{{ followUps.to }} of {{ followUps.total }}</p>
                <ul class="divide-y divide-border rounded-lg border border-border bg-card">
                    <li v-for="f in followUps.data" :key="f.id">
                        <FollowUpItem :follow-up="f" show-customer />
                    </li>
                </ul>
                <Pagination :links="followUps.links" />
            </template>
        </div>
    </StaffLayout>
</template>
