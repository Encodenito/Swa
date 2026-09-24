<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    settings: Record<string, number>;
    ranges: Record<string, [number, number]>;
}>();

const breadcrumbs = [
    { title: 'Admin dashboard', href: route('admin.home') },
    { title: 'Settings', href: route('admin.settings.edit') },
];

const meta: Record<string, { label: string; help: string; group: 'payment' | 'limits' }> = {
    downpayment_percent: {
        label: 'Downpayment (%)',
        help: 'Share of the total the customer pays online to confirm a reservation.',
        group: 'payment',
    },
    pending_minutes: {
        label: 'Time to pay (minutes)',
        help: 'How long an unpaid reservation holds the stock before it expires.',
        group: 'payment',
    },
    pickup_days: {
        label: 'Pickup window (days after confirmation)',
        help: 'Days the customer has to pick up once the downpayment is confirmed.',
        group: 'payment',
    },
    forfeit_refund_percent: {
        label: 'Refund on missed pickup (%)',
        help: 'Share of the downpayment refunded when the customer does not pick up in time.',
        group: 'payment',
    },
    max_unpaid_reservations: {
        label: 'Unpaid reservations per customer',
        help: 'How many reservations a customer can have waiting for payment at once.',
        group: 'limits',
    },
    max_qty_per_item: {
        label: 'Max quantity per item',
        help: 'The most units of one item a customer can reserve at a time.',
        group: 'limits',
    },
};

const form = useForm<Record<string, number | string>>({ ...props.settings });

const keys = computed(() => Object.keys(props.settings));
const groups = computed(() => [
    { title: 'Payment and pickup', keys: keys.value.filter((k) => (meta[k]?.group ?? 'limits') === 'payment') },
    { title: 'Limits per customer', keys: keys.value.filter((k) => (meta[k]?.group ?? 'limits') === 'limits') },
]);

const labelFor = (key: string) => meta[key]?.label ?? key.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
const range = (key: string) => props.ranges[key] ?? [undefined, undefined];

function submit() {
    form.put(route('admin.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Settings" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="max-w-2xl px-4 py-6">
            <Heading title="Settings" description="Shop rules for reservations, payments and pickup." />

            <form class="space-y-8" @submit.prevent="submit">
                <fieldset v-for="group in groups" v-show="group.keys.length" :key="group.title" class="space-y-5">
                    <legend class="mb-4 font-semibold">{{ group.title }}</legend>
                    <div v-for="key in group.keys" :key="key" class="grid gap-1.5 sm:grid-cols-[1fr_8rem] sm:items-start sm:gap-x-6">
                        <div>
                            <Label :for="key">{{ labelFor(key) }}</Label>
                            <p :id="`${key}-help`" class="mt-1 text-sm text-muted-foreground">
                                {{ meta[key]?.help }}
                                <span v-if="ranges[key]" class="whitespace-nowrap">Between {{ range(key)[0] }} and {{ range(key)[1] }}.</span>
                            </p>
                        </div>
                        <div>
                            <Input
                                :id="key"
                                v-model.number="form[key]"
                                type="number"
                                inputmode="numeric"
                                step="1"
                                :min="range(key)[0]"
                                :max="range(key)[1]"
                                required
                                class="text-right tabular-nums"
                                :aria-invalid="!!form.errors[key]"
                                :aria-describedby="`${key}-help ${key}-error`"
                            />
                            <InputError :id="`${key}-error`" :message="form.errors[key]" class="mt-1" />
                        </div>
                    </div>
                </fieldset>

                <div class="space-y-3 border-t border-border pt-6">
                    <p class="text-sm text-muted-foreground">
                        Changes apply to new reservations only. Reservations already placed keep the rules they were made under.
                    </p>
                    <Button type="submit" :disabled="form.processing || !form.isDirty">{{ form.processing ? 'Saving…' : 'Save settings' }}</Button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
