<script setup lang="ts">
import Pagination from '@/components/shop/Pagination.vue';
import PriceTag from '@/components/shop/PriceTag.vue';
import LoadingRegion from '@/components/shop/skeleton/LoadingRegion.vue';
import ConfirmDialog from '@/components/shop/staff/ConfirmDialog.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import StockLevel from '@/components/shop/staff/StockLevel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { ItemCard, Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { ref } from 'vue';

type AdminItem = ItemCard & {
    sku: string | null;
    description: string | null;
    category_id: number;
    low_stock_threshold: number;
    active: boolean;
    superseded_by_id: number | null;
    deleted_at: string | null;
};

const props = defineProps<{ items: Paginated<AdminItem>; q: string }>();

const breadcrumbs = [
    { title: 'Admin dashboard', href: route('admin.home') },
    { title: 'Items', href: route('admin.items.index') },
];

const query = ref(props.q ?? '');
const searching = ref(false);

function search() {
    const q = query.value.trim();
    router.get(route('admin.items.index'), q === '' ? {} : { q }, {
        preserveState: true,
        replace: true,
        onStart: () => (searching.value = true),
        onFinish: () => (searching.value = false),
    });
}

function state(item: AdminItem): { label: string; tone: string } {
    if (item.deleted_at) return { label: 'Removed', tone: 'bg-muted text-muted-foreground line-through' };
    if (!item.active) return { label: 'Hidden', tone: 'bg-warning-chip text-warning-strong' };

    return { label: 'Active', tone: 'bg-success-chip text-success-strong' };
}

// Remove (soft delete)
const removing = ref<AdminItem | null>(null);
const confirmOpen = ref(false);
const removeProcessing = ref(false);

function askRemove(item: AdminItem) {
    removing.value = item;
    confirmOpen.value = true;
}

function remove() {
    if (!removing.value) return;
    router.delete(route('admin.items.destroy', { item: removing.value.slug }), {
        preserveScroll: true,
        onStart: () => (removeProcessing.value = true),
        onFinish: () => {
            removeProcessing.value = false;
            confirmOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Items" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="mb-6 flex flex-col gap-3 border-b border-border pb-6 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-0.5">
                    <h1 class="text-xl font-semibold tracking-tight">Items</h1>
                    <p class="text-sm text-muted-foreground">Everything in the catalog, including hidden and removed items.</p>
                </div>
                <Button as-child class="shrink-0">
                    <Link :href="route('admin.items.create')"><Plus aria-hidden="true" /> Add item</Link>
                </Button>
            </div>

            <form role="search" class="mb-6 flex max-w-xl items-end gap-2" @submit.prevent="search">
                <div class="grid flex-1 gap-1.5">
                    <Label for="item-search">Search by name or SKU</Label>
                    <Input id="item-search" v-model="query" type="search" autocomplete="off" placeholder="e.g. Kingston 32GB" />
                </div>
                <Button type="submit" class="h-10" :disabled="searching">{{ searching ? 'Searching…' : 'Search' }}</Button>
            </form>

            <LoadingRegion :loading="searching">
                <EmptyState
                    v-if="items.data.length === 0"
                    :title="q ? `No items match “${q}”` : 'No items yet'"
                    :description="q ? 'Try part of the name or the SKU.' : 'Add your first item to start the catalog.'"
                >
                    <Button v-if="!q" as-child variant="outline">
                        <Link :href="route('admin.items.create')">Add item</Link>
                    </Button>
                </EmptyState>

                <template v-else>
                    <ul class="divide-y divide-border rounded-lg border border-border">
                        <li
                            v-for="item in items.data"
                            :key="item.id"
                            :class="['grid gap-3 p-4 sm:grid-cols-[3.5rem_1fr_auto] sm:items-center', item.deleted_at ? 'bg-muted/40' : '']"
                        >
                            <div class="hidden size-14 overflow-hidden rounded-md border border-border bg-muted sm:block">
                                <img v-if="item.image_url" :src="item.image_url" alt="" class="size-full object-contain" loading="lazy" />
                            </div>
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{ item.name }}</span>
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', state(item).tone]">{{ state(item).label }}</span>
                                </div>
                                <p class="text-sm text-muted-foreground">
                                    <span v-if="item.sku" class="font-mono text-xs">{{ item.sku }}</span>
                                    <template v-if="item.sku && item.category">, </template>{{ item.category }}
                                </p>
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                    <PriceTag :price="item.current_price" :regular-price="item.price" :clearance="item.clearance" size="sm" />
                                    <span
                                        ><span class="text-muted-foreground">Available</span>
                                        <StockLevel :available="item.available" :low-stock="item.low_stock"
                                    /></span>
                                </div>
                            </div>
                            <div v-if="!item.deleted_at" class="flex gap-2 sm:justify-end">
                                <Button as-child variant="outline" size="sm">
                                    <Link :href="route('admin.items.edit', { item: item.slug })" :aria-label="`Edit ${item.name}`">Edit</Link>
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="text-destructive"
                                    :aria-label="`Remove ${item.name}`"
                                    @click="askRemove(item)"
                                >
                                    Remove
                                </Button>
                            </div>
                            <p v-else class="text-sm text-muted-foreground sm:text-right">Removed from the catalog</p>
                        </li>
                    </ul>

                    <Pagination :links="items.links" />
                </template>
            </LoadingRegion>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="`Remove “${removing?.name ?? ''}” from the catalog?`"
            description="Shoppers will no longer see or reserve it. Its reservations, stock and history are kept, so past records still make sense."
            confirm-label="Remove item"
            destructive
            :processing="removeProcessing"
            @confirm="remove"
        />
    </AdminLayout>
</template>
