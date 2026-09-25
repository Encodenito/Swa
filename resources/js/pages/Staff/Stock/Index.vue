<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import ClearanceBadge from '@/components/shop/ClearanceBadge.vue';
import Pagination from '@/components/shop/Pagination.vue';
import LoadingRegion from '@/components/shop/skeleton/LoadingRegion.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import StockLevel from '@/components/shop/staff/StockLevel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatPeso } from '@/lib/money';
import type { ItemCard, Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

type StockItem = ItemCard & { sku: string | null; active: boolean };

const props = defineProps<{ items: Paginated<StockItem>; q: string }>();

const breadcrumbs = [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Stock', href: route('staff.stock.index') },
];

const query = ref(props.q ?? '');
const searching = ref(false);

function search() {
    const q = query.value.trim();
    router.get(route('staff.stock.index'), q === '' ? {} : { q }, {
        preserveState: true,
        replace: true,
        onStart: () => (searching.value = true),
        onFinish: () => (searching.value = false),
    });
}

function rowTone(item: StockItem): string {
    if (item.available <= 0) return 'bg-danger-surface/60';
    if (item.low_stock) return 'bg-warning-surface/60';

    return '';
}
</script>

<template>
    <Head title="Stock" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading title="Stock" description="Units available to customers right now. Open an item to see its batches and history." />

            <form role="search" class="mb-6 flex max-w-xl items-end gap-2" @submit.prevent="search">
                <div class="grid flex-1 gap-1.5">
                    <Label for="stock-search">Search by name or SKU</Label>
                    <Input id="stock-search" v-model="query" type="search" autocomplete="off" placeholder="e.g. Logitech M185" />
                </div>
                <Button type="submit" class="h-10" :disabled="searching">{{ searching ? 'Searching…' : 'Search' }}</Button>
            </form>

            <LoadingRegion :loading="searching">
                <EmptyState
                    v-if="items.data.length === 0"
                    :title="q ? `No items match “${q}”` : 'No items yet'"
                    :description="q ? 'Try part of the name or the SKU.' : 'Items appear here once an admin adds them to the catalog.'"
                />

                <template v-else>
                    <div class="hidden overflow-hidden rounded-lg border border-border md:block">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Item</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">SKU</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Available</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-medium">Current price</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="item in items.data" :key="item.id" :class="['hover:bg-accent/50', rowTone(item)]">
                                    <td class="px-4 py-3">
                                        <Link
                                            :href="route('staff.stock.show', { item: item.slug })"
                                            class="font-medium underline-offset-4 hover:underline"
                                            >{{ item.name }}</Link
                                        >
                                        <span v-if="!item.active" class="ml-2 text-xs text-muted-foreground">(hidden from catalog)</span>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ item.sku ?? '—' }}</td>
                                    <td class="px-4 py-3"><StockLevel :available="item.available" :low-stock="item.low_stock" /></td>
                                    <td class="px-4 py-3 text-right">
                                        <span class="inline-flex items-center gap-2">
                                            <ClearanceBadge v-if="item.clearance" />
                                            <span class="tabular-nums">{{ formatPeso(item.current_price) }}</span>
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <ul class="divide-y divide-border rounded-lg border border-border md:hidden">
                        <li v-for="item in items.data" :key="item.id" :class="rowTone(item)">
                            <Link :href="route('staff.stock.show', { item: item.slug })" class="block space-y-1 p-4 hover:bg-accent/50">
                                <span class="block font-medium">{{ item.name }}</span>
                                <span class="block text-xs text-muted-foreground">
                                    <span v-if="item.sku" class="font-mono">{{ item.sku }}</span>
                                    <template v-if="!item.active"> (hidden from catalog)</template>
                                </span>
                                <span class="flex items-center justify-between gap-2 text-sm">
                                    <StockLevel :available="item.available" :low-stock="item.low_stock" />
                                    <span class="inline-flex items-center gap-2">
                                        <ClearanceBadge v-if="item.clearance" />
                                        <span class="tabular-nums">{{ formatPeso(item.current_price) }}</span>
                                    </span>
                                </span>
                            </Link>
                        </li>
                    </ul>

                    <Pagination :links="items.links" />
                </template>
            </LoadingRegion>
        </div>
    </StaffLayout>
</template>
