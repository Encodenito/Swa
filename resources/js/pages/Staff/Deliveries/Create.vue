<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import NativeSelect from '@/components/shop/staff/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatPeso } from '@/lib/money';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { nextTick } from 'vue';

const props = defineProps<{
    suppliers: { id: number; name: string }[];
    items: { id: number; name: string; sku: string | null; price: string }[];
    canSetClearance: boolean;
}>();

const breadcrumbs = [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Stock Receiving', href: route('staff.deliveries.index') },
    { title: 'Record a delivery', href: route('staff.deliveries.create') },
];

type Line = {
    key: number;
    item_id: number | '';
    quantity_ordered: string;
    unit_cost: string;
    sale_price: string;
};

type DeliveryForm = {
    supplier_id: number | '';
    reference: string;
    expected_at: string;
    lines: Line[];
};

let nextKey = 1;
const blankLine = (): Line => ({ key: nextKey++, item_id: '', quantity_ordered: '1', unit_cost: '', sale_price: '' });

const form = useForm<DeliveryForm>({
    supplier_id: '',
    reference: '',
    expected_at: '',
    lines: [blankLine()],
});

// Errors come back with keys like "lines.0.item_id".
function lineError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`lines.${index}.${field}`];
}

async function addLine() {
    form.lines.push(blankLine());
    await nextTick();
    document.getElementById(`line-${form.lines[form.lines.length - 1].key}-item`)?.focus();
}

function removeLine(index: number) {
    form.lines.splice(index, 1);
}

function itemLabel(item: { name: string; sku: string | null; price: string }): string {
    return `${item.name}${item.sku ? ` (${item.sku})` : ''} – ${formatPeso(item.price)}`;
}

function submit() {
    form.transform((data) => ({
        supplier_id: data.supplier_id,
        reference: data.reference || null,
        expected_at: data.expected_at || null,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            quantity_ordered: line.quantity_ordered,
            unit_cost: line.unit_cost.trim() || null,
            ...(props.canSetClearance ? { sale_price: line.sale_price.trim() || null } : {}),
        })),
    })).post(route('staff.deliveries.store'));
}
</script>

