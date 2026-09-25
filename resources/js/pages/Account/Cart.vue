<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PriceTag from '@/components/shop/PriceTag.vue';
import EmptyState from '@/components/shop/customer/EmptyState.vue';
import ItemImage from '@/components/shop/customer/ItemImage.vue';
import QuantityStepper from '@/components/shop/customer/QuantityStepper.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AccountLayout from '@/layouts/AccountLayout.vue';
import { formatPeso } from '@/lib/money';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, LoaderCircle, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface CartPrice {
    quantity: number;
    unit_price: string;
    clearance: boolean;
}

interface CartLine {
    item_id: number;
    name: string;
    slug: string;
    image_url: string | null;
    quantity: number;
    available: number;
    prices: CartPrice[];
    line_total: string;
}

const props = defineProps<{
    cart: {
        lines: CartLine[];
        total: string;
        downpayment: string;
        balance: string;
        problems: string[];
    };
    terms: {
        pickupDays: number;
        forfeitRefundPercent: number;
        downpaymentPercent: number;
        pendingMinutes: number;
    };
    maxQuantity: number;
    canReserve: boolean;
}>();

const breadcrumbs = [
    { title: 'My reservations', href: route('account.home') },
    { title: 'Cart', href: route('account.cart') },
];

const isEmpty = computed(() => props.cart.lines.length === 0);

// Quantity changes: one request at a time, errors shown on the line that was changed.
const lineForm = useForm({ item_id: 0, quantity: 0 });
const updatingId = ref<number | null>(null);

function setQuantity(itemId: number, quantity: number) {
    lineForm.item_id = itemId;
    lineForm.quantity = quantity;
    updatingId.value = itemId;
    lineForm.patch(route('account.cart.update'), {
        preserveScroll: true,
        onFinish: () => {
            updatingId.value = null;
        },
    });
}

function lineErrors(itemId: number): string | undefined {
    if (lineForm.item_id !== itemId) return undefined;

    return lineForm.errors.quantity ?? lineForm.errors.item_id;
}

/** Display only: "1 × ₱27,999.00 clearance, 1 × ₱30,000.00". Amounts are the server's. */
function breakdown(prices: CartPrice[]): string {
    return prices.map((p) => `${p.quantity} × ${formatPeso(p.unit_price)}${p.clearance ? ' clearance' : ''}`).join(', ');
}

const reserveForm = useForm({ terms: false });
// The server also reports cart problems (e.g. stock ran out) under a 'cart' key.
const cartError = computed(() => (reserveForm.errors as Record<string, string | undefined>).cart);

const canSubmit = computed(() => props.canReserve && !isEmpty.value && !reserveForm.processing && !lineForm.processing);

