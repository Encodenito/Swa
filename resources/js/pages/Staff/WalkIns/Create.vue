<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ConfirmDialog from '@/components/shop/staff/ConfirmDialog.vue';
import ItemPicker from '@/components/shop/staff/ItemPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { ItemCard } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Info } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineProps<{
    items: (ItemCard & { sku: string | null })[];
}>();

const breadcrumbs = [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Walk-in sale', href: route('staff.walk-ins.create') },
];

const form = useForm({
    lines: [] as { item_id: number; quantity: number }[],
    walk_in_name: '',
    walk_in_phone: '',
});

const errors = computed(() => form.errors as Record<string, string | undefined>);
const confirmOpen = ref(false);
const unitCount = computed(() => form.lines.reduce((n, line) => n + line.quantity, 0));

function askConfirm() {
    if (form.lines.length === 0) {
        form.setError('lines', 'Add at least one item.');

        return;
    }
    form.clearErrors('lines');
    confirmOpen.value = true;
}

function submit() {
    form.transform((data) => ({
        lines: data.lines,
        walk_in_name: data.walk_in_name.trim() || null,
        walk_in_phone: data.walk_in_phone.trim() || null,
    })).post(route('staff.walk-ins.store'), {
        preserveScroll: true,
        onFinish: () => (confirmOpen.value = false),
    });
}
</script>

<template>
    <Head title="Walk-in sale" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl px-4 py-6">
            <Heading title="Walk-in sale" description="For customers buying at the counter without a reservation. Paid in full in cash." />

            <form class="space-y-6" novalidate @submit.prevent="askConfirm">
                <section aria-labelledby="items-heading" class="rounded-lg border border-border bg-card p-4">
                    <h2 id="items-heading" class="mb-3 text-lg font-semibold">Items</h2>
                    <ItemPicker v-model:lines="form.lines" id-prefix="walk-in" :items="items" :errors="errors" :disabled="form.processing" />
                    <p class="mt-4 flex items-start gap-2 rounded-md bg-muted px-3 py-2 text-sm">
                        <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        The total is calculated when you record the sale.
                    </p>
                </section>

                <section aria-labelledby="customer-heading" class="rounded-lg border border-border bg-card p-4">
                    <h2 id="customer-heading" class="text-lg font-semibold">Customer (optional)</h2>
                    <p class="mb-3 mt-1 text-sm text-muted-foreground">Only if the customer wants it on the receipt or for warranty.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="walk_in_name">Name</Label>
                            <Input
                                id="walk_in_name"
                                v-model="form.walk_in_name"
                                autocomplete="off"
                                maxlength="150"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.walk_in_name || undefined"
                                aria-describedby="walk_in_name-error"
                            />
                            <InputError id="walk_in_name-error" :message="form.errors.walk_in_name" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="walk_in_phone">Mobile number</Label>
                            <Input
                                id="walk_in_phone"
                                v-model="form.walk_in_phone"
                                type="tel"
                                inputmode="tel"
                                autocomplete="off"
                                maxlength="30"
                                placeholder="e.g. 0917 123 4567"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.walk_in_phone || undefined"
                                aria-describedby="walk_in_phone-error"
                            />
                            <InputError id="walk_in_phone-error" :message="form.errors.walk_in_phone" />
                        </div>
                    </div>
                </section>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button type="submit" class="h-11 sm:px-6" :disabled="form.processing">
                        {{ form.processing ? 'Recording…' : 'Record sale' }}
                    </Button>
                </div>
            </form>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            title="Record this sale?"
            :description="`${form.lines.length} ${form.lines.length === 1 ? 'item' : 'items'}, ${unitCount} ${unitCount === 1 ? 'unit' : 'units'} in total. The stock is taken off the shelf right away and the next page shows the total to collect in cash.`"
            confirm-label="Record sale"
            :processing="form.processing"
            @confirm="submit"
        />
    </StaffLayout>
</template>