<template>
    <Head title="Record a delivery" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="max-w-4xl px-4 py-6">
            <Heading
                title="Record a delivery"
                description="List what you ordered. You confirm the quantities that actually arrived when you receive it."
            />

            <EmptyState
                v-if="suppliers.length === 0"
                title="Add a supplier first"
                description="Deliveries are recorded against a supplier. Ask an admin to add one."
            />

            <form v-else class="space-y-8" @submit.prevent="submit">
                <fieldset class="grid gap-4 sm:grid-cols-3">
                    <legend class="sr-only">Delivery details</legend>
                    <div class="grid gap-1.5">
                        <Label for="supplier_id">Supplier</Label>
                        <NativeSelect
                            id="supplier_id"
                            v-model="form.supplier_id"
                            required
                            :aria-invalid="!!form.errors.supplier_id"
                            aria-describedby="supplier_id-error"
                        >
                            <option value="" disabled>Choose a supplier</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </NativeSelect>
                        <InputError id="supplier_id-error" :message="form.errors.supplier_id" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="reference">Supplier reference (optional)</Label>
                        <Input
                            id="reference"
                            v-model="form.reference"
                            maxlength="100"
                            placeholder="PO or invoice number"
                            :aria-invalid="!!form.errors.reference"
                            aria-describedby="reference-error"
                        />
                        <InputError id="reference-error" :message="form.errors.reference" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="expected_at">Expected on (optional)</Label>
                        <Input
                            id="expected_at"
                            v-model="form.expected_at"
                            type="date"
                            :aria-invalid="!!form.errors.expected_at"
                            aria-describedby="expected_at-error"
                        />
                        <InputError id="expected_at-error" :message="form.errors.expected_at" />
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="mb-1 font-semibold">Items ordered</legend>
                    <p class="mb-3 text-sm text-muted-foreground">
                        Unit cost is what you pay the supplier.
                        <template v-if="canSetClearance"> A clearance price sells this batch below the regular price.</template>
                    </p>
                    <InputError :message="form.errors.lines" class="mb-3" />

                    <ol class="space-y-3">
                        <li v-for="(line, index) in form.lines" :key="line.key" class="rounded-lg border border-border bg-card p-4">
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <span class="text-sm font-medium">Line {{ index + 1 }}</span>
                                <Button
                                    v-if="form.lines.length > 1"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Remove line ${index + 1}`"
                                    @click="removeLine(index)"
                                >
                                    <Trash2 aria-hidden="true" /> Remove
                                </Button>
                            </div>
                            <div :class="['grid gap-3', canSetClearance ? 'sm:grid-cols-[1fr_7rem_9rem_9rem]' : 'sm:grid-cols-[1fr_7rem_9rem]']">
                                <div class="grid gap-1.5">
                                    <Label :for="`line-${line.key}-item`">Item</Label>
                                    <NativeSelect
                                        :id="`line-${line.key}-item`"
                                        v-model="line.item_id"
                                        required
                                        :aria-invalid="!!lineError(index, 'item_id')"
                                        :aria-describedby="`line-${line.key}-item-error`"
                                    >
                                        <option value="" disabled>Choose an item</option>
                                        <option v-for="item in items" :key="item.id" :value="item.id">{{ itemLabel(item) }}</option>
                                    </NativeSelect>
                                    <InputError :id="`line-${line.key}-item-error`" :message="lineError(index, 'item_id')" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label :for="`line-${line.key}-qty`">Quantity</Label>
                                    <Input
                                        :id="`line-${line.key}-qty`"
                                        v-model="line.quantity_ordered"
                                        type="number"
                                        inputmode="numeric"
                                        min="1"
                                        step="1"
                                        required
                                        class="tabular-nums"
                                        :aria-invalid="!!lineError(index, 'quantity_ordered')"
                                        :aria-describedby="`line-${line.key}-qty-error`"
                                    />
                                    <InputError :id="`line-${line.key}-qty-error`" :message="lineError(index, 'quantity_ordered')" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label :for="`line-${line.key}-cost`">Unit cost (optional)</Label>
                                    <Input
                                        :id="`line-${line.key}-cost`"
                                        v-model="line.unit_cost"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        placeholder="0.00"
                                        class="tabular-nums"
                                        :aria-invalid="!!lineError(index, 'unit_cost')"
                                        :aria-describedby="`line-${line.key}-cost-error`"
                                    />
                                    <InputError :id="`line-${line.key}-cost-error`" :message="lineError(index, 'unit_cost')" />
                                </div>
                                <div v-if="canSetClearance" class="grid gap-1.5">
                                    <Label :for="`line-${line.key}-sale`">Clearance price (optional)</Label>
                                    <Input
                                        :id="`line-${line.key}-sale`"
                                        v-model="line.sale_price"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        placeholder="0.00"
                                        class="tabular-nums"
                                        :aria-invalid="!!lineError(index, 'sale_price')"
                                        :aria-describedby="`line-${line.key}-sale-error`"
                                    />
                                    <InputError :id="`line-${line.key}-sale-error`" :message="lineError(index, 'sale_price')" />
                                </div>
                            </div>
                        </li>
                    </ol>

                    <Button type="button" variant="outline" class="mt-3 w-full sm:w-auto" @click="addLine">
                        <Plus aria-hidden="true" /> Add another item
                    </Button>
                </fieldset>

                <div class="flex flex-col-reverse gap-2 border-t border-border pt-6 sm:flex-row sm:justify-end">
                    <Button as-child variant="outline">
                        <Link :href="route('staff.deliveries.index')">Cancel</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Record delivery' }}</Button>
                </div>
            </form>
        </div>
    </StaffLayout>
</template>