function reserve() {
    if (!canSubmit.value) return;
    // The server answers with an external redirect to PayMongo; Inertia follows it.
    reserveForm.post(route('account.reservations.store'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Cart" />

    <AccountLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl px-4 py-6">
            <h1 class="text-2xl font-bold tracking-tight">Cart</h1>
            <p class="mt-1 text-sm text-muted-foreground">Review your items, then pay the downpayment to reserve them.</p>

            <div
                v-if="cart.problems.length > 0"
                class="mt-5 rounded-md border border-warning-border bg-warning-surface p-4 text-warning-strong"
                aria-live="polite"
            >
                <div class="flex gap-3">
                    <AlertTriangle class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                    <div>
                        <p class="font-semibold">Some items changed</p>
                        <ul class="mt-1 list-disc space-y-1 pl-4 text-sm">
                            <li v-for="(problem, i) in cart.problems" :key="i">{{ problem }}</li>
                        </ul>
                    </div>
                </div>
            </div>

            <EmptyState v-if="isEmpty" class="mt-6" title="Your cart is empty" description="Browse the items and add what you want to reserve.">
                <Link
                    :href="route('items.index')"
                    class="inline-flex h-11 items-center rounded-md bg-primary px-5 font-semibold text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                >
                    Browse items
                </Link>
            </EmptyState>

            <div v-else class="mt-6 grid gap-6 lg:grid-cols-[1fr_22rem] lg:items-start">
                <section aria-labelledby="lines-heading">
                    <h2 id="lines-heading" class="sr-only">Items in your cart</h2>
                    <ul class="divide-y divide-border rounded-lg border border-border bg-card">
                        <li v-for="line in cart.lines" :key="line.item_id" class="flex gap-3 p-4 sm:gap-4" :aria-busy="updatingId === line.item_id">
                            <div class="size-20 shrink-0 overflow-hidden rounded-md border border-border bg-white p-1 dark:bg-muted">
                                <ItemImage :src="line.image_url" :alt="line.name" />
                            </div>
                            <div class="min-w-0 flex-1 space-y-2">
                                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                                    <Link
                                        :href="route('items.show', { item: line.slug })"
                                        class="font-semibold leading-snug hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {{ line.name }}
                                    </Link>
                                    <p class="font-semibold tabular-nums">
                                        <span class="sr-only">Line total </span>{{ formatPeso(line.line_total) }}
                                    </p>
                                </div>

                                <div class="text-sm text-muted-foreground">
                                    <p v-if="line.prices.length > 1" class="tabular-nums">{{ breakdown(line.prices) }}</p>
                                    <p v-else-if="line.prices.length === 1" class="flex flex-wrap items-center gap-2">
                                        <PriceTag size="sm" :price="line.prices[0].unit_price" :clearance="line.prices[0].clearance" />
                                        <span>each{{ line.prices[0].clearance ? ', clearance' : '' }}</span>
                                    </p>
                                    <p v-else class="font-medium text-foreground">Sold out</p>
                                </div>

                                <div class="flex flex-wrap items-center gap-3">
                                    <label :for="`qty-${line.item_id}`" class="sr-only">Quantity of {{ line.name }}</label>
                                    <QuantityStepper
                                        :id="`qty-${line.item_id}`"
                                        :model-value="line.quantity"
                                        :label="`Quantity of ${line.name}`"
                                        :max="maxQuantity"
                                        :disabled="lineForm.processing"
                                        :invalid="Boolean(lineErrors(line.item_id))"
                                        @update:model-value="(q: number) => setQuantity(line.item_id, q)"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        class="h-11 text-muted-foreground hover:text-destructive"
                                        :disabled="lineForm.processing"
                                        @click="setQuantity(line.item_id, 0)"
                                    >
                                        <Trash2 class="size-4" aria-hidden="true" />
                                        Remove<span class="sr-only"> {{ line.name }}</span>
                                    </Button>
                                    <LoaderCircle
                                        v-if="updatingId === line.item_id"
                                        class="size-4 animate-spin text-muted-foreground"
                                        aria-label="Updating"
                                    />
                                </div>
                                <InputError :message="lineErrors(line.item_id)" />
                            </div>
                        </li>
                    </ul>
                    <Link
                        :href="route('items.index')"
                        class="mt-3 inline-flex h-10 items-center rounded-md px-2 text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        Keep browsing
                    </Link>
                </section>

                <section aria-labelledby="summary-heading" class="rounded-lg border border-border bg-card p-4 lg:sticky lg:top-20">
                    <h2 id="summary-heading" class="text-lg font-semibold">Summary</h2>

                    <dl class="mt-3 space-y-2 text-sm" :class="{ 'opacity-60': lineForm.processing }" aria-live="polite">
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-muted-foreground">Total</dt>
                            <dd class="font-semibold tabular-nums">{{ formatPeso(cart.total) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-y border-border py-3">
                            <dt class="font-semibold">Downpayment now ({{ terms.downpaymentPercent }}%)</dt>
                            <dd><PriceTag :price="cart.downpayment" /></dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-muted-foreground">Balance at pickup</dt>
                            <dd class="font-semibold tabular-nums">{{ formatPeso(cart.balance) }}</dd>
                        </div>
                    </dl>

                    <form class="mt-5 space-y-4" @submit.prevent="reserve">
                        <div>
                            <div class="flex items-start gap-3">
                                <Checkbox
                                    id="terms"
                                    v-model:checked="reserveForm.terms"
                                    class="mt-0.5"
                                    :disabled="reserveForm.processing"
                                    :aria-invalid="Boolean(reserveForm.errors.terms) || undefined"
                                    aria-describedby="terms-error"
                                />
                                <label for="terms" class="text-sm leading-relaxed">
                                    You can cancel for free (full refund) until we start preparing your order. Once it is marked Processing, it cannot
                                    be cancelled. Pick up within {{ terms.pickupDays }} days of confirmation, or only
                                    {{ terms.forfeitRefundPercent }}% of your downpayment is refunded.
                                </label>
                            </div>
                            <InputError id="terms-error" class="mt-2" :message="reserveForm.errors.terms" />
                        </div>

                        <p class="text-sm text-muted-foreground">
                            After you continue, you have {{ terms.pendingMinutes }} minutes to pay. If the payment doesn't go through in time, the
                            items are released.
                        </p>

                        <p
                            v-if="cartError"
                            role="alert"
                            class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                        >
                            {{ cartError }}
                        </p>

                        <p v-if="!canReserve" class="text-sm text-muted-foreground">
                            Your account can't place reservations right now. Check that your email address is verified, or ask us at the shop counter.
                        </p>

                        <Button type="submit" class="h-12 w-full text-base" :disabled="!canSubmit">
                            <LoaderCircle v-if="reserveForm.processing" class="size-4 animate-spin" aria-hidden="true" />
                            Pay downpayment with GCash or card
                        </Button>
                        <p class="text-xs text-muted-foreground">You'll pay on PayMongo's secure page, then come back here.</p>
                    </form>
                </section>
            </div>
        </div>
    </AccountLayout>
</template>
