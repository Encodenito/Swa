<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Supplier {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    deliveries_count: number;
}

const props = defineProps<{ suppliers: Supplier[]; canEdit: boolean }>();

// Staff also reach this page (read only); render it in their own layout.
const Layout = computed(() => (props.canEdit ? AdminLayout : StaffLayout));

const breadcrumbs = computed(() => [
    props.canEdit ? { title: 'Admin dashboard', href: route('admin.home') } : { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Suppliers', href: route('staff.suppliers.index') },
]);

// Add
const addForm = useForm({ name: '', phone: '', email: '' });

function add() {
    addForm.post(route('admin.suppliers.store'), {
        preserveScroll: true,
        onSuccess: () => addForm.reset(),
    });
}

// Edit in a dialog
const editing = ref<Supplier | null>(null);
const editOpen = ref(false);
const editForm = useForm({ name: '', phone: '', email: '' });

function startEdit(supplier: Supplier) {
    editing.value = supplier;
    editForm.clearErrors();
    editForm.name = supplier.name;
    editForm.phone = supplier.phone ?? '';
    editForm.email = supplier.email ?? '';
    editOpen.value = true;
}

function update() {
    if (!editing.value) return;
    editForm.put(route('admin.suppliers.update', { supplier: editing.value.id }), {
        preserveScroll: true,
        onSuccess: () => (editOpen.value = false),
    });
}

const deliveriesLabel = (n: number) => (n === 1 ? '1 delivery' : `${n} deliveries`);
</script>

<template>
    <Head title="Suppliers" />

    <component :is="Layout" :breadcrumbs="breadcrumbs">
        <div class="max-w-3xl px-4 py-6">
            <Heading title="Suppliers" description="Who delivers stock to the shop, and how to reach them." />

            <form v-if="canEdit" class="mb-8 space-y-4 rounded-lg border border-border bg-card p-4" @submit.prevent="add">
                <h3 class="font-semibold">Add a supplier</h3>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label for="new-name">Name</Label>
                        <Input
                            id="new-name"
                            v-model="addForm.name"
                            required
                            maxlength="150"
                            :aria-invalid="!!addForm.errors.name"
                            aria-describedby="new-name-error"
                        />
                        <InputError id="new-name-error" :message="addForm.errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="new-phone">Phone (optional)</Label>
                        <Input
                            id="new-phone"
                            v-model="addForm.phone"
                            type="tel"
                            maxlength="30"
                            :aria-invalid="!!addForm.errors.phone"
                            aria-describedby="new-phone-error"
                        />
                        <InputError id="new-phone-error" :message="addForm.errors.phone" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="new-email">Email (optional)</Label>
                        <Input
                            id="new-email"
                            v-model="addForm.email"
                            type="email"
                            maxlength="255"
                            :aria-invalid="!!addForm.errors.email"
                            aria-describedby="new-email-error"
                        />
                        <InputError id="new-email-error" :message="addForm.errors.email" />
                    </div>
                </div>
                <Button type="submit" :disabled="addForm.processing">{{ addForm.processing ? 'Adding…' : 'Add supplier' }}</Button>
            </form>

            <EmptyState
                v-if="suppliers.length === 0"
                title="No suppliers yet"
                :description="canEdit ? 'Add a supplier above before recording deliveries.' : 'Ask an admin to add the shop’s suppliers.'"
            />

            <ul v-else class="divide-y divide-border rounded-lg border border-border">
                <li v-for="supplier in suppliers" :key="supplier.id" class="flex flex-wrap items-start justify-between gap-3 p-4">
                    <div class="min-w-0 text-sm">
                        <p class="text-base font-medium">{{ supplier.name }}</p>
                        <p v-if="supplier.phone">
                            <a :href="`tel:${supplier.phone}`" class="underline-offset-4 hover:underline">{{ supplier.phone }}</a>
                        </p>
                        <p v-if="supplier.email">
                            <a :href="`mailto:${supplier.email}`" class="break-all underline-offset-4 hover:underline">{{ supplier.email }}</a>
                        </p>
                        <p v-if="!supplier.phone && !supplier.email" class="text-muted-foreground">No contact details</p>
                        <p class="mt-1 text-muted-foreground">{{ deliveriesLabel(supplier.deliveries_count) }}</p>
                    </div>
                    <Button
                        v-if="canEdit"
                        type="button"
                        variant="outline"
                        size="sm"
                        :aria-label="`Edit ${supplier.name}`"
                        @click="startEdit(supplier)"
                    >
                        Edit
                    </Button>
                </li>
            </ul>
        </div>

        <Dialog v-if="canEdit" v-model:open="editOpen">
            <DialogContent class="max-w-[calc(100vw-2rem)] rounded-lg sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Edit supplier</DialogTitle>
                    <DialogDescription>Changes show on past and future deliveries.</DialogDescription>
                </DialogHeader>
                <form id="edit-supplier-form" class="space-y-4" @submit.prevent="update">
                    <div class="grid gap-1.5">
                        <Label for="edit-name">Name</Label>
                        <Input
                            id="edit-name"
                            v-model="editForm.name"
                            required
                            maxlength="150"
                            :aria-invalid="!!editForm.errors.name"
                            aria-describedby="edit-name-error"
                        />
                        <InputError id="edit-name-error" :message="editForm.errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="edit-phone">Phone (optional)</Label>
                        <Input
                            id="edit-phone"
                            v-model="editForm.phone"
                            type="tel"
                            maxlength="30"
                            :aria-invalid="!!editForm.errors.phone"
                            aria-describedby="edit-phone-error"
                        />
                        <InputError id="edit-phone-error" :message="editForm.errors.phone" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="edit-email">Email (optional)</Label>
                        <Input
                            id="edit-email"
                            v-model="editForm.email"
                            type="email"
                            maxlength="255"
                            :aria-invalid="!!editForm.errors.email"
                            aria-describedby="edit-email-error"
                        />
                        <InputError id="edit-email-error" :message="editForm.errors.email" />
                    </div>
                </form>
                <DialogFooter class="gap-2 sm:gap-0">
                    <Button type="button" variant="outline" :disabled="editForm.processing" @click="editOpen = false">Cancel</Button>
                    <Button type="submit" form="edit-supplier-form" :disabled="editForm.processing">
                        {{ editForm.processing ? 'Saving…' : 'Save changes' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </component>
</template>
