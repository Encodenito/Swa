<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/shop/Pagination.vue';
import StatusBadge from '@/components/shop/StatusBadge.vue';
import ConfirmDialog from '@/components/shop/staff/ConfirmDialog.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import ReasonDialog from '@/components/shop/staff/ReasonDialog.vue';
import { Button } from '@/components/ui/button';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { Paginated, PaymentRow, ReservationStatus } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, CheckCheck, Clock, RotateCcw, Undo2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Kind = 'needs_review' | 'refund_failed' | 'stuck';

type ReviewRow = PaymentRow & {
    kind: Kind;
    stuck_since: string | null;
    reservation: {
        reference_code: string;
        status: ReservationStatus;
        status_label: string;
        customer: { name: string; email: string; phone: string | null } | null;
    };
    original: { id: number; provider_ref: string | null } | null;
};

const props = defineProps<{
    payments: Paginated<ReviewRow>;
    canMarkReviewed: boolean;
}>();

const breadcrumbs = [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Payments to review', href: route('staff.payments.review') },
];

const kinds: { kind: Kind; title: string; explain: string }[] = [
    { kind: 'needs_review', title: 'Paid but not applied', explain: 'Paid at PayMongo but not applied to the reservation.' },
    { kind: 'refund_failed', title: 'Refunds that failed', explain: 'PayMongo refused this refund.' },
    {
        kind: 'stuck',
        title: 'Refunds with no answer',
        explain: 'Sent more than 15 minutes ago with no answer. Check the PayMongo dashboard before doing anything.',
    },
];

const groups = computed(() =>
    kinds.map((k) => ({ ...k, rows: props.payments.data.filter((p) => p.kind === k.kind) })).filter((g) => g.rows.length > 0),
);

const typeLabel: Record<PaymentRow['type'], string> = {
    downpayment: 'Downpayment',
    balance: 'Balance',
    full: 'Full payment',
    refund: 'Refund',
};

const tone: Record<Kind, string> = {
    needs_review: 'border-red-400 dark:border-red-800',
    refund_failed: 'border-red-400 dark:border-red-800',
    stuck: 'border-amber-400 dark:border-amber-700',
};

// One reason dialog, pointed at whichever row and action was chosen.
const reasonOpen = ref(false);
const reasonAction = ref<{ url: string; title: string; description: string; confirmLabel: string; destructive: boolean; key: string } | null>(null);

function refundInFull(p: ReviewRow) {
    reasonAction.value = {
        key: `refund-${p.id}`,
        url: route('staff.payments.refund', { payment: p.id }),
        title: `Refund ${formatPeso(p.amount)} in full?`,
        description: `The full ${formatPeso(p.amount)} goes back to the customer's original payment method through PayMongo. The reservation ${p.reservation.reference_code} is not changed.`,
        confirmLabel: 'Refund in full',
        destructive: true,
    };
    reasonOpen.value = true;
}

function markReviewed(p: ReviewRow) {
    reasonAction.value = {
        key: `reviewed-${p.id}`,
        url: route('admin.payments.reviewed', { payment: p.id }),
        title: 'Keep this payment?',
        description: `No refund is sent. The ${formatPeso(p.amount)} stays with the shop and the payment is marked reviewed. Note how it was settled with the customer.`,
        confirmLabel: 'Keep payment, mark reviewed',
        destructive: false,
    };
    reasonOpen.value = true;
}

// Retry a failed refund. The server claims the row atomically (refund_failed → refund_pending).
const retryTarget = ref<ReviewRow | null>(null);
const retryOpen = ref(false);
const retryForm = useForm({});
const retryError = computed(() => Object.values(retryForm.errors as Record<string, string>)[0]);

function askRetry(p: ReviewRow) {
    retryForm.clearErrors();
    retryTarget.value = p;
    retryOpen.value = true;
}

function retry() {
    if (!retryTarget.value) return;
    retryForm.post(route('staff.payments.retry', { payment: retryTarget.value.id }), {
        preserveScroll: true,
        onSuccess: () => (retryOpen.value = false),
    });
}
</script>

