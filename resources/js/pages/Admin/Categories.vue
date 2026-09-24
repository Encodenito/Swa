<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ConfirmDialog from '@/components/shop/staff/ConfirmDialog.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

interface Category {
    id: number;
    name: string;
    slug: string;
    items_count: number;
}

defineProps<{ categories: Category[] }>();

const breadcrumbs = [
    { title: 'Admin dashboard', href: route('admin.home') },
    { title: 'Categories', href: route('admin.categories.index') },
];

// Add
const addForm = useForm({ name: '' });

function add() {
    addForm.post(route('admin.categories.store'), {
        preserveScroll: true,
        onSuccess: () => addForm.reset(),
    });
}

// Inline rename, one row at a time
const editingId = ref<number | null>(null);
const renameForm = useForm({ name: '' });

async function startRename(category: Category) {
    editingId.value = category.id;
    renameForm.clearErrors();
    renameForm.name = category.name;
    await nextTick();
    document.getElementById(`rename-${category.id}`)?.focus();
}

function cancelRename() {
    editingId.value = null;
    renameForm.clearErrors();
}

function rename(category: Category) {
    renameForm.put(route('admin.categories.update', { category: category.id }), {
        preserveScroll: true,
        onSuccess: () => (editingId.value = null),
    });
}

// Delete
const deleting = ref<Category | null>(null);
const confirmOpen = ref(false);
const deleteProcessing = ref(false);

function askDelete(category: Category) {
    deleting.value = category;
    confirmOpen.value = true;
}

function destroy() {
    if (!deleting.value) return;
    router.delete(route('admin.categories.destroy', { category: deleting.value.id }), {
        preserveScroll: true,
        onStart: () => (deleteProcessing.value = true),
        onFinish: () => {
            deleteProcessing.value = false;
            confirmOpen.value = false;
        },
    });
}

const itemsLabel = (n: number) => (n === 1 ? '1 item' : `${n} items`);
</script>

<template>
    <Head title="Categories" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="max-w-3xl px-4 py-6">
            <Heading title="Categories" description="Shoppers browse the catalog by category. Every item belongs to one." />

            <form class="mb-8 rounded-lg border border-border bg-card p-4" @submit.prevent="add">
                <Label for="new-category">New category</Label>
                <div class="mt-1.5 flex flex-col gap-2 sm:flex-row">
                    <Input
                        id="new-category"
                        v-model="addForm.name"
                        maxlength="100"
                        required
                        placeholder="e.g. Printer ink"
                        :aria-invalid="!!addForm.errors.name"
                        aria-describedby="new-category-error"
                    />
                    <Button type="submit" class="h-10 shrink-0" :disabled="addForm.processing">{{
                        addForm.processing ? 'Adding…' : 'Add category'
                    }}</Button>
                </div>
                <InputError id="new-category-error" class="mt-1.5" :message="addForm.errors.name" />
            </form>

            <EmptyState v-if="categories.length === 0" title="No categories yet" description="Add your first category above, then add items to it." />

            <ul v-else class="divide-y divide-border rounded-lg border border-border">
                <li v-for="category in categories" :key="category.id" class="p-4">
                    <form v-if="editingId === category.id" class="space-y-2" @submit.prevent="rename(category)" @keydown.esc="cancelRename">
                        <Label :for="`rename-${category.id}`">Rename “{{ category.name }}”</Label>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <Input
                                :id="`rename-${category.id}`"
                                v-model="renameForm.name"
                                maxlength="100"
                                required
                                :aria-invalid="!!renameForm.errors.name"
                                :aria-describedby="`rename-${category.id}-error`"
                            />
                            <div class="flex gap-2">
                                <Button type="submit" class="h-10 flex-1" :disabled="renameForm.processing">{{
                                    renameForm.processing ? 'Saving…' : 'Save'
                                }}</Button>
                                <Button type="button" variant="outline" class="h-10 flex-1" :disabled="renameForm.processing" @click="cancelRename">
                                    Cancel
                                </Button>
                            </div>
                        </div>
                        <InputError :id="`rename-${category.id}-error`" :message="renameForm.errors.name" />
                    </form>

                    <div v-else class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium">{{ category.name }}</p>
                            <p class="text-sm text-muted-foreground">{{ itemsLabel(category.items_count) }}</p>
                        </div>
                        <div class="flex gap-2">
                            <Button type="button" variant="outline" size="sm" :aria-label="`Rename ${category.name}`" @click="startRename(category)">
                                Rename
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="text-destructive"
                                :aria-label="`Delete ${category.name}`"
                                @click="askDelete(category)"
                            >
                                Delete
                            </Button>
                        </div>
                    </div>
                </li>
            </ul>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="`Delete “${deleting?.name ?? ''}”?`"
            :description="
                deleting && deleting.items_count > 0
                    ? `This category still has ${itemsLabel(deleting.items_count)}. Move them to another category first; the delete will be refused until then.`
                    : 'The category is removed for good. Only empty categories can be deleted.'
            "
            confirm-label="Delete category"
            destructive
            :processing="deleteProcessing"
            @confirm="destroy"
        />
    </AdminLayout>
</template>
