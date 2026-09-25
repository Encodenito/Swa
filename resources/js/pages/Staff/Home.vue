<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import StatusBadge from '@/components/shop/StatusBadge.vue';
import DashboardCard from '@/components/shop/crm/DashboardCard.vue';
import { dateOnlyIso } from '@/components/shop/crm/crm';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { ReservationStatus } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ReceiptText, ScanLine, Truck, UserPlus } from 'lucide-vue-next';

interface ReservationItem {
    reference_code: string;
    status: ReservationStatus;
    status_label: string;
    when: string | null;
    balance: string;
    customer: string | null;
}

interface ReservationCard {
    count: number;
    items: ReservationItem[];
}

defineProps<{
    summary: {
        pickups_today: ReservationCard;
        overdue_pickups: ReservationCard;
        expiring_soon: ReservationCard;
        payments_to_review: { count: number; needs_review: number; refund_failed: number; orphans: number };
        open_handoffs: number;
        follow_ups_due: {
            count: number;
            overdue: number;
            items: { id: number; due_on: string; note: string; customer: { id: number; name: string | null } }[];
        };
        low_stock: {
            count: number;
            items: { id: number; name: string; slug: string; sku: string | null; available: number; threshold: number }[];
        };
    };
}>();

const breadcrumbs = [{ title: 'Staff dashboard', href: route('staff.home') }];

const quickActions = [
    { title: 'Pickup counter', href: route('staff.pickup'), icon: ScanLine },
    { title: 'Walk-in sale', href: route('staff.walk-ins.create'), icon: ReceiptText },
    { title: 'Reserve for a customer', href: route('staff.customer-reservations.create'), icon: UserPlus },
    { title: 'Stock Receiving', href: route('staff.deliveries.index'), icon: Truck },
];

const pickupHref = (code: string) => route('staff.pickup', { q: code });
const listLink = 'block rounded-md px-2 py-1.5 -mx-2 hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
</script>

