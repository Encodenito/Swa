<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/shop/Pagination.vue';
import Chip from '@/components/shop/admin/Chip.vue';
import TagChip from '@/components/shop/crm/TagChip.vue';
import { manilaIso, type TagRef } from '@/components/shop/crm/crm';
import LoadingRegion from '@/components/shop/skeleton/LoadingRegion.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import NativeSelect from '@/components/shop/staff/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDate } from '@/lib/dates';
import type { Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface CustomerRow {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    active: boolean;
    tags: TagRef[];
    reservations_count: number;
    completed_count: number;
    open_follow_ups_count: number;
    last_reservation_at: string | null;
}

const props = defineProps<{
    customers: Paginated<CustomerRow>;
    filters: { q: string | null; tag: string | null; segment: string | null };
    segments: { value: string; label: string }[];
    tags: TagRef[];
}>();

const breadcrumbs = [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Customers', href: route('staff.customers.index') },
];

const q = ref(props.filters.q ?? '');
const segment = ref<string>(props.filters.segment ?? '');
const tag = ref<string>(props.filters.tag ?? '');
const searching = ref(false);

const filtered = computed(() => !!(props.filters.q || props.filters.segment || props.filters.tag));

function search() {
    const params: Record<string, string> = {};
    if (q.value.trim() !== '') params.q = q.value.trim();
    if (segment.value !== '') params.segment = segment.value;
    if (tag.value !== '') params.tag = tag.value;

    router.get(route('staff.customers.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (searching.value = true),
        onFinish: () => (searching.value = false),
    });
}

function toggleTag(slug: string) {
    tag.value = tag.value === slug ? '' : slug;
    search();
}

function clear() {
    q.value = '';
    segment.value = '';
    tag.value = '';
    search();
}

const showHref = (c: CustomerRow) => route('staff.customers.show', { customer: c.id });
const lastReservation = (c: CustomerRow) => (c.last_reservation_at ? formatDate(manilaIso(c.last_reservation_at)) : 'None yet');
const followUpLabel = (n: number) => (n === 1 ? '1 open follow-up' : `${n} open follow-ups`);
</script>

<template>
    <Head title="Customers" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading title="Customers" description="Search by name, email or mobile number, or pick a segment or tag." />

            <form role="search" class="mb-4 grid gap-3 sm:grid-cols-[1fr_16rem_auto] sm:items-end" @submit.prevent="search">
                <div class="grid gap-1.5">
                    <Label for="customer-search">Search</Label>
                    <Input id="customer-search" v-model="q" type="search" placeholder="e.g. Juan or 0917" autocomplete="off" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="customer-segment">Segment</Label>
                    <NativeSelect id="customer-segment" v-model="segment" @change="search">
                        <option value="">All customers</option>
                        <option v-for="s in segments" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </NativeSelect>
                </div>
                <div class="flex gap-2">
                    <Button type="submit" class="h-10 flex-1 sm:flex-none" :disabled="searching">{{ searching ? 'Searching…' : 'Search' }}</Button>
                    <Button v-if="filtered" type="button" variant="outline" class="h-10" :disabled="searching" @click="clear">Clear</Button>
                </div>
            </form>

            <div v-if="tags.length > 0" role="group" aria-label="Filter by tag" class="mb-6 flex flex-wrap gap-2">
                <button
                    v-for="t in tags"
                    :key="t.slug"
                    type="button"
                    :aria-pressed="tag === t.slug"
                    :disabled="searching"
                    :class="[
                        'inline-flex min-h-9 items-center rounded-full border px-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                        tag === t.slug ? 'border-foreground' : 'border-transparent opacity-80 hover:opacity-100',
                    ]"
                    @click="toggleTag(t.slug)"
                >
                    <TagChip :name="t.name" :colour="t.colour" />
                </button>
            </div>

            <LoadingRegion :loading="searching">
                <EmptyState
                    v-if="customers.data.length === 0"
                    :title="filtered ? 'No customers match' : 'No customers yet'"
                    :description="
                        filtered ? 'Try a different name, number, segment or tag.' : 'Customers appear here as soon as they create an account.'
                    "
                />

                <template v-else>
                    <p class="mb-2 text-sm text-muted-foreground">Showing {{ customers.from }}–{{ customers.to }} of {{ customers.total }}</p>

                    <!-- Desktop table -->
                    <div class="hidden overflow-hidden rounded-lg border border-border md:block">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Customer</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Tags</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-medium">Reservations</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Last reservation</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="c in customers.data" :key="c.id" class="align-top hover:bg-accent/50">
                                    <td class="px-4 py-3">
                                        <Link :href="showHref(c)" class="font-medium underline-offset-4 hover:underline">{{ c.name }}</Link>
                                        <span class="block text-muted-foreground">{{ c.email }}</span>
                                        <span v-if="c.phone" class="block text-muted-foreground">{{ c.phone }}</span>
                                        <span class="mt-1 flex flex-wrap gap-1">
                                            <Chip v-if="!c.active" tone="danger">Deactivated</Chip>
                                            <Chip v-if="c.open_follow_ups_count > 0" tone="warning">{{
                                                followUpLabel(c.open_follow_ups_count)
                                            }}</Chip>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span v-if="c.tags.length" class="flex flex-wrap gap-1">
                                            <TagChip v-for="t in c.tags" :key="t.slug" :name="t.name" :colour="t.colour" />
                                        </span>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        {{ c.reservations_count }}
                                        <span class="block text-xs text-muted-foreground">{{ c.completed_count }} completed</span>
                                    </td>
                                    <td class="px-4 py-3">{{ lastReservation(c) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Phone cards -->
                    <ul class="divide-y divide-border rounded-lg border border-border md:hidden">
                        <li v-for="c in customers.data" :key="c.id">
                            <Link :href="showHref(c)" class="block space-y-1.5 p-4 hover:bg-accent/50">
                                <span class="flex items-start justify-between gap-2">
                                    <span class="min-w-0">
                                        <span class="block font-medium">{{ c.name }}</span>
                                        <span class="block truncate text-sm text-muted-foreground">{{ c.email }}</span>
                                        <span v-if="c.phone" class="block text-sm text-muted-foreground">{{ c.phone }}</span>
                                    </span>
                                    <Chip v-if="!c.active" tone="danger" class="shrink-0">Deactivated</Chip>
                                </span>
                                <span v-if="c.tags.length || c.open_follow_ups_count > 0" class="flex flex-wrap gap-1">
                                    <Chip v-if="c.open_follow_ups_count > 0" tone="warning">{{ followUpLabel(c.open_follow_ups_count) }}</Chip>
                                    <TagChip v-for="t in c.tags" :key="t.slug" :name="t.name" :colour="t.colour" />
                                </span>
                                <span class="flex justify-between gap-2 text-sm text-muted-foreground">
                                    <span>{{ c.reservations_count }} reservations · {{ c.completed_count }} completed</span>
                                    <span class="shrink-0">Last: {{ lastReservation(c) }}</span>
                                </span>
                            </Link>
                        </li>
                    </ul>

                    <Pagination :links="customers.links" />
                </template>
            </LoadingRegion>
        </div>
    </StaffLayout>
</template>
