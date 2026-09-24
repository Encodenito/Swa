<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/shop/Pagination.vue';
import StatusBadge from '@/components/shop/StatusBadge.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import NativeSelect from '@/components/shop/staff/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime, formatDeadline } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { Paginated, ReservationSummary } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    reservations: Paginated<ReservationSummary>;
    filters: { status: string | null; q: string };
    statuses: { value: string; label: string }[];
}>();

const breadcrumbs = [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Reservations', href: route('staff.reservations.index') },
];

const q = ref(props.filters.q ?? '');
const status = ref<string>(props.filters.status ?? '');
const searching = ref(false);

function search() {
    const params: Record<string, string> = {};
    if (q.value.trim() !== '') params.q = q.value.trim();
    if (status.value !== '') params.status = status.value;

    router.get(route('staff.reservations.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (searching.value = true),
        onFinish: () => (searching.value = false),
    });
}

function clear() {
    q.value = '';
    status.value = '';
    search();
}

function deadline(r: ReservationSummary): { label: string; value: string } | null {
    if (r.pickup_by) return { label: 'Pick up by', value: formatDeadline(r.pickup_by) };
    if (r.status === 'pending_payment' && r.expires_at) return { label: 'Pay by', value: formatDateTime(r.expires_at) };

    return null;
}
</script>

<template>
    <Head title="Reservations" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading title="Reservations" description="Search by reference code, customer name, email or mobile number." />

            <form role="search" class="mb-6 grid gap-3 sm:grid-cols-[1fr_14rem_auto] sm:items-end" @submit.prevent="search">
                <div class="grid gap-1.5">
                    <Label for="reservation-search">Search</Label>
                    <Input id="reservation-search" v-model="q" type="search" placeholder="e.g. SWA-7K2P9QXM or juan@example.com" autocomplete="off" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="reservation-status">Status</Label>
                    <NativeSelect id="reservation-status" v-model="status" @change="search">
                        <option value="">All statuses</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </NativeSelect>
                </div>
                <div class="flex gap-2">
                    <Button type="submit" class="h-10 flex-1 sm:flex-none" :disabled="searching">{{ searching ? 'Searching…' : 'Search' }}</Button>
                    <Button v-if="filters.q || filters.status" type="button" variant="outline" class="h-10" :disabled="searching" @click="clear">
                        Clear
                    </Button>
                </div>
            </form>

            <div :class="['transition-opacity', searching ? 'opacity-60' : '']" :aria-busy="searching">
                <EmptyState
                    v-if="reservations.data.length === 0"
                    :title="filters.q || filters.status ? 'No reservations match your search' : 'No reservations yet'"
                    :description="
                        filters.q || filters.status
                            ? 'Check the reference code or try a different status.'
                            : 'Reservations appear here as soon as customers place them.'
                    "
                />

                <template v-else>
                    <p class="mb-2 text-sm text-muted-foreground">
                        Showing {{ reservations.from }}–{{ reservations.to }} of {{ reservations.total }}
                    </p>

                    <!-- Desktop table -->
                    <div class="hidden overflow-hidden rounded-lg border border-border md:block">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Reference</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Customer</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Status</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-medium">Total</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Deadline</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="r in reservations.data" :key="r.reference_code" class="hover:bg-accent/50">
                                    <td class="px-4 py-3">
                                        <Link
                                            :href="route('staff.reservations.show', { reservation: r.reference_code })"
                                            class="font-mono font-semibold underline-offset-4 hover:underline"
                                            >{{ r.reference_code }}</Link
                                        >
                                    </td>
                                    <td class="px-4 py-3">
                                        <template v-if="r.customer">
                                            <span class="block font-medium">{{ r.customer.name }}</span>
                                            <span class="block text-muted-foreground">{{ r.customer.phone ?? r.customer.email }}</span>
                                        </template>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="px-4 py-3"><StatusBadge :status="r.status" :label="r.status_label" /></td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ formatPeso(r.total) }}</td>
                                    <td class="px-4 py-3">
                                        <template v-if="deadline(r)">
                                            <span class="block text-muted-foreground">{{ deadline(r)!.label }}</span>
                                            <span class="block">{{ deadline(r)!.value }}</span>
                                        </template>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Phone cards -->
                    <ul class="divide-y divide-border rounded-lg border border-border md:hidden">
                        <li v-for="r in reservations.data" :key="r.reference_code">
                            <Link
                                :href="route('staff.reservations.show', { reservation: r.reference_code })"
                                class="block space-y-1.5 p-4 hover:bg-accent/50"
                            >
                                <span class="flex items-center justify-between gap-2">
                                    <span class="font-mono font-semibold">{{ r.reference_code }}</span>
                                    <StatusBadge :status="r.status" :label="r.status_label" />
                                </span>
                                <span v-if="r.customer" class="block text-sm">
                                    <span class="block">{{ r.customer.name }}</span>
                                    <span class="block text-muted-foreground">{{ r.customer.phone ?? r.customer.email }}</span>
                                </span>
                                <span class="flex items-center justify-between gap-2 text-sm">
                                    <span class="text-muted-foreground">
                                        <template v-if="deadline(r)">{{ deadline(r)!.label }} {{ deadline(r)!.value }}</template>
                                    </span>
                                    <span class="font-semibold tabular-nums">{{ formatPeso(r.total) }}</span>
                                </span>
                            </Link>
                        </li>
                    </ul>

                    <Pagination :links="reservations.links" />
                </template>
            </div>
        </div>
    </StaffLayout>
</template>
