<script setup lang="ts">
import Pagination from '@/components/shop/Pagination.vue';
import StatusBadge from '@/components/shop/StatusBadge.vue';
import EmptyState from '@/components/shop/customer/EmptyState.vue';
import AccountLayout from '@/layouts/AccountLayout.vue';
import { formatDate, formatDateTime, formatDeadline } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { Paginated, ReservationSummary } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';

defineProps<{
    reservations: Paginated<ReservationSummary>;
}>();

const breadcrumbs = [{ title: 'My reservations', href: route('account.home') }];

function itemCount(r: ReservationSummary): string | null {
    if (r.item_count === null) return null;

    return `${r.item_count} ${r.item_count === 1 ? 'item' : 'items'}`;
}
</script>

<template>
    <Head title="My reservations" />

    <AccountLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl px-4 py-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">My reservations</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Show the reference code at the counter when you pick up.</p>
                </div>
                <Link
                    :href="route('items.index')"
                    class="inline-flex h-10 items-center rounded-md border border-border bg-card px-4 text-sm font-semibold hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                >
                    Browse items
                </Link>
            </div>

            <EmptyState
                v-if="reservations.data.length === 0"
                class="mt-6"
                title="No reservations yet"
                description="Reserve an item with a downpayment and it shows up here with its pickup deadline."
            >
                <Link
                    :href="route('items.index')"
                    class="inline-flex h-11 items-center rounded-md bg-primary px-5 font-semibold text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                >
                    Browse items
                </Link>
            </EmptyState>

            <ul v-else class="mt-6 space-y-3">
                <li v-for="reservation in reservations.data" :key="reservation.reference_code">
                    <Link
                        :href="route('account.reservations.show', { reservation: reservation.reference_code })"
                        class="group flex items-center gap-3 rounded-lg border border-border bg-card p-4 hover:border-primary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    >
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="font-mono text-lg font-bold tracking-wide">{{ reservation.reference_code }}</span>
                                <StatusBadge :status="reservation.status" :label="reservation.status_label" />
                            </div>
                            <p class="text-sm text-muted-foreground">
                                <span class="font-semibold tabular-nums text-foreground">{{ formatPeso(reservation.total) }}</span>
                                <template v-if="itemCount(reservation)"> for {{ itemCount(reservation) }}</template>
                                <template v-if="reservation.created_at">, reserved {{ formatDate(reservation.created_at) }}</template>
                            </p>
                            <p
                                v-if="reservation.status === 'pending_payment' && reservation.expires_at"
                                class="text-sm font-medium text-amber-800 dark:text-amber-300"
                            >
                                Pay by {{ formatDateTime(reservation.expires_at) }}
                            </p>
                            <p
                                v-else-if="reservation.pickup_by && (reservation.status === 'confirmed' || reservation.status === 'processing')"
                                class="text-sm font-medium"
                            >
                                Pick up by {{ formatDeadline(reservation.pickup_by) }}
                            </p>
                        </div>
                        <ChevronRight class="size-5 shrink-0 text-muted-foreground group-hover:text-primary" aria-hidden="true" />
                    </Link>
                </li>
            </ul>

            <Pagination :links="reservations.links" />
        </div>
    </AccountLayout>
</template>
