<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';

/** Admin-only clearance price on one batch. Saving it empty removes the clearance price. */
const props = defineProps<{ batchId: number; salePrice: string | null }>();

const form = useForm({ sale_price: props.salePrice ?? '' });
const id = `batch-price-${props.batchId}`;

function save() {
    form.transform((data) => ({ sale_price: data.sale_price.trim() === '' ? null : data.sale_price.trim() })).put(
        route('admin.batches.price', { batch: props.batchId }),
        { preserveScroll: true },
    );
}

function remove() {
    form.sale_price = '';
    save();
}
</script>

<template>
    <form class="grid gap-1.5" @submit.prevent="save">
        <Label :for="id">Clearance price (optional)</Label>
        <div class="flex gap-2">
            <Input
                :id="id"
                v-model="form.sale_price"
                inputmode="decimal"
                autocomplete="off"
                placeholder="Regular price"
                class="tabular-nums"
                :aria-invalid="!!form.errors.sale_price"
                :aria-describedby="`${id}-help ${id}-error`"
            />
            <Button type="submit" variant="secondary" class="h-10 shrink-0" :disabled="form.processing">Save</Button>
        </div>
        <p :id="`${id}-help`" class="text-xs text-muted-foreground">Units already reserved keep the price they were reserved at.</p>
        <InputError :id="`${id}-error`" :message="form.errors.sale_price" />
        <Button v-if="salePrice" type="button" variant="link" class="h-auto justify-start p-0 text-sm" :disabled="form.processing" @click="remove">
            Remove clearance price
        </Button>
    </form>
</template>
