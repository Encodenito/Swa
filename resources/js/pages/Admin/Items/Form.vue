<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/shop/staff/NativeSelect.vue';
import TextArea from '@/components/shop/staff/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { ItemCard } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';

type AdminItem = ItemCard & {
    sku: string | null;
    description: string | null;
    category_id: number;
    low_stock_threshold: number;
    active: boolean;
    superseded_by_id: number | null;
    deleted_at: string | null;
};

const props = defineProps<{
    item: AdminItem | null;
    categories: { id: number; name: string }[];
    replacementOptions: { id: number; name: string }[];
}>();

const editing = computed(() => props.item !== null);
const title = computed(() => (props.item ? `Edit ${props.item.name}` : 'Add item'));

const breadcrumbs = computed(() => [
    { title: 'Admin dashboard', href: route('admin.home') },
    { title: 'Items', href: route('admin.items.index') },
    props.item
        ? { title: props.item.name, href: route('admin.items.edit', { item: props.item.slug }) }
        : { title: 'Add item', href: route('admin.items.create') },
]);

const form = useForm({
    category_id: (props.item?.category_id ?? '') as number | '',
    name: props.item?.name ?? '',
    brand: props.item?.brand ?? '',
    model: props.item?.model ?? '',
    sku: props.item?.sku ?? '',
    description: props.item?.description ?? '',
    price: props.item?.price ?? '',
    image: null as File | null,
    low_stock_threshold: props.item?.low_stock_threshold ?? 3,
    active: props.item?.active ?? true,
    superseded_by_id: (props.item?.superseded_by_id ?? '') as number | '',
});

// Local preview of a newly chosen photo.
const preview = ref<string | null>(null);

function onImage(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.image = file;
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = file ? URL.createObjectURL(file) : null;
}

onBeforeUnmount(() => {
    if (preview.value) URL.revokeObjectURL(preview.value);
});

function submit() {
    const url = props.item ? route('admin.items.update', { item: props.item.slug }) : route('admin.items.store');

    form.transform((data) => ({
        ...data,
        superseded_by_id: data.superseded_by_id === '' ? null : data.superseded_by_id,
        active: data.active ? 1 : 0,
    })).post(url, { forceFormData: true, preserveScroll: true });
}

const describedBy = (field: string) => `${field}-error`;
</script>

