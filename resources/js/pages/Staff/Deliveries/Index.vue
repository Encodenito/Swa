<script setup lang="ts">
import Pagination from '@/components/shop/Pagination.vue';
import DeliveryStatusChip from '@/components/shop/staff/DeliveryStatusChip.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import { Button } from '@/components/ui/button';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDate, formatDateTime } from '@/lib/dates';
import type { Paginated } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

interface DeliveryRow {
    id: number;
    supplier: string;
    reference: string | null;
    status: string;
    expected_at: string | null;
    received_at: string | null;
    lines_count: number;
}

defineProps<{ deliveries: Paginated<DeliveryRow> }>();

const breadcrumbs = [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Stock Receiving', href: route('staff.deliveries.index') },
];

function when(d: DeliveryRow): string {
    if (d.received_at) return `Received ${formatDateTime(d.received_at)}`;
    if (d.expected_at) return `Expected ${formatDate(d.expected_at)}`;

    return 'No expected date';
}

function lines(n: number): string {
    return n === 1 ? '1 item' : `${n} items`;
}
</script>

<template>
    <Head title="Stock Receiving" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="flex flex-col gap-3 border-b border-border pb-6 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-0.5">
                    <h2 class="text-xl font-semibold tracking-tight">Stock Receiving</h2>
                    <p class="text-sm text-muted-foreground">
                        Supplier deliveries. Stock only becomes available to customers once a delivery is received.
                    </p>
                </div>
                <Button as-child class="shrink-0">
                    <Link :href="route('staff.deliveries.create')"><Plus aria-hidden="true" /> Record a delivery</Link>
                </Button>
            </div>

            <EmptyState
                v-if="deliveries.data.length === 0"
                title="No deliveries recorded yet"
                description="Record a delivery when you order from a supplier, then receive it here when it arrives."
                class="mt-6"
            >
                <Button as-child variant="outline">
                    <Link :href="route('staff.deliveries.create')">Record a delivery</Link>
                </Button>
            </EmptyState>

            <template v-else>
                <div class="mt-6 hidden overflow-hidden rounded-lg border border-border md:block">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">Supplier</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Reference</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Status</th>
                                <th scope="col" class="px-4 py-2.5 font-medium">Date</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">Items</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="d in deliveries.data" :key="d.id" class="hover:bg-accent/50">
                                <td class="px-4 py-3">
                                    <Link
                                        :href="route('staff.deliveries.show', { delivery: d.id })"
                                        class="font-medium underline-offset-4 hover:underline"
                                        >{{ d.supplier }}</Link
                                    >
                                </td>
                                <td class="px-4 py-3">{{ d.reference ?? '—' }}</td>
                                <td class="px-4 py-3"><DeliveryStatusChip :status="d.status" /></td>
                                <td class="px-4 py-3 text-muted-foreground">{{ when(d) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ d.lines_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ul class="mt-6 divide-y divide-border rounded-lg border border-border md:hidden">
                    <li v-for="d in deliveries.data" :key="d.id">
                        <Link :href="route('staff.deliveries.show', { delivery: d.id })" class="block space-y-1 p-4 hover:bg-accent/50">
                            <span class="flex items-center justify-between gap-2">
                                <span class="font-medium">{{ d.supplier }}</span>
                                <DeliveryStatusChip :status="d.status" />
                            </span>
                            <span class="block text-sm text-muted-foreground">
                                <template v-if="d.reference">Ref {{ d.reference }}, </template>{{ lines(d.lines_count) }}
                            </span>
                            <span class="block text-sm text-muted-foreground">{{ when(d) }}</span>
                        </Link>
                    </li>
                </ul>

                <Pagination :links="deliveries.links" />
            </template>
        </div>
    </StaffLayout>
</template>