<template>
    <Head title="Staff dashboard" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading title="Staff dashboard" description="What needs doing today, at a glance." />

            <nav aria-label="Quick actions" class="mb-6">
                <ul class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                    <li v-for="action in quickActions" :key="action.href">
                        <Link
                            :href="action.href"
                            class="flex h-11 items-center gap-2 rounded-md border border-border bg-card px-3 text-sm font-medium hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            <component :is="action.icon" class="size-4 shrink-0" aria-hidden="true" />
                            <span class="truncate">{{ action.title }}</span>
                        </Link>
                    </li>
                </ul>
            </nav>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <!-- Pickups today -->
                <DashboardCard
                    title="Pickups due today"
                    :count="summary.pickups_today.count"
                    :href="route('staff.pickup')"
                    link-label="Open the pickup counter"
                    empty-text="No pickups due today."
                >
                    <ul class="space-y-1">
                        <li v-for="r in summary.pickups_today.items" :key="r.reference_code">
                            <Link :href="pickupHref(r.reference_code)" :class="listLink">
                                <span class="flex items-center justify-between gap-2">
                                    <span class="font-mono font-semibold">{{ r.reference_code }}</span>
                                    <StatusBadge :status="r.status" :label="r.status_label" />
                                </span>
                                <span class="flex justify-between gap-2 text-muted-foreground">
                                    <span class="truncate">{{ r.customer ?? 'Customer' }}</span>
                                    <span class="shrink-0 tabular-nums">Balance {{ formatPeso(r.balance) }}</span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </DashboardCard>

                <!-- Overdue pickups -->
                <DashboardCard
                    title="Overdue pickups"
                    :count="summary.overdue_pickups.count"
                    :href="route('staff.pickup')"
                    link-label="Open the pickup counter"
                    :attention="summary.overdue_pickups.count > 0 ? 'red' : null"
                    attention-label="Overdue"
                    empty-text="No pickups are past their deadline."
                >
                    <ul class="space-y-1">
                        <li v-for="r in summary.overdue_pickups.items" :key="r.reference_code">
                            <Link :href="pickupHref(r.reference_code)" :class="listLink">
                                <span class="flex items-center justify-between gap-2">
                                    <span class="font-mono font-semibold">{{ r.reference_code }}</span>
                                    <span class="text-xs text-red-700 dark:text-red-400">Due {{ formatDate(r.when) }}</span>
                                </span>
                                <span class="flex justify-between gap-2 text-muted-foreground">
                                    <span class="truncate">{{ r.customer ?? 'Customer' }}</span>
                                    <span class="shrink-0 tabular-nums">Balance {{ formatPeso(r.balance) }}</span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </DashboardCard>

                <!-- Expiring soon -->
                <DashboardCard
                    title="Unpaid, expiring in 10 minutes"
                    :count="summary.expiring_soon.count"
                    :href="route('staff.reservations.index', { status: 'pending_payment' })"
                    link-label="See unpaid reservations"
                    empty-text="No unpaid reservations are about to expire."
                >
                    <ul class="space-y-1">
                        <li v-for="r in summary.expiring_soon.items" :key="r.reference_code">
                            <Link :href="route('staff.reservations.show', { reservation: r.reference_code })" :class="listLink">
                                <span class="flex items-center justify-between gap-2">
                                    <span class="font-mono font-semibold">{{ r.reference_code }}</span>
                                    <span class="text-xs text-muted-foreground">Expires {{ formatDateTime(r.when) }}</span>
                                </span>
                                <span class="block truncate text-muted-foreground">{{ r.customer ?? 'Customer' }}</span>
                            </Link>
                        </li>
                    </ul>
                </DashboardCard>

                <!-- Payments to review -->
                <DashboardCard
                    title="Payments to review"
                    :count="summary.payments_to_review.count + summary.payments_to_review.orphans"
                    :href="route('staff.payments.review')"
                    link-label="Review payments"
                    :attention="
                        summary.payments_to_review.refund_failed > 0
                            ? 'red'
                            : summary.payments_to_review.count + summary.payments_to_review.orphans > 0
                              ? 'amber'
                              : null
                    "
                    :attention-label="summary.payments_to_review.refund_failed > 0 ? 'Refund failed' : 'To review'"
                    empty-text="Every payment has been applied."
                >
                    <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1">
                        <dt class="text-muted-foreground">Needs review</dt>
                        <dd class="text-right font-medium tabular-nums">{{ summary.payments_to_review.needs_review }}</dd>
                        <dt class="text-muted-foreground">Refunds failed</dt>
                        <dd class="text-right font-medium tabular-nums">{{ summary.payments_to_review.refund_failed }}</dd>
                        <dt class="text-muted-foreground">Unmatched payments</dt>
                        <dd class="text-right font-medium tabular-nums">{{ summary.payments_to_review.orphans }}</dd>
                    </dl>
                </DashboardCard>

                <!-- Open handoffs -->
                <DashboardCard
                    title="Chats waiting for staff"
                    :count="summary.open_handoffs"
                    :href="route('staff.handoffs.index')"
                    link-label="Open handoffs"
                    :attention="summary.open_handoffs > 0 ? 'amber' : null"
                    attention-label="Waiting"
                    empty-text="No customer is waiting for a reply."
                >
                    <p class="text-muted-foreground">
                        {{ summary.open_handoffs === 1 ? '1 customer asked' : `${summary.open_handoffs} customers asked` }} the chatbot for a person.
                    </p>
                </DashboardCard>

                <!-- Follow-ups -->
                <DashboardCard
                    title="Follow-ups due"
                    :count="summary.follow_ups_due.count"
                    :href="route('staff.follow-ups.index', { tab: summary.follow_ups_due.overdue > 0 ? 'overdue' : 'today' })"
                    link-label="See follow-ups"
                    :attention="summary.follow_ups_due.overdue > 0 ? 'red' : null"
                    :attention-label="`${summary.follow_ups_due.overdue} overdue`"
                    empty-text="No follow-ups due today."
                >
                    <ul class="space-y-1">
                        <li v-for="f in summary.follow_ups_due.items" :key="f.id">
                            <Link :href="route('staff.customers.show', { customer: f.customer.id })" :class="listLink">
                                <span class="flex items-center justify-between gap-2">
                                    <span class="truncate font-medium">{{ f.customer.name ?? 'Customer' }}</span>
                                    <span class="shrink-0 text-xs text-muted-foreground">{{ formatDate(dateOnlyIso(f.due_on)) }}</span>
                                </span>
                                <span class="block truncate text-muted-foreground">{{ f.note }}</span>
                            </Link>
                        </li>
                    </ul>
                </DashboardCard>

                <!-- Low stock -->
                <DashboardCard
                    title="Low stock"
                    :count="summary.low_stock.count"
                    :href="route('staff.stock.index')"
                    link-label="See all stock"
                    empty-text="Every item is above its low-stock level."
                >
                    <ul class="space-y-1">
                        <li v-for="item in summary.low_stock.items" :key="item.id">
                            <Link :href="route('staff.stock.show', { item: item.slug })" :class="listLink">
                                <span class="flex items-center justify-between gap-2">
                                    <span class="truncate font-medium">{{ item.name }}</span>
                                    <span
                                        :class="[
                                            'shrink-0 text-xs font-semibold tabular-nums',
                                            item.available === 0 ? 'text-red-700 dark:text-red-400' : 'text-amber-800 dark:text-amber-300',
                                        ]"
                                    >
                                        {{ item.available === 0 ? 'Out of stock' : `${item.available} left` }}
                                    </span>
                                </span>
                                <span class="block text-xs text-muted-foreground">
                                    <template v-if="item.sku">{{ item.sku }} · </template>Low at {{ item.threshold }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </DashboardCard>
            </div>
        </div>
    </StaffLayout>
</template>
