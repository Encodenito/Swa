<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/shop/StatusBadge.vue';
import CancelReservationDialog from '@/components/shop/customer/CancelReservationDialog.vue';
import { Button } from '@/components/ui/button';
import AccountLayout from '@/layouts/AccountLayout.vue';
import { formatDate, formatDateTime, formatDeadline } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { PaymentRow, ReservationDetail } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Clock, LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    reservation: ReservationDetail;
}>();

const breadcrumbs = computed(() => [
    { title: 'My reservations', href: route('account.home') },
    { title: props.reservation.reference_code, href: route('account.reservations.show', { reservation: props.reservation.reference_code }) },
]);

const status = computed(() => props.reservation.status);
const isPending = computed(() => status.value === 'pending_payment');
const showCode = computed(() => status.value === 'confirmed' || status.value === 'processing');

const downpaymentLabel = computed(() => {
    if (isPending.value) return 'Downpayment to pay now';
    if (status.value === 'expired') return 'Downpayment (not paid)';

    return 'Downpayment paid';
});

const balanceLabel = computed(() => (['pending_payment', 'confirmed', 'processing'].includes(status.value) ? 'Balance due at pickup' : 'Balance'));

const keyDates = computed(() => {
    const r = props.reservation;
    const rows: { label: string; value: string }[] = [];
    if (r.created_at) rows.push({ label: 'Reserved', value: formatDateTime(r.created_at) });
    if (isPending.value && r.expires_at) rows.push({ label: 'Pay by', value: formatDateTime(r.expires_at) });
    if (r.confirmed_at) rows.push({ label: 'Confirmed', value: formatDateTime(r.confirmed_at) });
    if (r.processing_at) rows.push({ label: 'Preparation started', value: formatDateTime(r.processing_at) });
    if (r.pickup_by && showCode.value) rows.push({ label: 'Pick up by', value: formatDeadline(r.pickup_by) });
    if (r.completed_at) rows.push({ label: 'Picked up', value: formatDateTime(r.completed_at) });
    if (r.cancelled_at) rows.push({ label: 'Cancelled', value: formatDateTime(r.cancelled_at) });
    if (r.forfeited_at) rows.push({ label: 'Forfeited', value: formatDateTime(r.forfeited_at) });

    return rows;
});

const paymentType: Record<PaymentRow['type'], string> = {
    downpayment: 'Downpayment',
    balance: 'Balance',
    full: 'Full payment',
    refund: 'Refund',
};

const paymentStatus: Record<PaymentRow['status'], string> = {
    paid: 'Paid',
    needs_review: 'Being checked',
    refund_pending: 'Refund on its way',
    refunded: 'Refunded',
    refund_failed: 'Refund delayed — we will contact you',
};

function statusTone(s: PaymentRow['status']): string {
    if (s === 'refund_failed') return 'text-red-700 dark:text-red-400';
    if (s === 'refund_pending' || s === 'needs_review') return 'text-amber-800 dark:text-amber-300';

    return 'text-muted-foreground';
}

const hasRefund = computed(() => props.reservation.payments.some((p) => p.type === 'refund'));

const paymentMethod: Record<PaymentRow['method'], string> = {
    paymongo: 'GCash or card',
    cash: 'Cash',
};

const payForm = useForm({});
const payError = computed(() => Object.values(payForm.errors as Record<string, string>)[0]);

