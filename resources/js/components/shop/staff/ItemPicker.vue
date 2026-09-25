<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PriceTag from '@/components/shop/PriceTag.vue';
import QuantityStepper from '@/components/shop/customer/QuantityStepper.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ItemCard } from '@/types';
import { Check, Plus, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

/**
 * Pick in-stock items and quantities for a sale or reservation. Shows unit prices only:
 * the server prices the lines (from locked batches) and computes every total.
 */
const props = withDefaults(
    defineProps<{
        idPrefix: string;
        items: (ItemCard & { sku: string | null })[];
        /** Upper limit per line on top of what is available, e.g. the max per item setting. */
        maxQuantity?: number | null;
        errors?: Record<string, string | undefined>;
        disabled?: boolean;
    }>(),
    { maxQuantity: null, errors: () => ({}), disabled: false },
);

const lines = defineModel<{ item_id: number; quantity: number }[]>('lines', { required: true });

const search = ref('');
const byId = computed(() => new Map(props.items.map((item) => [item.id, item])));
const picked = computed(() => new Set(lines.value.map((line) => line.item_id)));

const results = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (term === '') return props.items;

    return props.items.filter((item) => item.name.toLowerCase().includes(term) || (item.sku ?? '').toLowerCase().includes(term));
});

function maxFor(itemId: number): number {
    const available = byId.value.get(itemId)?.available ?? 1;

    return props.maxQuantity ? Math.min(available, props.maxQuantity) : available;
}

async function add(itemId: number) {
    if (picked.value.has(itemId)) return;
    lines.value = [...lines.value, { item_id: itemId, quantity: 1 }];
    await nextTick();
    document.getElementById(`${props.idPrefix}-qty-${itemId}`)?.focus();
}

function remove(index: number) {
    lines.value = lines.value.filter((_, i) => i !== index);
}

function setQuantity(index: number, quantity: number) {
    lines.value = lines.value.map((line, i) => (i === index ? { ...line, quantity } : line));
}

function lineError(index: number): string | undefined {
    return props.errors[`lines.${index}.quantity`] ?? props.errors[`lines.${index}.item_id`] ?? props.errors[`lines.${index}`];
}
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-1.5">
            <Label :for="`${idPrefix}-search`">Find an item</Label>
            <Input
                :id="`${idPrefix}-search`"
                v-model="search"
                type="search"
                autocomplete="off"
                placeholder="Name or SKU"
                :aria-controls="`${idPrefix}-results`"
                :disabled="disabled"
            />
        </div>

        <p v-if="items.length === 0" class="rounded-md border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
            Nothing is in stock right now.
        </p>
        <p v-else-if="results.length === 0" class="rounded-md border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
            No in-stock item matches "{{ search.trim() }}".
        </p>
        <ul
            v-else
            :id="`${idPrefix}-results`"
            :aria-label="`Items in stock (${results.length})`"
            class="max-h-80 divide-y divide-border overflow-y-auto rounded-md border border-border"
        >
            <li v-for="item in results" :key="item.id" class="flex items-center gap-3 p-3">
                <div class="min-w-0 flex-1 space-y-1">
                    <p class="font-medium leading-snug">{{ item.name }}</p>
                    <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                        <span v-if="item.sku" class="font-mono">{{ item.sku }}</span>
                        <span>{{ item.available }} available</span>
                    </p>
                    <PriceTag :price="item.current_price" :regular-price="item.clearance ? item.price : null" :clearance="item.clearance" size="sm" />
                </div>
                <Button
                    type="button"
                    size="sm"
                    :variant="picked.has(item.id) ? 'ghost' : 'outline'"
                    class="h-10 shrink-0"
                    :disabled="disabled || picked.has(item.id)"
                    :aria-label="picked.has(item.id) ? `${item.name} added` : `Add ${item.name}`"
                    @click="add(item.id)"
                >
                    <Check v-if="picked.has(item.id)" class="size-4" aria-hidden="true" />
                    <Plus v-else class="size-4" aria-hidden="true" />
                    {{ picked.has(item.id) ? 'Added' : 'Add' }}
                </Button>
            </li>
        </ul>

        <section :aria-labelledby="`${idPrefix}-lines-heading`" class="space-y-2">
            <h3 :id="`${idPrefix}-lines-heading`" class="font-semibold">Selected items ({{ lines.length }})</h3>
            <p v-if="lines.length === 0" class="text-sm text-muted-foreground">No items yet. Add them from the list above.</p>
            <ul v-else class="divide-y divide-border rounded-md border border-border">
                <li v-for="(line, index) in lines" :key="line.item_id" class="space-y-2 p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium leading-snug">{{ byId.get(line.item_id)?.name ?? 'Item no longer in stock' }}</p>
                            <PriceTag
                                v-if="byId.get(line.item_id)"
                                :price="byId.get(line.item_id)!.current_price"
                                :regular-price="byId.get(line.item_id)!.clearance ? byId.get(line.item_id)!.price : null"
                                :clearance="byId.get(line.item_id)!.clearance"
                                size="sm"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-10 shrink-0"
                            :disabled="disabled"
                            :aria-label="`Remove ${byId.get(line.item_id)?.name ?? 'item'}`"
                            @click="remove(index)"
                        >
                            <Trash2 class="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <Label :for="`${idPrefix}-qty-${line.item_id}`" class="text-sm text-muted-foreground">Quantity</Label>
                        <QuantityStepper
                            :id="`${idPrefix}-qty-${line.item_id}`"
                            label="Quantity"
                            :model-value="line.quantity"
                            :max="maxFor(line.item_id)"
                            :disabled="disabled"
                            :invalid="!!lineError(index)"
                            :describedby="`${idPrefix}-line-${line.item_id}-error`"
                            @update:model-value="setQuantity(index, $event)"
                        />
                        <span class="text-xs text-muted-foreground">Max {{ maxFor(line.item_id) }}</span>
                    </div>
                    <InputError :id="`${idPrefix}-line-${line.item_id}-error`" :message="lineError(index)" />
                </li>
            </ul>
            <InputError :message="errors.lines" />
        </section>
    </div>
</template>
