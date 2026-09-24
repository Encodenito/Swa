<script setup lang="ts">
import ClearanceBadge from '@/components/shop/ClearanceBadge.vue';
import PriceTag from '@/components/shop/PriceTag.vue';
import ItemImage from '@/components/shop/customer/ItemImage.vue';
import type { ItemCard } from '@/types';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ item: ItemCard }>();

const soldOut = computed(() => props.item.available === 0);
const subtitle = computed(() => [props.item.brand, props.item.model].filter(Boolean).join(' '));
</script>

<template>
    <Link
        :href="route('items.show', { item: item.slug })"
        class="group flex h-full flex-col overflow-hidden rounded-lg border border-border bg-card hover:border-primary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    >
        <div class="relative aspect-[4/3] border-b border-border bg-white p-3 dark:bg-muted">
            <ItemImage :src="item.image_url" :alt="item.name" :class="{ 'opacity-50': soldOut }" />
            <ClearanceBadge v-if="item.clearance" class="absolute left-2 top-2" />
        </div>
        <div class="flex flex-1 flex-col gap-1 p-4">
            <p v-if="item.category" class="text-xs text-muted-foreground">{{ item.category }}</p>
            <h2 class="font-semibold leading-snug group-hover:text-primary">{{ item.name }}</h2>
            <p v-if="subtitle" class="text-sm text-muted-foreground">{{ subtitle }}</p>
            <div class="mt-auto flex flex-wrap items-end justify-between gap-2 pt-3">
                <PriceTag :price="item.current_price" :regular-price="item.clearance ? item.price : null" :clearance="item.clearance" />
                <span v-if="soldOut" class="text-sm font-semibold text-muted-foreground">Sold out</span>
                <span v-else-if="item.low_stock" class="text-sm font-semibold text-amber-700 dark:text-amber-400"
                    >Only {{ item.available }} left</span
                >
            </div>
        </div>
    </Link>
</template>
