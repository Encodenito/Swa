<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PriceTag from '@/components/shop/PriceTag.vue';
import StatusBadge from '@/components/shop/StatusBadge.vue';
import PaymentList from '@/components/shop/staff/PaymentList.vue';
import ReservationLines from '@/components/shop/staff/ReservationLines.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDeadline } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { ReservationDetail } from '@/types';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ reservation: ReservationDetail }>();

const code = computed(() => props.reservation.reference_code);
const needsReview = computed(() => props.reservation.payments.some((p) => p.status === 'needs_review'));

// Amount is typed by staff, never prefilled: the server checks it against the balance.
const form = useForm({ amount: '', id_checked: false });

function complete() {
    form.post(route('staff.pickup.complete', { reservation: code.value }), { preserveScroll: true });
}
</script>

<template>
    <article :aria-labelledby="`pickup-${code}`" class="overflow-hidden rounded-lg border border-border bg-card">
        <header class="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h3 :id="`pickup-${code}`" class="text-lg font-semibold">{{ reservation.customer?.name ?? 'Unknown customer' }}</h3>
                <p v-if="reservation.customer" class="text-sm text-muted-foreground">
                    <span class="break-all">{{ reservation.customer.email }}</span>
                    <template v-if="reservation.customer.phone">
                        <br />
                        {{ reservation.customer.phone }}
                    </template>
                </p>
            </div>
            <div class="flex flex-col gap-1 sm:items-end">
                <Link
                    :href="route('staff.reservations.show', { reservation: code })"
                    class="font-mono text-lg font-semibold underline-offset-4 hover:underline"
                    :aria-label="`Open reservation ${code}`"
                    >{{ code }}</Link
                >
                <StatusBadge :status="reservation.status" :label="reservation.status_label" />
                <p class="text-sm">
                    <span class="text-muted-foreground">Pick up by</span> <span class="font-medium">{{ formatDeadline(reservation.pickup_by) }}</span>
                </p>
            </div>
        </header>

        <div class="grid gap-6 p-4 lg:grid-cols-[1fr_20rem]">
            <div class="space-y-4">
                <section :aria-label="`Items in ${code}`">
                    <ReservationLines :lines="reservation.lines" />
                </section>
                <dl class="grid grid-cols-2 gap-2 rounded-md bg-muted/50 p-3 text-sm">
                    <dt class="text-muted-foreground">Total</dt>
                    <dd class="text-right tabular-nums">{{ formatPeso(reservation.total) }}</dd>
                    <dt class="text-muted-foreground">Downpayment paid</dt>
                    <dd class="text-right tabular-nums">{{ formatPeso(reservation.downpayment_amount) }}</dd>
                </dl>
                <details v-if="reservation.payments.length" class="text-sm" :open="needsReview">
                    <summary class="cursor-pointer font-medium">Payments ({{ reservation.payments.length }})</summary>
                    <div class="mt-2"><PaymentList :payments="reservation.payments" /></div>
                </details>
            </div>

            <form class="space-y-4 rounded-md border border-border p-4" @submit.prevent="complete">
                <div>
                    <p class="text-sm text-muted-foreground">Balance due</p>
                    <PriceTag :price="reservation.balance" size="lg" />
                </div>

                <p
                    v-if="needsReview"
                    role="alert"
                    class="rounded-md border border-danger-border bg-danger-surface px-3 py-2 text-sm text-danger-strong"
                >
                    A payment needs review. Check it before handing over.
                </p>

                <div class="grid gap-1.5">
                    <Label :for="`amount-${code}`">Amount received (₱)</Label>
                    <Input
                        :id="`amount-${code}`"
                        v-model="form.amount"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        :placeholder="reservation.balance"
                        class="h-12 text-lg tabular-nums"
                        required
                        :aria-invalid="!!form.errors.amount"
                        :aria-describedby="`amount-${code}-error`"
                    />
                    <InputError :id="`amount-${code}-error`" :message="form.errors.amount" />
                </div>

                <div class="grid gap-1.5">
                    <div class="flex items-start gap-3">
                        <Checkbox
                            :id="`id-checked-${code}`"
                            v-model:checked="form.id_checked"
                            class="mt-0.5"
                            :aria-invalid="!!form.errors.id_checked"
                            :aria-describedby="`id-checked-${code}-error`"
                        />
                        <Label :for="`id-checked-${code}`" class="leading-snug">
                            I checked this is the account holder (and their ID for high-value items)
                        </Label>
                    </div>
                    <InputError :id="`id-checked-${code}-error`" :message="form.errors.id_checked" />
                </div>

                <Button type="submit" size="lg" class="w-full" :disabled="form.processing">
                    {{ form.processing ? 'Completing…' : 'Complete pickup' }}
                </Button>
            </form>
        </div>
    </article>
</template>
