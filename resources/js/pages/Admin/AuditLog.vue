<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/shop/Pagination.vue';
import AuditChanges from '@/components/shop/admin/AuditChanges.vue';
import LoadingRegion from '@/components/shop/skeleton/LoadingRegion.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import NativeSelect from '@/components/shop/staff/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatDateTime } from '@/lib/dates';
import type { Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Values = Record<string, unknown> | unknown[] | null;

interface LogEntry {
    id: number;
    action: string;
    subject_type: string | null;
    subject_id: number | null;
    subject_label: string | null;
    before: Values;
    after: Values;
    user: { id: number; name: string } | null;
    ip: string | null;
    created_at: string | null;
}

interface Filters {
    action: string | null;
    user_id: number | string | null;
    from: string | null;
    to: string | null;
}

const props = defineProps<{ logs: Paginated<LogEntry>; filters: Filters; actions: string[] }>();

const breadcrumbs = [
    { title: 'Admin dashboard', href: route('admin.home') },
    { title: 'Audit log', href: route('admin.audit-log') },
];

const ACTION_LABELS: Record<string, string> = {
    'reservation.cancelled': 'Reservation cancelled',
    'reservation.forfeited': 'Reservation forfeited',
    'reservation.deadline_extended': 'Pickup deadline extended',
    'refund.created': 'Refund created',
    'refund.sent': 'Refund sent to PayMongo',
    'refund.failed': 'Refund failed',
    'refund.retried': 'Refund retried',
    'walk_in.voided': 'Walk-in sale voided',
    'item.price_changed': 'Item price changed',
    'batch.clearance_changed': 'Clearance price changed',
    'stock.adjusted': 'Stock adjusted',
    'settings.changed': 'Setting changed',
    'payment.reviewed': 'Payment reviewed',
    'user.created': 'Account created',
    'user.role_changed': 'Role changed',
    'user.deactivated': 'Account deactivated',
    'user.reactivated': 'Account reactivated',
};

const actionLabel = (action: string) => ACTION_LABELS[action] ?? action;

function subjectText(log: LogEntry): string {
    if (!log.subject_type) return '—';
    const type = log.subject_type.replace(/[_.]+/g, ' ');

    return log.subject_id !== null ? `${type} #${log.subject_id}` : type;
}

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

// Filters (GET)
const action = ref<string>(props.filters.action ?? '');
const from = ref<string>(props.filters.from ?? '');
const to = ref<string>(props.filters.to ?? '');
const userId = ref<string>(props.filters.user_id ? String(props.filters.user_id) : '');
const loading = ref(false);

const hasFilters = computed(() => !!(props.filters.action || props.filters.user_id || props.filters.from || props.filters.to));

// Name for the "only entries by" chip, taken from the entries on this page.
const filteredUserName = computed(() => {
    if (!props.filters.user_id) return null;
    const match = props.logs.data.find((l) => l.user && String(l.user.id) === String(props.filters.user_id));

    return match?.user?.name ?? `user #${props.filters.user_id}`;
});

function apply() {
    const params: Record<string, string> = {};
    if (action.value) params.action = action.value;
    if (userId.value) params.user_id = userId.value;
    if (from.value) params.from = from.value;
    if (to.value) params.to = to.value;

    router.get(route('admin.audit-log'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

function filterByUser(id: number) {
    userId.value = String(id);
    apply();
}

function clearUser() {
    userId.value = '';
    apply();
}

function clear() {
    action.value = '';
    from.value = '';
    to.value = '';
    userId.value = '';
    apply();
}
</script>

<template>
    <Head title="Audit log" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading
                title="Audit log"
                description="Refunds, voids, cancellations, price and stock changes, and account changes. Entries cannot be edited."
            />

            <form
                class="mb-4 grid gap-3 rounded-lg border border-border bg-card p-4 sm:grid-cols-2 lg:grid-cols-[1fr_11rem_11rem_auto] lg:items-end"
                @submit.prevent="apply"
            >
                <div class="grid gap-1.5 sm:col-span-2 lg:col-span-1">
                    <Label for="audit-action">Action</Label>
                    <NativeSelect
                        id="audit-action"
                        v-model="action"
                        :aria-invalid="!!errors.action"
                        aria-describedby="audit-action-error"
                        @change="apply"
                    >
                        <option value="">All actions</option>
                        <option v-for="a in actions" :key="a" :value="a">{{ actionLabel(a) }}</option>
                    </NativeSelect>
                    <InputError id="audit-action-error" :message="errors.action" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="audit-from">From</Label>
                    <Input
                        id="audit-from"
                        v-model="from"
                        type="date"
                        :max="to || undefined"
                        :aria-invalid="!!errors.from"
                        aria-describedby="audit-from-error"
                    />
                    <InputError id="audit-from-error" :message="errors.from" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="audit-to">To</Label>
                    <Input
                        id="audit-to"
                        v-model="to"
                        type="date"
                        :min="from || undefined"
                        :aria-invalid="!!errors.to"
                        aria-describedby="audit-to-error"
                    />
                    <InputError id="audit-to-error" :message="errors.to" />
                </div>
                <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
                    <Button type="submit" class="h-10 flex-1 lg:flex-none" :disabled="loading">{{ loading ? 'Loading…' : 'Filter' }}</Button>
                    <Button v-if="hasFilters" type="button" variant="outline" class="h-10" :disabled="loading" @click="clear">Clear</Button>
                </div>
            </form>

            <p v-if="filters.user_id" class="mb-4 flex flex-wrap items-center gap-2 text-sm">
                <span
                    >Only entries by <span class="font-medium">{{ filteredUserName }}</span></span
                >
                <Button type="button" variant="ghost" size="sm" class="h-8" :disabled="loading" @click="clearUser">
                    <X class="size-4" aria-hidden="true" />
                    Show everyone
                </Button>
            </p>

            <LoadingRegion :loading="loading">
                <EmptyState
                    v-if="logs.data.length === 0"
                    :title="hasFilters ? 'No entries match these filters' : 'Nothing recorded yet'"
                    :description="
                        hasFilters
                            ? 'Try a different action or a wider date range.'
                            : 'Refunds, voids, price changes and account changes will appear here.'
                    "
                />

                <template v-else>
                    <p class="mb-2 text-sm text-muted-foreground">Showing {{ logs.from }}–{{ logs.to }} of {{ logs.total }}</p>

                    <ol class="divide-y divide-border rounded-lg border border-border">
                        <li v-for="log in logs.data" :key="log.id" class="grid gap-3 p-4 md:grid-cols-[14rem_1fr]">
                            <div class="space-y-1 text-sm">
                                <p class="font-semibold">{{ actionLabel(log.action) }}</p>
                                <p class="text-muted-foreground">
                                    <time :datetime="log.created_at ?? undefined">{{ formatDateTime(log.created_at) }}</time>
                                </p>
                                <p>
                                    <span class="text-muted-foreground">By </span>
                                    <button
                                        v-if="log.user"
                                        type="button"
                                        class="rounded-sm font-medium underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        :title="`Show only entries by ${log.user.name}`"
                                        @click="filterByUser(log.user.id)"
                                    >
                                        {{ log.user.name }}
                                    </button>
                                    <span v-else class="font-medium">System</span>
                                </p>
                                <p v-if="log.ip" class="font-mono text-xs text-muted-foreground">{{ log.ip }}</p>
                            </div>
                            <div class="min-w-0 space-y-2">
                                <p class="text-sm">
                                    <span class="text-muted-foreground">Subject: </span>
                                    <Link
                                        v-if="log.subject_label"
                                        :href="route('staff.reservations.show', { reservation: log.subject_label })"
                                        class="font-mono font-semibold underline underline-offset-4"
                                        >{{ log.subject_label }}</Link
                                    >
                                    <span v-else class="font-medium">{{ subjectText(log) }}</span>
                                </p>
                                <AuditChanges :before="log.before" :after="log.after" />
                            </div>
                        </li>
                    </ol>

                    <Pagination :links="logs.links" />
                </template>
            </LoadingRegion>
        </div>
    </AdminLayout>
</template>
