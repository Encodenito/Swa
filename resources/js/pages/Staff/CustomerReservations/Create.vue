<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ItemPicker from '@/components/shop/staff/ItemPicker.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { ItemCard } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Info, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Customer = { id: number; name: string; email: string; phone: string | null };

const props = defineProps<{
    q: string;
    customers: Customer[];
    items: (ItemCard & { sku: string | null })[];
    terms: { pickupDays: number; forfeitRefundPercent: number; downpaymentPercent: number; pendingMinutes: number };
    maxQuantity: number;
}>();

const breadcrumbs = [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Reserve for a customer', href: route('staff.customer-reservations.create') },
];

// Step 1: find the customer (server search, GET).
const q = ref(props.q ?? '');
const searching = ref(false);

function searchCustomers() {
    router.get(route('staff.customer-reservations.create'), q.value.trim() === '' ? {} : { q: q.value.trim() }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['q', 'customers'],
        onStart: () => (searching.value = true),
        onFinish: () => (searching.value = false),
    });
}

const form = useForm({
    customer_id: null as number | null,
    lines: [] as { item_id: number; quantity: number }[],
    terms_confirmed: false,
});

// Kept separately so the choice survives a new search that no longer lists this customer.
const selected = ref<Customer | null>(null);

function choose(customer: Customer) {
    selected.value = customer;
    form.customer_id = customer.id;
    form.clearErrors('customer_id');
}

const errors = computed(() => form.errors as Record<string, string | undefined>);

function submit() {
    form.post(route('staff.customer-reservations.store'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Reserve for a customer" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6">
            <Heading
                title="Reserve for a customer"
                description="For customers with an account who ask at the counter or by phone. They still pay the downpayment online with the link you get next."
            />

            <!-- Step 1 -->
            <section aria-labelledby="step-1" class="rounded-lg border border-border bg-card p-4">
                <h2 id="step-1" class="text-lg font-semibold"><span class="text-muted-foreground">1.</span> Customer</h2>

                <form role="search" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end" @submit.prevent="searchCustomers">
                    <div class="grid flex-1 gap-1.5">
                        <Label for="customer-search">Name, email or mobile number</Label>
                        <Input
                            id="customer-search"
                            v-model="q"
                            type="search"
                            autocomplete="off"
                            placeholder="e.g. juan@example.com"
                            maxlength="100"
                        />
                    </div>
                    <Button type="submit" variant="outline" class="h-10" :disabled="searching">
                        <Search class="size-4" aria-hidden="true" />
                        {{ searching ? 'Searching…' : 'Find customer' }}
                    </Button>
                </form>

                <div :class="['mt-4 transition-opacity', searching ? 'opacity-60' : '']" :aria-busy="searching">
                    <p v-if="!props.q" class="text-sm text-muted-foreground">Search to list matching customer accounts.</p>
                    <p v-else-if="customers.length === 0" class="rounded-md border border-dashed border-border p-4 text-center text-sm">
                        No customer found — ask them to create an account first.
                    </p>
                    <fieldset v-else aria-describedby="customer_id-error">
                        <legend class="mb-2 text-sm font-medium">Choose the customer ({{ customers.length }} found)</legend>
                        <div class="space-y-2">
                            <label
                                v-for="c in customers"
                                :key="c.id"
                                class="flex min-h-11 cursor-pointer items-start gap-3 rounded-md border border-border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-accent has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                            >
                                <input
                                    type="radio"
                                    name="customer_id"
                                    :value="c.id"
                                    :checked="form.customer_id === c.id"
                                    class="mt-0.5 size-4 accent-primary"
                                    :disabled="form.processing"
                                    @change="choose(c)"
                                />
                                <span class="min-w-0">
                                    <span class="block font-medium">{{ c.name }}</span>
                                    <span class="block break-all text-muted-foreground">{{ c.email }}</span>
                                    <span v-if="c.phone" class="block text-muted-foreground">{{ c.phone }}</span>
                                </span>
                            </label>
                        </div>
                    </fieldset>
                </div>

                <p v-if="selected" class="mt-4 rounded-md bg-muted px-3 py-2 text-sm" aria-live="polite">
                    Reserving for <span class="font-semibold">{{ selected.name }}</span> ({{ selected.email }})
                </p>
                <InputError id="customer_id-error" class="mt-2" :message="form.errors.customer_id" />
            </section>

            <form class="space-y-6" @submit.prevent="submit">
                <!-- Step 2 -->
                <section aria-labelledby="step-2" class="rounded-lg border border-border bg-card p-4">
                    <h2 id="step-2" class="mb-1 text-lg font-semibold"><span class="text-muted-foreground">2.</span> Items</h2>
                    <p class="mb-3 text-sm text-muted-foreground">Up to {{ maxQuantity }} of each item.</p>
                    <ItemPicker
                        v-model:lines="form.lines"
                        id-prefix="reserve"
                        :items="items"
                        :max-quantity="maxQuantity"
                        :errors="errors"
                        :disabled="form.processing"
                    />
                    <p class="mt-4 flex items-start gap-2 rounded-md bg-muted px-3 py-2 text-sm">
                        <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        The total and the {{ terms.downpaymentPercent }}% downpayment are calculated when you create the reservation.
                    </p>
                </section>

                <!-- Step 3 -->
                <section aria-labelledby="step-3" class="rounded-lg border border-border bg-card p-4">
                    <h2 id="step-3" class="text-lg font-semibold"><span class="text-muted-foreground">3.</span> Terms</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Read these to the customer before you continue.</p>
                    <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                        <li>
                            The customer pays a {{ terms.downpaymentPercent }}% downpayment online within {{ terms.pendingMinutes }} minutes, or the
                            reservation expires.
                        </li>
                        <li>
                            You can cancel for free (full refund) until we start preparing your order. Once it is marked Processing, it cannot be
                            cancelled. Pick up within {{ terms.pickupDays }} days of confirmation, or only {{ terms.forfeitRefundPercent }}% of your
                            downpayment is refunded.
                        </li>
                    </ul>

                    <div class="mt-4 flex items-start gap-3">
                        <Checkbox
                            id="terms_confirmed"
                            v-model:checked="form.terms_confirmed"
                            class="mt-0.5"
                            :disabled="form.processing"
                            :aria-invalid="!!form.errors.terms_confirmed || undefined"
                            aria-describedby="terms_confirmed-error"
                        />
                        <Label for="terms_confirmed" class="leading-relaxed">The customer agreed to these terms</Label>
                    </div>
                    <InputError id="terms_confirmed-error" class="mt-2" :message="form.errors.terms_confirmed" />
                </section>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                    <p v-if="!form.customer_id" class="text-sm text-muted-foreground sm:mr-auto">Choose a customer first.</p>
                    <Button type="submit" class="h-11 sm:px-6" :disabled="form.processing">
                        {{ form.processing ? 'Creating…' : 'Create reservation' }}
                    </Button>
                </div>
            </form>
        </div>
    </StaffLayout>
</template>