<template>
    <Head :title="title" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="max-w-3xl px-4 py-6">
            <Heading
                :title="title"
                :description="
                    editing
                        ? 'Changes to the price apply to new reservations only.'
                        : 'After adding an item, receive its stock through Stock Receiving.'
                "
            />

            <form class="space-y-8" novalidate @submit.prevent="submit">
                <fieldset class="grid gap-4 sm:grid-cols-2">
                    <legend class="mb-3 font-semibold">Basics</legend>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            maxlength="200"
                            :aria-invalid="!!form.errors.name"
                            :aria-describedby="describedBy('name')"
                        />
                        <InputError :id="describedBy('name')" :message="form.errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="category_id">Category</Label>
                        <NativeSelect
                            id="category_id"
                            v-model="form.category_id"
                            required
                            :aria-invalid="!!form.errors.category_id"
                            :aria-describedby="describedBy('category_id')"
                        >
                            <option value="" disabled>Choose a category</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </NativeSelect>
                        <InputError :id="describedBy('category_id')" :message="form.errors.category_id" />
                        <p v-if="categories.length === 0" class="text-sm text-muted-foreground">
                            No categories yet.
                            <Link :href="route('admin.categories.index')" class="underline underline-offset-4">Add one first</Link>.
                        </p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="sku">SKU (optional)</Label>
                        <Input
                            id="sku"
                            v-model="form.sku"
                            maxlength="64"
                            class="font-mono"
                            :aria-invalid="!!form.errors.sku"
                            :aria-describedby="describedBy('sku')"
                        />
                        <InputError :id="describedBy('sku')" :message="form.errors.sku" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="brand">Brand (optional)</Label>
                        <Input
                            id="brand"
                            v-model="form.brand"
                            maxlength="100"
                            :aria-invalid="!!form.errors.brand"
                            :aria-describedby="describedBy('brand')"
                        />
                        <InputError :id="describedBy('brand')" :message="form.errors.brand" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="model">Model (optional)</Label>
                        <Input
                            id="model"
                            v-model="form.model"
                            maxlength="100"
                            :aria-invalid="!!form.errors.model"
                            :aria-describedby="describedBy('model')"
                        />
                        <InputError :id="describedBy('model')" :message="form.errors.model" />
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="description">Description (optional)</Label>
                        <TextArea
                            id="description"
                            v-model="form.description"
                            rows="5"
                            maxlength="5000"
                            :aria-invalid="!!form.errors.description"
                            :aria-describedby="describedBy('description')"
                        />
                        <InputError :id="describedBy('description')" :message="form.errors.description" />
                    </div>
                </fieldset>

                <fieldset class="grid gap-4 sm:grid-cols-2">
                    <legend class="mb-3 font-semibold">Price and stock</legend>
                    <div class="grid gap-1.5">
                        <Label for="price">Regular price (₱)</Label>
                        <Input
                            id="price"
                            v-model="form.price"
                            inputmode="decimal"
                            autocomplete="off"
                            required
                            placeholder="1234.50"
                            class="tabular-nums"
                            :aria-invalid="!!form.errors.price"
                            :aria-describedby="describedBy('price')"
                        />
                        <InputError :id="describedBy('price')" :message="form.errors.price" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="low_stock_threshold">Low stock warning at</Label>
                        <Input
                            id="low_stock_threshold"
                            v-model.number="form.low_stock_threshold"
                            type="number"
                            inputmode="numeric"
                            min="0"
                            max="1000"
                            step="1"
                            required
                            class="tabular-nums"
                            :aria-invalid="!!form.errors.low_stock_threshold"
                            aria-describedby="low_stock_threshold-help low_stock_threshold-error"
                        />
                        <p id="low_stock_threshold-help" class="text-sm text-muted-foreground">
                            Flag the item as low stock at this many units or fewer.
                        </p>
                        <InputError id="low_stock_threshold-error" :message="form.errors.low_stock_threshold" />
                    </div>
                </fieldset>

                <fieldset class="space-y-3">
                    <legend class="mb-3 font-semibold">Photo</legend>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                        <div class="flex size-32 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-muted">
                            <img
                                v-if="preview || item?.image_url"
                                :src="preview ?? item?.image_url ?? ''"
                                alt="Item photo preview"
                                class="size-full object-contain"
                            />
                            <span v-else class="px-2 text-center text-xs text-muted-foreground">No photo</span>
                        </div>
                        <div class="grid flex-1 gap-1.5">
                            <Label for="image">{{ item?.image_url ? 'Replace photo (optional)' : 'Photo (optional)' }}</Label>
                            <input
                                id="image"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="block w-full text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                :aria-invalid="!!form.errors.image"
                                aria-describedby="image-help image-error"
                                @change="onImage"
                            />
                            <p id="image-help" class="text-sm text-muted-foreground">JPG, PNG or WebP, up to 2 MB.</p>
                            <progress v-if="form.progress" :value="form.progress.percentage" max="100" class="h-1.5 w-full">
                                {{ form.progress.percentage }}%
                            </progress>
                            <InputError id="image-error" :message="form.errors.image" />
                        </div>
                    </div>
                </fieldset>

                <fieldset class="space-y-4">
                    <legend class="mb-3 font-semibold">Visibility</legend>
                    <div class="grid gap-1.5">
                        <div class="flex items-start gap-3">
                            <Checkbox id="active" v-model:checked="form.active" class="mt-0.5" aria-describedby="active-help active-error" />
                            <div>
                                <Label for="active">Show in the catalog</Label>
                                <p id="active-help" class="mt-1 text-sm text-muted-foreground">
                                    Untick to hide it from shoppers without removing it.
                                </p>
                            </div>
                        </div>
                        <InputError id="active-error" :message="form.errors.active" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="superseded_by_id">Replaced by (optional)</Label>
                        <NativeSelect
                            id="superseded_by_id"
                            v-model="form.superseded_by_id"
                            :aria-invalid="!!form.errors.superseded_by_id"
                            aria-describedby="superseded_by_id-help superseded_by_id-error"
                        >
                            <option value="">No replacement</option>
                            <option v-for="o in replacementOptions" :key="o.id" :value="o.id">{{ o.name }}</option>
                        </NativeSelect>
                        <p id="superseded_by_id-help" class="text-sm text-muted-foreground">
                            When this item sells out, its page sends shoppers to the replacement.
                        </p>
                        <InputError id="superseded_by_id-error" :message="form.errors.superseded_by_id" />
                    </div>
                </fieldset>

                <div class="flex flex-col-reverse gap-2 border-t border-border pt-6 sm:flex-row sm:justify-end">
                    <Button as-child variant="outline">
                        <Link :href="route('admin.items.index')">Cancel</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Saving…' : editing ? 'Save changes' : 'Add item' }}
                    </Button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
