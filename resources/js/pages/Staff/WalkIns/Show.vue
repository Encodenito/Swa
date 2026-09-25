<script setup lang="ts">
import StatusBadge from '@/components/shop/StatusBadge.vue';
import PaymentList from '@/components/shop/staff/PaymentList.vue';
import ReasonDialog from '@/components/shop/staff/ReasonDialog.vue';
import ReservationLines from '@/components/shop/staff/ReservationLines.vue';
import { Button } from '@/components/ui/button';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { PaymentRow, ReservationDetail } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Ban, Plus, Printer } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    sale: ReservationDetail;
}>();

const code = computed(() => props.sale.reference_code);
const voided = computed(() => props.sale.status === 'voided');

const breadcrumbs = computed(() => [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Reservations', href: route('staff.reservations.index', { type: 'walk_in' }) },
    { title: code.value, href: route('staff.walk-ins.show', { reservation: code.value }) },
]);

/** The cash payment that settled the sale (server-recorded, paid in full). */
const salePayment = computed<PaymentRow | undefined>(() => props.sale.payments.find((p) => p.type !== 'refund'));

const methodLabel: Record<PaymentRow['method'], string> = { cash: 'Cash', paymongo: 'PayMongo' };

const voidOpen = ref(false);

function print() {
    window.print();
}
</script>

<template>
    <Head :title="`Walk-in sale ${sale.reference_code}`" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-xl space-y-6 px-4 py-6">
            <div class="flex flex-wrap items-center justify-between gap-2 print:hidden">
                <h1 class="text-2xl font-bold tracking-tight">Walk-in sale</h1>
                <div class="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" class="h-10" @click="print">
                        <Printer class="size-4" aria-hidden="true" />
                        Print
                    </Button>
                    <Button as-child variant="outline" class="h-10">
                        <Link :href="route('staff.walk-ins.create')">
                            <Plus class="size-4" aria-hidden="true" />
                            New sale
                        </Link>
                    </Button>
                </div>
            </div>

            <div v-if="voided" role="status" class="rounded-md border border-danger-border bg-danger-surface px-4 py-3 text-sm text-danger-strong">
                <p class="font-semibold">
                    This sale was voided<template v-if="sale.cancelled_at"> on {{ formatDateTime(sale.cancelled_at) }}</template
                    >.
                </p>
                <p v-if="sale.cancel_reason" class="mt-1">Reason: {{ sale.cancel_reason }}</p>
                <p class="mt-1">The items went back into stock and the cash is to be returned to the customer.</p>
            </div>

            <article
                id="walk-in-receipt"
                aria-labelledby="receipt-heading"
                class="rounded-lg border border-border bg-card p-5 print:border-0 print:p-0"
            >
                <header class="border-b border-dashed border-border pb-4 text-center">
                    <p class="font-semibold">SWA I.T. Supplies Center</p>
                    <h2 id="receipt-heading" class="text-sm text-muted-foreground">Sales receipt</h2>
                    <p class="mt-3 break-all font-mono text-2xl font-bold tracking-wider">{{ sale.reference_code }}</p>
                    <p class="mt-1 text-sm">{{ formatDateTime(sale.completed_at ?? sale.created_at) }}</p>
                    <p v-if="voided" class="mt-2"><StatusBadge :status="sale.status" :label="sale.status_label" /></p>
                </header>

                <dl v-if="sale.walk_in_name || sale.walk_in_phone" class="space-y-1 border-b border-dashed border-border py-3 text-sm">
                    <div v-if="sale.walk_in_name" class="flex justify-between gap-2">
                        <dt class="text-muted-foreground">Customer</dt>
                        <dd class="text-right">{{ sale.walk_in_name }}</dd>
                    </div>
                    <div v-if="sale.walk_in_phone" class="flex justify-between gap-2">
                        <dt class="text-muted-foreground">Mobile</dt>
                        <dd class="text-right">{{ sale.walk_in_phone }}</dd>
                    </div>
                </dl>

                <section aria-label="Items" class="border-b border-dashed border-border py-2">
                    <ReservationLines :lines="sale.lines" />
                </section>

                <dl class="space-y-1 py-3 text-sm">
                    <div class="flex items-baseline justify-between gap-2 text-base">
                        <dt class="font-semibold">Total</dt>
                        <dd class="text-xl font-bold tabular-nums">{{ formatPeso(sale.total) }}</dd>
                    </div>
                    <div v-if="salePayment" class="flex justify-between gap-2">
                        <dt class="text-muted-foreground">Paid, {{ methodLabel[salePayment.method] }} (in full)</dt>
                        <dd class="tabular-nums">{{ formatPeso(salePayment.amount) }}</dd>
                    </div>
                    <p v-else class="text-muted-foreground">No payment recorded.</p>
                </dl>

                <p class="border-t border-dashed border-border pt-3 text-center text-xs text-muted-foreground">Thank you for shopping with us.</p>
            </article>

            <section aria-labelledby="payments-heading" class="rounded-lg border border-border bg-card p-4 print:hidden">
                <h2 id="payments-heading" class="mb-3 font-semibold">Payment record</h2>
                <PaymentList :payments="sale.payments" />
            </section>

            <section v-if="sale.can_void" aria-labelledby="void-heading" class="rounded-lg border border-border bg-card p-4 print:hidden">
                <h2 id="void-heading" class="font-semibold">Void this sale</h2>
                <p class="mb-3 mt-1 text-sm text-muted-foreground">
                    Use this when the sale was a mistake or the customer returns everything the same day.
                </p>
                <Button type="button" variant="destructive" class="h-10" @click="voidOpen = true">
                    <Ban class="size-4" aria-hidden="true" />
                    Void sale
                </Button>
            </section>
        </div>

        <ReasonDialog
            v-if="sale.can_void"
            v-model:open="voidOpen"
            id-prefix="void"
            :url="route('admin.walk-ins.void', { reservation: sale.reference_code })"
            :title="`Void sale ${sale.reference_code}?`"
            description="Return the cash to the customer; the items go back into stock. This cannot be undone."
            confirm-label="Void sale"
            placeholder="e.g. Rang up the wrong item"
            destructive
        >
            <p class="rounded-md bg-muted px-3 py-2 text-sm">
                Cash to return: <span class="font-semibold tabular-nums">{{ formatPeso(salePayment?.amount ?? sale.total) }}</span>
            </p>
        </ReasonDialog>
    </StaffLayout>
</template>

<style>
/*
 * Print only the receipt: the sidebar, header, flash messages and buttons stay on screen.
 * Scoped with :has() because this stylesheet stays loaded after navigating to other pages.
 */
@media print {
    body:has(#walk-in-receipt) * {
        visibility: hidden;
    }

    #walk-in-receipt,
    #walk-in-receipt * {
        visibility: visible;
    }

    #walk-in-receipt {
        position: absolute;
        inset: 0 0 auto 0;
        margin: 0 auto;
        max-width: 80mm;
        color: #000;
        background: #fff;
    }
}
</style>
