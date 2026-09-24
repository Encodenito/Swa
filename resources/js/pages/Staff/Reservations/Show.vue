<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/shop/StatusBadge.vue';
import ConfirmDialog from '@/components/shop/staff/ConfirmDialog.vue';
import PaymentList from '@/components/shop/staff/PaymentList.vue';
import ReservationLines from '@/components/shop/staff/ReservationLines.vue';
import TextArea from '@/components/shop/staff/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime, formatDeadline } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { ReservationDetail } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ScanLine } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    reservation: ReservationDetail;
    can: { process: boolean; extend: boolean; complete: boolean };
}>();

const breadcrumbs = computed(() => [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Reservations', href: route('staff.reservations.index') },
    { title: props.reservation.reference_code, href: route('staff.reservations.show', { reservation: props.reservation.reference_code }) },
]);

const needsReview = computed(() => props.reservation.payments.some((p) => p.status === 'needs_review'));

// Start preparing
const confirmProcess = ref(false);
const processForm = useForm({});

function startPreparing() {
    processForm.post(route('staff.reservations.process', { reservation: props.reservation.reference_code }), {
        preserveScroll: true,
        onFinish: () => (confirmProcess.value = false),
    });
}

// Extend pickup deadline (dates are Y-m-d in shop time)
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
const extendForm = useForm({ pickup_by: '', reason: '' });

function extend() {
    extendForm.post(route('staff.reservations.extend', { reservation: props.reservation.reference_code }), {
        preserveScroll: true,
        onSuccess: () => extendForm.reset(),
    });
}

const timeline = computed(() =>
    [
        { label: 'Placed', at: props.reservation.created_at },
        { label: 'Confirmed', at: props.reservation.confirmed_at },
        { label: 'Preparing since', at: props.reservation.processing_at },
        { label: 'Picked up', at: props.reservation.completed_at },
        { label: 'Cancelled', at: props.reservation.cancelled_at },
    ].filter((row) => row.at),
);
</script>

