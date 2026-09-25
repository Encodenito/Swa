<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/shop/Pagination.vue';
import AddStaffDialog from '@/components/shop/admin/AddStaffDialog.vue';
import Chip from '@/components/shop/admin/Chip.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import NativeSelect from '@/components/shop/staff/NativeSelect.vue';
import TextArea from '@/components/shop/staff/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatDate } from '@/lib/dates';
import type { Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { UserPlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Account {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: string;
    verified: boolean;
    active: boolean;
    is_me: boolean;
    created_at: string | null;
}

const props = defineProps<{
    users: Paginated<Account>;
    filters: { role: string | null; q: string };
    roles: { value: string; label: string }[];
}>();

const breadcrumbs = [
    { title: 'Admin dashboard', href: route('admin.home') },
    { title: 'Accounts', href: route('admin.users.index') },
];

const roleLabels = computed(() => Object.fromEntries(props.roles.map((r) => [r.value, r.label])));
const roleLabel = (role: string) => roleLabels.value[role] ?? role;
const roleTone = (role: string): 'accent' | 'info' | 'neutral' => (role === 'admin' ? 'accent' : role === 'staff' ? 'info' : 'neutral');

// Search and filter (GET)
const q = ref(props.filters.q ?? '');
const role = ref<string>(props.filters.role ?? '');
const searching = ref(false);
const hasFilters = computed(() => !!props.filters.q || !!props.filters.role);

function search() {
    const params: Record<string, string> = {};
    if (q.value.trim() !== '') params.q = q.value.trim();
    if (role.value !== '') params.role = role.value;

    router.get(route('admin.users.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (searching.value = true),
        onFinish: () => (searching.value = false),
    });
}

function clear() {
    q.value = '';
    role.value = '';
    search();
}

// Add staff
const addOpen = ref(false);

// Change role
const roleTarget = ref<Account | null>(null);
const roleOpen = ref(false);
const roleForm = useForm({ role: '', reason: '' });

function startRoleChange(user: Account) {
    roleTarget.value = user;
    roleForm.clearErrors();
    roleForm.role = user.role;
    roleForm.reason = '';
    roleOpen.value = true;
}

function submitRole() {
    if (!roleTarget.value) return;
    roleForm.put(route('admin.users.role', { user: roleTarget.value.id }), {
        preserveScroll: true,
        onSuccess: () => (roleOpen.value = false),
    });
}

// Deactivate / reactivate
const activeTarget = ref<Account | null>(null);
const activeOpen = ref(false);
const activeForm = useForm({ reason: '' });
const deactivating = computed(() => activeTarget.value?.active ?? true);

function startActiveChange(user: Account) {
    activeTarget.value = user;
    activeForm.clearErrors();
    activeForm.reason = '';
    activeOpen.value = true;
}

function submitActive() {
    if (!activeTarget.value) return;
    const name = deactivating.value ? 'admin.users.deactivate' : 'admin.users.reactivate';
    activeForm.post(route(name, { user: activeTarget.value.id }), {
        preserveScroll: true,
        onSuccess: () => (activeOpen.value = false),
    });
}
</script>

<template>
    <Head title="Accounts" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <Heading title="Accounts" description="Customers, staff and admins. Every role or access change is recorded in the audit log." />
                <Button type="button" class="w-full sm:w-auto" @click="addOpen = true">
                    <UserPlus class="size-4" aria-hidden="true" />
                    Add staff account
                </Button>
            </div>

            <form role="search" class="mb-6 grid gap-3 sm:grid-cols-[1fr_12rem_auto] sm:items-end" @submit.prevent="search">
                <div class="grid gap-1.5">
                    <Label for="user-search">Search</Label>
                    <Input id="user-search" v-model="q" type="search" maxlength="100" placeholder="Name or email" autocomplete="off" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="user-role">Role</Label>
                    <NativeSelect id="user-role" v-model="role" @change="search">
                        <option value="">All roles</option>
                        <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                    </NativeSelect>
                </div>
                <div class="flex gap-2">
                    <Button type="submit" class="h-10 flex-1 sm:flex-none" :disabled="searching">{{ searching ? 'Searching…' : 'Search' }}</Button>
                    <Button v-if="hasFilters" type="button" variant="outline" class="h-10" :disabled="searching" @click="clear">Clear</Button>
                </div>
            </form>

            <p class="mb-4 rounded-md border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                Deactivated accounts are signed out and cannot log in. Their reservations, payments and history are kept.
            </p>

            <div :class="['transition-opacity', searching ? 'opacity-60' : '']" :aria-busy="searching">
                <EmptyState
                    v-if="users.data.length === 0"
                    :title="hasFilters ? 'No accounts match your search' : 'No accounts yet'"
                    :description="hasFilters ? 'Check the spelling or try a different role.' : 'Accounts appear here as people register.'"
                />

                <template v-else>
                    <p class="mb-2 text-sm text-muted-foreground">Showing {{ users.from }}–{{ users.to }} of {{ users.total }}</p>

                    <!-- Desktop table -->
                    <div class="hidden overflow-hidden rounded-lg border border-border md:block">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Name</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Contact</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Access</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Joined</th>
                                    <th scope="col" class="px-4 py-2.5 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="u in users.data" :key="u.id" :class="u.active ? '' : 'bg-muted/30'">
                                    <td class="px-4 py-3 font-medium">{{ u.name }}</td>
                                    <td class="px-4 py-3">
                                        <span class="block break-all">{{ u.email }}</span>
                                        <span v-if="u.phone" class="block text-muted-foreground">{{ u.phone }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="flex flex-wrap gap-1.5">
                                            <Chip :tone="roleTone(u.role)">{{ roleLabel(u.role) }}</Chip>
                                            <Chip v-if="!u.verified" tone="warning">Unverified</Chip>
                                            <Chip v-if="!u.active" tone="danger">Deactivated</Chip>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ formatDate(u.created_at) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <span v-if="u.is_me" class="text-muted-foreground">This is you</span>
                                        <span v-else class="inline-flex gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                :aria-label="`Change role of ${u.name}`"
                                                @click="startRoleChange(u)"
                                            >
                                                Change role
                                            </Button>
                                            <Button
                                                type="button"
                                                :variant="u.active ? 'outline' : 'secondary'"
                                                size="sm"
                                                :class="u.active ? 'text-destructive hover:text-destructive' : ''"
                                                :aria-label="`${u.active ? 'Deactivate' : 'Reactivate'} ${u.name}`"
                                                @click="startActiveChange(u)"
                                            >
                                                {{ u.active ? 'Deactivate' : 'Reactivate' }}
                                            </Button>
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Phone cards -->
                    <ul class="divide-y divide-border rounded-lg border border-border md:hidden">
                        <li v-for="u in users.data" :key="u.id" :class="['space-y-2 p-4', u.active ? '' : 'bg-muted/30']">
                            <p class="font-medium">{{ u.name }}</p>
                            <p class="text-sm">
                                <span class="block break-all">{{ u.email }}</span>
                                <span v-if="u.phone" class="block text-muted-foreground">{{ u.phone }}</span>
                            </p>
                            <p class="flex flex-wrap gap-1.5">
                                <Chip :tone="roleTone(u.role)">{{ roleLabel(u.role) }}</Chip>
                                <Chip v-if="!u.verified" tone="warning">Unverified</Chip>
                                <Chip v-if="!u.active" tone="danger">Deactivated</Chip>
                            </p>
                            <p class="text-sm text-muted-foreground">Joined {{ formatDate(u.created_at) }}</p>
                            <p v-if="u.is_me" class="text-sm text-muted-foreground">This is you</p>
                            <div v-else class="grid grid-cols-2 gap-2 pt-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="h-10"
                                    :aria-label="`Change role of ${u.name}`"
                                    @click="startRoleChange(u)"
                                >
                                    Change role
                                </Button>
                                <Button
                                    type="button"
                                    :variant="u.active ? 'outline' : 'secondary'"
                                    :class="['h-10', u.active ? 'text-destructive hover:text-destructive' : '']"
                                    :aria-label="`${u.active ? 'Deactivate' : 'Reactivate'} ${u.name}`"
                                    @click="startActiveChange(u)"
                                >
                                    {{ u.active ? 'Deactivate' : 'Reactivate' }}
                                </Button>
                            </div>
                        </li>
                    </ul>

                    <Pagination :links="users.links" />
                </template>
            </div>
        </div>

        <AddStaffDialog v-model:open="addOpen" :roles="roles" />

        <!-- Change role -->
        <Dialog v-model:open="roleOpen">
            <DialogContent class="max-w-[calc(100vw-2rem)] rounded-lg sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Change role</DialogTitle>
                    <DialogDescription v-if="roleTarget">
                        {{ roleTarget.name }} is currently {{ roleLabel(roleTarget.role) }}. The change takes effect on their next page load.
                    </DialogDescription>
                </DialogHeader>
                <form id="change-role-form" class="space-y-4" @submit.prevent="submitRole">
                    <div class="grid gap-1.5">
                        <Label for="change-role">New role</Label>
                        <NativeSelect
                            id="change-role"
                            v-model="roleForm.role"
                            required
                            :aria-invalid="!!roleForm.errors.role"
                            aria-describedby="change-role-error"
                        >
                            <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </NativeSelect>
                        <InputError id="change-role-error" :message="roleForm.errors.role" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="change-role-reason">Reason</Label>
                        <TextArea
                            id="change-role-reason"
                            v-model="roleForm.reason"
                            required
                            minlength="3"
                            maxlength="255"
                            placeholder="e.g. Promoted to shift supervisor"
                            :aria-invalid="!!roleForm.errors.reason"
                            aria-describedby="change-role-reason-hint change-role-reason-error"
                        />
                        <p id="change-role-reason-hint" class="text-sm text-muted-foreground">Saved in the audit log.</p>
                        <InputError id="change-role-reason-error" :message="roleForm.errors.reason" />
                    </div>
                </form>
                <DialogFooter class="gap-2 sm:gap-0">
                    <Button type="button" variant="outline" :disabled="roleForm.processing" @click="roleOpen = false">Cancel</Button>
                    <Button type="submit" form="change-role-form" :disabled="roleForm.processing || !roleTarget || roleForm.role === roleTarget.role">
                        {{ roleForm.processing ? 'Saving…' : 'Change role' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Deactivate / reactivate -->
        <Dialog v-model:open="activeOpen">
            <DialogContent class="max-w-[calc(100vw-2rem)] rounded-lg sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ deactivating ? 'Deactivate account' : 'Reactivate account' }}</DialogTitle>
                    <DialogDescription v-if="activeTarget">
                        <template v-if="deactivating">
                            {{ activeTarget.name }} will be signed out and cannot log in. Their reservations and history are kept, and you can
                            reactivate the account later.
                        </template>
                        <template v-else>{{ activeTarget.name }} will be able to log in again with their existing password.</template>
                    </DialogDescription>
                </DialogHeader>
                <form id="active-form" class="space-y-4" @submit.prevent="submitActive">
                    <div class="grid gap-1.5">
                        <Label for="active-reason">Reason</Label>
                        <TextArea
                            id="active-reason"
                            v-model="activeForm.reason"
                            required
                            minlength="3"
                            maxlength="255"
                            :aria-invalid="!!activeForm.errors.reason"
                            aria-describedby="active-reason-hint active-reason-error"
                        />
                        <p id="active-reason-hint" class="text-sm text-muted-foreground">Saved in the audit log.</p>
                        <InputError id="active-reason-error" :message="activeForm.errors.reason" />
                    </div>
                </form>
                <DialogFooter class="gap-2 sm:gap-0">
                    <Button type="button" variant="outline" :disabled="activeForm.processing" @click="activeOpen = false">Cancel</Button>
                    <Button type="submit" form="active-form" :variant="deactivating ? 'destructive' : 'default'" :disabled="activeForm.processing">
                        {{ activeForm.processing ? 'Working…' : deactivating ? 'Deactivate' : 'Reactivate' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