<template>
    <Head title="Payments to review" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading title="Payments to review" description="Money that PayMongo moved but we could not settle automatically. Oldest first." />

            <EmptyState
                v-if="payments.data.length === 0"
                title="Nothing needs review."
                description="Payments that could not be applied and refunds that failed or got stuck show up here."
            />

            <div v-else class="space-y-8">
                <section v-for="group in groups" :key="group.kind" :aria-labelledby="`${group.kind}-heading`">
                    <h2 :id="`${group.kind}-heading`" class="flex items-center gap-2 text-lg font-semibold">
                        <Clock v-if="group.kind === 'stuck'" class="size-5 text-amber-600" aria-hidden="true" />
                        <AlertTriangle v-else class="size-5 text-red-600" aria-hidden="true" />
                        {{ group.title }}
                        <span class="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold tabular-nums">{{ group.rows.length }}</span>
                    </h2>
                    <p class="mb-3 mt-1 text-sm text-muted-foreground">{{ group.explain }}</p>

                    <ul class="space-y-3">
                        <li v-for="p in group.rows" :key="p.id" :class="['rounded-lg border border-l-4 bg-card p-4 text-sm', tone[p.kind]]">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold">
                                        {{ typeLabel[p.type] }}
                                        <span class="font-normal text-muted-foreground">#{{ p.id }}</span>
                                    </p>
                                    <p class="text-muted-foreground">Created {{ formatDateTime(p.created_at) }}</p>
                                </div>
                                <p class="text-lg font-bold tabular-nums">{{ p.type === 'refund' ? '−' : '' }}{{ formatPeso(p.amount) }}</p>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <Link
                                    :href="route('staff.reservations.show', { reservation: p.reservation.reference_code })"
                                    class="font-mono font-semibold underline underline-offset-4 hover:text-primary"
                                    >{{ p.reservation.reference_code }}</Link
                                >
                                <StatusBadge :status="p.reservation.status" :label="p.reservation.status_label" />
                            </div>
                            <p v-if="p.reservation.customer" class="mt-1">
                                {{ p.reservation.customer.name }}
                                <span class="break-all text-muted-foreground"
                                    >· {{ p.reservation.customer.phone ?? p.reservation.customer.email }}</span
                                >
                            </p>
                            <p v-else class="mt-1 text-muted-foreground">Walk-in customer</p>

                            <dl class="mt-3 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                                <div v-if="p.provider_ref">
                                    <dt class="text-muted-foreground">PayMongo reference</dt>
                                    <dd class="break-all font-mono text-xs">{{ p.provider_ref }}</dd>
                                </div>
                                <div v-if="p.original">
                                    <dt class="text-muted-foreground">Refund of payment #{{ p.original.id }}</dt>
                                    <dd class="break-all font-mono text-xs">{{ p.original.provider_ref ?? '—' }}</dd>
                                </div>
                                <div v-if="p.stuck_since">
                                    <dt class="text-muted-foreground">Waiting since</dt>
                                    <dd>{{ formatDateTime(p.stuck_since) }}</dd>
                                </div>
                            </dl>

                            <p v-if="p.note" class="mt-3 whitespace-pre-line rounded-md bg-muted px-3 py-2">
                                <span class="font-medium">{{ p.kind === 'refund_failed' ? 'Error: ' : 'Note: ' }}</span
                                >{{ p.note }}
                            </p>

                            <div v-if="p.kind === 'needs_review'" class="mt-4 flex flex-col gap-2 sm:flex-row">
                                <Button type="button" variant="destructive" class="h-10" @click="refundInFull(p)">
                                    <Undo2 class="size-4" aria-hidden="true" />
                                    Refund in full
                                </Button>
                                <Button v-if="canMarkReviewed" type="button" variant="outline" class="h-10" @click="markReviewed(p)">
                                    <CheckCheck class="size-4" aria-hidden="true" />
                                    Keep payment, mark reviewed
                                </Button>
                            </div>
                            <div v-else-if="p.kind === 'refund_failed'" class="mt-4">
                                <Button type="button" class="h-10" @click="askRetry(p)">
                                    <RotateCcw class="size-4" aria-hidden="true" />
                                    Retry refund
                                </Button>
                            </div>
                            <p v-else class="mt-4 text-muted-foreground">
                                No action here. Look up the PayMongo reference in the PayMongo dashboard: if the refund went through, this row updates
                                once PayMongo notifies us. If it did not, tell an admin.
                            </p>
                        </li>
                    </ul>
                </section>

                <Pagination :links="payments.links" />
            </div>
        </div>

        <ReasonDialog
            v-if="reasonAction"
            :key="reasonAction.key"
            v-model:open="reasonOpen"
            :id-prefix="reasonAction.key"
            :url="reasonAction.url"
            :title="reasonAction.title"
            :description="reasonAction.description"
            :confirm-label="reasonAction.confirmLabel"
            :destructive="reasonAction.destructive"
        />

        <ConfirmDialog
            v-model:open="retryOpen"
            :title="retryTarget ? `Send the ${formatPeso(retryTarget.amount)} refund again?` : 'Send the refund again?'"
            description="The refund is sent to PayMongo once more. Check the error first: if the cause is not fixed, it will fail again."
            confirm-label="Retry refund"
            :processing="retryForm.processing"
            @confirm="retry"
        >
            <InputError :message="retryError" />
        </ConfirmDialog>
    </StaffLayout>
</template>