<template>
    <Head :title="`Reservation ${reservation.reference_code}`" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm text-muted-foreground">Reservation</p>
                    <h2 class="flex flex-wrap items-center gap-3 font-mono text-2xl font-semibold tracking-tight">
                        {{ reservation.reference_code }}
                        <StatusBadge :status="reservation.status" :label="reservation.status_label" class="font-sans" />
                    </h2>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <Button v-if="can.complete" as-child>
                        <Link :href="route('staff.pickup', { q: reservation.reference_code })">
                            <ScanLine aria-hidden="true" />
                            Go to pickup counter
                        </Link>
                    </Button>
                    <Button v-if="can.process" type="button" variant="outline" @click="confirmProcess = true">Start preparing</Button>
                </div>
            </header>

            <p
                v-if="needsReview"
                role="alert"
                class="mb-6 rounded-md border border-red-400 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100"
            >
                A payment on this reservation needs review. Check the payment note below before handing anything over.
            </p>

            <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
                <div class="space-y-6">
                    <section aria-labelledby="items-heading" class="rounded-lg border border-border bg-card p-4">
                        <h3 id="items-heading" class="mb-2 font-semibold">Items</h3>
                        <ReservationLines :lines="reservation.lines" />
                        <dl class="mt-3 space-y-1 border-t border-border pt-3 text-sm">
                            <div class="flex justify-between gap-2">
                                <dt class="text-muted-foreground">Total</dt>
                                <dd class="font-semibold tabular-nums">{{ formatPeso(reservation.total) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-muted-foreground">Downpayment</dt>
                                <dd class="tabular-nums">{{ formatPeso(reservation.downpayment_amount) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-muted-foreground">Balance due at pickup</dt>
                                <dd class="font-semibold tabular-nums">{{ formatPeso(reservation.balance) }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section aria-labelledby="payments-heading" class="rounded-lg border border-border bg-card p-4">
                        <h3 id="payments-heading" class="mb-3 font-semibold">Payments</h3>
                        <PaymentList :payments="reservation.payments" />
                    </section>
                </div>

                <div class="space-y-6">
                    <section aria-labelledby="customer-heading" class="rounded-lg border border-border bg-card p-4 text-sm">
                        <h3 id="customer-heading" class="mb-2 font-semibold">Customer</h3>
                        <template v-if="reservation.customer">
                            <p class="font-medium">{{ reservation.customer.name }}</p>
                            <p>
                                <a :href="`mailto:${reservation.customer.email}`" class="break-all underline-offset-4 hover:underline">{{
                                    reservation.customer.email
                                }}</a>
                            </p>
                            <p v-if="reservation.customer.phone">
                                <a :href="`tel:${reservation.customer.phone}`" class="underline-offset-4 hover:underline">{{
                                    reservation.customer.phone
                                }}</a>
                            </p>
                        </template>
                        <p v-else class="text-muted-foreground">Customer account not available.</p>
                    </section>

                    <section aria-labelledby="dates-heading" class="rounded-lg border border-border bg-card p-4 text-sm">
                        <h3 id="dates-heading" class="mb-2 font-semibold">Dates</h3>
                        <dl class="space-y-1.5">
                            <div v-if="reservation.pickup_by" class="flex justify-between gap-2">
                                <dt class="text-muted-foreground">Pick up by</dt>
                                <dd class="text-right font-semibold">{{ formatDeadline(reservation.pickup_by) }}</dd>
                            </div>
                            <div v-if="reservation.status === 'pending_payment' && reservation.expires_at" class="flex justify-between gap-2">
                                <dt class="text-muted-foreground">Pay by</dt>
                                <dd class="text-right">{{ formatDateTime(reservation.expires_at) }}</dd>
                            </div>
                            <div v-for="row in timeline" :key="row.label" class="flex justify-between gap-2">
                                <dt class="text-muted-foreground">{{ row.label }}</dt>
                                <dd class="text-right">{{ formatDateTime(row.at) }}</dd>
                            </div>
                        </dl>
                        <p v-if="reservation.cancel_reason" class="mt-3 border-t border-border pt-3">
                            <span class="text-muted-foreground">Reason:</span> {{ reservation.cancel_reason }}
                        </p>
                    </section>

                    <section v-if="can.extend" aria-labelledby="extend-heading" class="rounded-lg border border-border bg-card p-4">
                        <h3 id="extend-heading" class="font-semibold">Extend pickup deadline</h3>
                        <p class="mb-3 mt-1 text-sm text-muted-foreground">Pick the new last day for pickup and note why, for the record.</p>
                        <form class="space-y-3" @submit.prevent="extend">
                            <div class="grid gap-1.5">
                                <Label for="pickup_by">New pickup date</Label>
                                <Input
                                    id="pickup_by"
                                    v-model="extendForm.pickup_by"
                                    type="date"
                                    :min="today"
                                    required
                                    :aria-invalid="!!extendForm.errors.pickup_by"
                                    aria-describedby="pickup_by-error"
                                />
                                <InputError id="pickup_by-error" :message="extendForm.errors.pickup_by" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="extend-reason">Reason</Label>
                                <TextArea
                                    id="extend-reason"
                                    v-model="extendForm.reason"
                                    required
                                    minlength="3"
                                    maxlength="255"
                                    placeholder="e.g. Customer called, out of town until Friday"
                                    :aria-invalid="!!extendForm.errors.reason"
                                    aria-describedby="extend-reason-error"
                                />
                                <InputError id="extend-reason-error" :message="extendForm.errors.reason" />
                            </div>
                            <Button type="submit" class="w-full" :disabled="extendForm.processing">
                                {{ extendForm.processing ? 'Saving…' : 'Extend deadline' }}
                            </Button>
                        </form>
                    </section>
                </div>
            </div>
        </div>

        <ConfirmDialog
            v-model:open="confirmProcess"
            title="Start preparing this order?"
            description="Once you start preparing, the customer will no longer be able to cancel this reservation."
            confirm-label="Start preparing"
            :processing="processForm.processing"
            @confirm="startPreparing"
        />
    </StaffLayout>
</template>
