<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ConfirmDialog from '@/components/shop/staff/ConfirmDialog.vue';
import DeliveryStatusChip from '@/components/shop/staff/DeliveryStatusChip.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface DeliveryLine {
    id: number;
    item: string;
    sku: string | null;
    quantity_ordered: number;
    quantity_received: number | null;
    unit_cost: string | null;
    sale_price: string | null;
}

const props = defineProps<{
    delivery: {
        id: number;
        supplier: string;
        reference: string | null;
        status: string;
        expected_at: string | null;
        received_at: string | null;
        received_by: string | null;
        lines: DeliveryLine[];
    };
}>();

const title = computed(() => `Delivery from ${props.delivery.supplier}`);

const breadcrumbs = computed(() => [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Stock Receiving', href: route('staff.deliveries.index') },
    { title: props.delivery.reference ?? `Delivery #${props.delivery.id}`, href: route('staff.deliveries.show', { delivery: props.delivery.id }) },
]);

const isOrdered = computed(() => props.delivery.status === 'ordered');

const form = useForm({
    received: Object.fromEntries(props.delivery.lines.map((line) => [line.id, line.quantity_ordered])) as Record<number, number>,
});

const confirming = ref(false);

function receivedError(lineId: number): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`received.${lineId}`];
}

const differs = computed(() => props.delivery.lines.filter((line) => Number(form.received[line.id]) !== line.quantity_ordered));

function receive() {
    form.post(route('staff.deliveries.receive', { delivery: props.delivery.id }), {
        preserveScroll: true,
        onFinish: () => (confirming.value = false),
    });
}
</script>

<template>
    <Head :title="title" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="max-w-4xl px-4 py-6">
            <header class="mb-6 space-y-2 border-b border-border pb-6">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="text-xl font-semibold tracking-tight">{{ title }}</h2>
                    <DeliveryStatusChip :status="delivery.status" />
                </div>
                <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                    <dt class="text-muted-foreground">Supplier reference</dt>
                    <dd>{{ delivery.reference ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Expected on</dt>
                    <dd>{{ formatDate(delivery.expected_at) }}</dd>
                    <template v-if="delivery.received_at">
                        <dt class="text-muted-foreground">Received</dt>
                        <dd>
                            {{ formatDateTime(delivery.received_at) }}<template v-if="delivery.received_by"> by {{ delivery.received_by }}</template>
                        </dd>
                    </template>
                </dl>
            </header>

            <p v-if="delivery.lines.length === 0" class="text-sm text-muted-foreground">This delivery has no items.</p>

            <!-- Receive form -->
            <form v-else-if="isOrdered" @submit.prevent="confirming = true">
                <h3 class="font-semibold">Count what arrived</h3>
                <p class="mb-4 mt-1 text-sm text-muted-foreground">
                    Each quantity starts at what was ordered. Change it if fewer units arrived; enter 0 for an item that did not come.
                </p>
                <InputError :message="form.errors.received" class="mb-3" />

                <ul class="divide-y divide-border rounded-lg border border-border">
                    <li v-for="line in delivery.lines" :key="line.id" class="grid gap-3 p-4 sm:grid-cols-[1fr_9rem] sm:items-center">
                        <div class="min-w-0 text-sm">
                            <label :for="`received-${line.id}`" class="block font-medium">{{ line.item }}</label>
                            <span class="block text-muted-foreground">
                                <template v-if="line.sku">SKU {{ line.sku }}. </template>Ordered
                                <span class="tabular-nums">{{ line.quantity_ordered }}</span>
                                <template v-if="line.unit_cost">, cost {{ formatPeso(line.unit_cost) }} each</template>
                                <template v-if="line.sale_price">, clearance {{ formatPeso(line.sale_price) }}</template>
                            </span>
                        </div>
                        <div>
                            <Input
                                :id="`received-${line.id}`"
                                v-model.number="form.received[line.id]"
                                type="number"
                                inputmode="numeric"
                                min="0"
                                step="1"
                                required
                                class="h-11 text-right text-base tabular-nums"
                                :aria-invalid="!!receivedError(line.id)"
                                :aria-describedby="`received-${line.id}-error`"
                            />
                            <InputError :id="`received-${line.id}-error`" :message="receivedError(line.id)" />
                        </div>
                    </li>
                </ul>

                <div class="mt-6 flex justify-end">
                    <Button type="submit" size="lg" class="w-full sm:w-auto" :disabled="form.processing">Receive into stock</Button>
                </div>
            </form>

            <!-- Received or cancelled -->
            <template v-else>
                <div class="hidden overflow-hidden rounded-lg border border-border md:block">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">Item</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">Ordered</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">Received</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">Unit cost</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">Clearance price</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="line in delivery.lines" :key="line.id">
                                <td class="px-4 py-3">
                                    <span class="block font-medium">{{ line.item }}</span>
                                    <span v-if="line.sku" class="block text-muted-foreground">SKU {{ line.sku }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ line.quantity_ordered }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <span class="font-semibold">{{ line.quantity_received ?? '—' }}</span>
                                    <span
                                        v-if="line.quantity_received !== null && line.quantity_received < line.quantity_ordered"
                                        class="block text-xs text-amber-700 dark:text-amber-400"
                                        >Short {{ line.quantity_ordered - line.quantity_received }}</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ line.unit_cost ? formatPeso(line.unit_cost) : '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ line.sale_price ? formatPeso(line.sale_price) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ul class="divide-y divide-border rounded-lg border border-border md:hidden">
                    <li v-for="line in delivery.lines" :key="line.id" class="space-y-1 p-4 text-sm">
                        <span class="block font-medium">{{ line.item }}</span>
                        <span v-if="line.sku" class="block text-muted-foreground">SKU {{ line.sku }}</span>
                        <dl class="grid grid-cols-2 gap-1 pt-1">
                            <dt class="text-muted-foreground">Ordered</dt>
                            <dd class="text-right tabular-nums">{{ line.quantity_ordered }}</dd>
                            <dt class="text-muted-foreground">Received</dt>
                            <dd class="text-right font-semibold tabular-nums">{{ line.quantity_received ?? '—' }}</dd>
                            <template v-if="line.unit_cost">
                                <dt class="text-muted-foreground">Unit cost</dt>
                                <dd class="text-right tabular-nums">{{ formatPeso(line.unit_cost) }}</dd>
                            </template>
                            <template v-if="line.sale_price">
                                <dt class="text-muted-foreground">Clearance price</dt>
                                <dd class="text-right tabular-nums">{{ formatPeso(line.sale_price) }}</dd>
                            </template>
                        </dl>
                    </li>
                </ul>
            </template>
        </div>

        <ConfirmDialog
            v-model:open="confirming"
            title="Receive this delivery into stock?"
            description="The received units become available to customers right away. You cannot edit the quantities afterwards; fix mistakes with a stock adjustment."
            confirm-label="Receive into stock"
            :processing="form.processing"
            @confirm="receive"
        >
            <p v-if="differs.length" class="text-sm">
                {{ differs.length === 1 ? '1 item differs' : `${differs.length} items differ` }} from the order:
                <span class="font-medium">{{ differs.map((l) => `${l.item} (${form.received[l.id]} of ${l.quantity_ordered})`).join(', ') }}</span
                >.
            </p>
            <p v-else class="text-sm">All items arrived as ordered.</p>
        </ConfirmDialog>
    </StaffLayout>
</template>
