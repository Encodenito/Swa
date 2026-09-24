<script setup lang="ts">
import PriceTag from '@/components/shop/PriceTag.vue';
import BatchAdjustDialog from '@/components/shop/staff/BatchAdjustDialog.vue';
import BatchPriceForm from '@/components/shop/staff/BatchPriceForm.vue';
import SignedQuantity from '@/components/shop/staff/SignedQuantity.vue';
import StockLevel from '@/components/shop/staff/StockLevel.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { ItemCard } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Batch {
    id: number;
    quantity_remaining: number;
    sale_price: string | null;
    received_at: string;
}

interface Movement {
    id: number;
    batch_id: number;
    type: string;
    quantity: number;
    reason: string | null;
    reference_code: string | null;
    by: string | null;
    created_at: string | null;
}

const props = defineProps<{ item: ItemCard; batches: Batch[]; movements: Movement[]; canSetClearance: boolean }>();

const breadcrumbs = computed(() => [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Stock', href: route('staff.stock.index') },
    { title: props.item.name, href: route('staff.stock.show', { item: props.item.slug }) },
]);

const ordinals = ['first', 'second', 'third'];

// Batches arrive oldest first; the oldest batch with units left sells first.
const queue = computed(() => {
    let position = 0;

    return props.batches.map((batch) => {
        if (batch.quantity_remaining <= 0) return { batch, label: 'Empty', first: false };
        const label = position < ordinals.length ? `Sells ${ordinals[position]}` : `Sells ${position + 1}th`;
        position++;

        return { batch, label, first: position === 1 };
    });
});

const movementLabel: Record<string, string> = {
    received: 'Received',
    reserved: 'Reserved',
    released: 'Released',
    sold: 'Picked up',
    damaged: 'Damaged',
    adjusted: 'Count correction',
};

const batchName = (id: number) => `Batch #${id}`;
</script>

<template>
    <Head :title="`Stock: ${item.name}`" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-8 px-4 py-6">
            <header class="flex flex-col gap-4 border-b border-border pb-6 sm:flex-row sm:items-start">
                <img
                    v-if="item.image_url"
                    :src="item.image_url"
                    alt=""
                    class="size-20 shrink-0 rounded-md border border-border bg-muted object-contain"
                />
                <div class="min-w-0 flex-1 space-y-2">
                    <h2 class="text-xl font-semibold tracking-tight">{{ item.name }}</h2>
                    <p v-if="item.brand || item.model || item.category" class="text-sm text-muted-foreground">
                        {{ [item.brand, item.model].filter(Boolean).join(' ') }}<template v-if="item.category"> in {{ item.category }}</template>
                    </p>
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                        <PriceTag :price="item.current_price" :regular-price="item.price" :clearance="item.clearance" />
                        <span
                            ><span class="text-muted-foreground">Available</span> <StockLevel :available="item.available" :low-stock="item.low_stock"
                        /></span>
                    </div>
                </div>
            </header>

            <section aria-labelledby="batches-heading">
                <h3 id="batches-heading" class="font-semibold">Batches</h3>
                <p class="mb-3 mt-1 text-sm text-muted-foreground">Oldest stock sells first. Reservations take units from the top of this list.</p>

                <p v-if="batches.length === 0" class="rounded-lg border border-dashed border-border p-6 text-sm text-muted-foreground">
                    No stock has been received for this item yet.
                    <Link :href="route('staff.deliveries.create')" class="font-medium text-foreground underline underline-offset-4"
                        >Record a delivery</Link
                    >
                </p>

                <ol v-else class="space-y-3">
                    <li
                        v-for="{ batch, label, first } in queue"
                        :key="batch.id"
                        :class="[
                            'grid gap-4 rounded-lg border bg-card p-4',
                            canSetClearance ? 'md:grid-cols-[1fr_16rem_auto]' : 'sm:grid-cols-[1fr_auto]',
                            first ? 'border-foreground/40' : 'border-border',
                            batch.quantity_remaining <= 0 ? 'opacity-70' : '',
                        ]"
                    >
                        <div class="min-w-0 space-y-1 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ batchName(batch.id) }}</span>
                                <span
                                    :class="[
                                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                                        first ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                                    ]"
                                    >{{ label }}</span
                                >
                            </div>
                            <p class="text-muted-foreground">Received {{ formatDate(batch.received_at) }}</p>
                            <p>
                                <span class="text-lg font-semibold tabular-nums">{{ batch.quantity_remaining }}</span>
                                <span class="text-muted-foreground"> left</span>
                                <template v-if="batch.sale_price">
                                    <span class="text-muted-foreground">, clearance at </span>
                                    <span class="font-medium tabular-nums">{{ formatPeso(batch.sale_price) }}</span>
                                </template>
                            </p>
                        </div>
                        <BatchPriceForm v-if="canSetClearance" :batch-id="batch.id" :sale-price="batch.sale_price" />
                        <div class="flex items-start">
                            <BatchAdjustDialog :batch-id="batch.id" :batch-label="batchName(batch.id)" :remaining="batch.quantity_remaining" />
                        </div>
                    </li>
                </ol>
            </section>

            <section aria-labelledby="ledger-heading">
                <h3 id="ledger-heading" class="font-semibold">Stock history</h3>
                <p class="mb-3 mt-1 text-sm text-muted-foreground">The latest 50 movements, newest first.</p>

                <p v-if="movements.length === 0" class="rounded-lg border border-dashed border-border p-6 text-sm text-muted-foreground">
                    No stock movements yet.
                </p>

                <template v-else>
                    <div class="hidden overflow-hidden rounded-lg border border-border md:block">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 font-medium">When</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">What</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-medium">Units</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Batch</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Details</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">By</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="m in movements" :key="m.id">
                                    <td class="whitespace-nowrap px-4 py-3 text-muted-foreground">{{ formatDateTime(m.created_at) }}</td>
                                    <td class="px-4 py-3">{{ movementLabel[m.type] ?? m.type }}</td>
                                    <td class="px-4 py-3 text-right"><SignedQuantity :quantity="m.quantity" /></td>
                                    <td class="px-4 py-3 text-muted-foreground">#{{ m.batch_id }}</td>
                                    <td class="px-4 py-3">
                                        <Link
                                            v-if="m.reference_code"
                                            :href="route('staff.reservations.show', { reservation: m.reference_code })"
                                            class="font-mono underline-offset-4 hover:underline"
                                            >{{ m.reference_code }}</Link
                                        >
                                        <span v-if="m.reason" :class="m.reference_code ? 'block text-muted-foreground' : ''">{{ m.reason }}</span>
                                        <span v-if="!m.reference_code && !m.reason" class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ m.by ?? 'System' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <ul class="divide-y divide-border rounded-lg border border-border md:hidden">
                        <li v-for="m in movements" :key="m.id" class="space-y-1 p-4 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium">{{ movementLabel[m.type] ?? m.type }}</span>
                                <SignedQuantity :quantity="m.quantity" />
                            </div>
                            <p class="text-muted-foreground">
                                {{ formatDateTime(m.created_at) }}, batch #{{ m.batch_id }}, by {{ m.by ?? 'system' }}
                            </p>
                            <Link
                                v-if="m.reference_code"
                                :href="route('staff.reservations.show', { reservation: m.reference_code })"
                                class="block font-mono underline-offset-4 hover:underline"
                                >{{ m.reference_code }}</Link
                            >
                            <p v-if="m.reason">{{ m.reason }}</p>
                        </li>
                    </ul>
                </template>
            </section>
        </div>
    </StaffLayout>
</template>