function pay() {
    // The server redirects to PayMongo's hosted checkout.
    payForm.post(route('account.reservations.pay', { reservation: props.reservation.reference_code }), { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Reservation ${reservation.reference_code}`" />

    <AccountLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6">
            <!-- The code is what the customer shows at the counter, so it gets the most weight on the page. -->
            <header class="rounded-lg border border-border bg-card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h1 class="text-sm font-medium text-muted-foreground">Reservation</h1>
                    <StatusBadge :status="reservation.status" :label="reservation.status_label" />
                </div>
                <p class="mt-2 break-all font-mono text-4xl font-bold tracking-wider sm:text-5xl">{{ reservation.reference_code }}</p>
                <p v-if="showCode" class="mt-2 font-medium">Show this code at the counter</p>
                <p v-if="showCode && reservation.pickup_by" class="mt-1 text-sm text-muted-foreground">
                    Pick up by {{ formatDeadline(reservation.pickup_by) }}
                </p>

                <p v-if="status === 'processing'" class="mt-4 rounded-md bg-secondary px-3 py-2 text-sm">
                    We are preparing your order. It can no longer be cancelled.
                </p>

                <div
                    v-if="isPending"
                    class="mt-4 space-y-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
                >
                    <p class="flex items-center gap-2 font-medium">
                        <Clock class="size-4 shrink-0" aria-hidden="true" />
                        Pay by {{ formatDateTime(reservation.expires_at) }}
                    </p>
                    <p v-if="!reservation.can_pay" class="text-sm">
                        The payment window has closed. If you already paid, this page updates once PayMongo confirms.
                    </p>
                    <form v-if="reservation.can_pay" @submit.prevent="pay">
                        <Button type="submit" class="h-11 w-full sm:w-auto sm:px-6" :disabled="payForm.processing">
                            <LoaderCircle v-if="payForm.processing" class="size-4 animate-spin" aria-hidden="true" />
                            Continue to payment
                        </Button>
                    </form>
                    <InputError :message="payError" />
                </div>

                <div v-if="reservation.cancel.allowed" class="mt-4 space-y-3 border-t border-border pt-4">
                    <p class="text-sm text-muted-foreground">
                        Changed your mind? You can cancel until we start preparing your order<template v-if="status === 'confirmed'"
                            >, and your downpayment is refunded in full</template
                        >.
                    </p>
                    <CancelReservationDialog :reservation="reservation" />
                </div>

                <div v-if="status === 'cancelled'" class="mt-4 rounded-md bg-secondary px-3 py-2 text-sm">
                    <p class="font-medium">
                        This reservation was cancelled<template v-if="reservation.cancelled_at">
                            on {{ formatDate(reservation.cancelled_at) }}</template
                        >.
                    </p>
                    <p v-if="reservation.cancel_reason" class="mt-1 text-muted-foreground">Reason: {{ reservation.cancel_reason }}</p>
                    <p v-if="hasRefund" class="mt-1 text-muted-foreground">You can follow the refund under Payments below.</p>
                </div>

                <div
                    v-else-if="status === 'forfeited'"
                    class="mt-4 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-950 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100"
                >
                    <p class="font-medium">
                        Not picked up in time<template v-if="reservation.forfeited_at"
                            >, forfeited on {{ formatDate(reservation.forfeited_at) }}</template
                        >.
                    </p>
                    <p class="mt-1">The items went back on sale. Any refund of part of your downpayment is shown under Payments below.</p>
                </div>

                <p v-else-if="reservation.cancel_reason" class="mt-4 text-sm text-muted-foreground">Reason: {{ reservation.cancel_reason }}</p>
            </header>

            <section aria-labelledby="items-heading" class="rounded-lg border border-border bg-card">
                <h2 id="items-heading" class="px-4 pt-4 text-lg font-semibold">Items</h2>
                <p v-if="reservation.lines.length === 0" class="p-4 text-sm text-muted-foreground">No items on this reservation.</p>
                <div v-else class="overflow-x-auto">
                    <table class="mt-2 w-full text-sm">
                        <thead class="border-b border-border text-left text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-medium">Item</th>
                                <th scope="col" class="px-2 py-2 text-right font-medium">Qty</th>
                                <th scope="col" class="hidden px-2 py-2 text-right font-medium sm:table-cell">Unit price</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="line in reservation.lines" :key="line.id" class="align-top">
                                <td class="px-4 py-3">
                                    <Link
                                        v-if="line.item_slug"
                                        :href="route('items.show', { item: line.item_slug })"
                                        class="font-medium hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {{ line.item_name ?? 'Item' }}
                                    </Link>
                                    <span v-else class="font-medium">{{ line.item_name ?? 'Item no longer listed' }}</span>
                                    <!-- Unit price moves under the name on phones. -->
                                    <span class="block tabular-nums text-muted-foreground sm:hidden">
                                        {{ formatPeso(line.unit_price) }} each<template v-if="line.clearance">, clearance</template>
                                    </span>
                                    <span v-if="line.clearance" class="hidden text-xs text-muted-foreground sm:block">Clearance price</span>
                                </td>
                                <td class="px-2 py-3 text-right tabular-nums">{{ line.quantity }}</td>
                                <td class="hidden px-2 py-3 text-right tabular-nums sm:table-cell">{{ formatPeso(line.unit_price) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatPeso(line.line_total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <dl class="space-y-2 border-t border-border p-4 text-sm">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-muted-foreground">Total</dt>
                        <dd class="font-semibold tabular-nums">{{ formatPeso(reservation.total) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-muted-foreground">{{ downpaymentLabel }}</dt>
                        <dd class="font-semibold tabular-nums">{{ formatPeso(reservation.downpayment_amount) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 border-t border-border pt-2 text-base">
                        <dt class="font-semibold">{{ balanceLabel }}</dt>
                        <dd class="font-bold tabular-nums">{{ formatPeso(reservation.balance) }}</dd>
                    </div>
                </dl>
            </section>

            <section v-if="keyDates.length > 0" aria-labelledby="dates-heading" class="rounded-lg border border-border bg-card p-4">
                <h2 id="dates-heading" class="text-lg font-semibold">Key dates</h2>
                <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div v-for="row in keyDates" :key="row.label">
                        <dt class="text-muted-foreground">{{ row.label }}</dt>
                        <dd class="font-medium">{{ row.value }}</dd>
                    </div>
                </dl>
            </section>

            <section aria-labelledby="payments-heading" class="rounded-lg border border-border bg-card p-4">
                <h2 id="payments-heading" class="text-lg font-semibold">Payments</h2>
                <p v-if="reservation.payments.length === 0" class="mt-2 text-sm text-muted-foreground">
                    {{ isPending ? 'No payment received yet.' : 'No payments recorded.' }}
                </p>
                <ul v-else class="mt-3 divide-y divide-border text-sm">
                    <li
                        v-for="payment in reservation.payments"
                        :key="payment.id"
                        class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"
                    >
                        <div>
                            <p class="font-medium">{{ paymentType[payment.type] ?? payment.type }}</p>
                            <p class="text-muted-foreground">
                                {{ paymentMethod[payment.method] ?? payment.method }}, {{ formatDateTime(payment.created_at) }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold tabular-nums">
                                <span v-if="payment.type === 'refund'" class="sr-only">Refund to you: </span>
                                <span aria-hidden="true">{{ payment.type === 'refund' ? '−' : '' }}</span
                                >{{ formatPeso(payment.amount) }}
                            </p>
                            <p :class="statusTone(payment.status)">{{ paymentStatus[payment.status] ?? payment.status }}</p>
                        </div>
                    </li>
                </ul>
            </section>

            <Link
                :href="route('account.home')"
                class="inline-flex h-10 items-center rounded-md px-2 text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                Back to my reservations
            </Link>
        </div>
    </AccountLayout>
</template>
