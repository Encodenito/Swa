<script setup lang="ts">
import { formatDateTime } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { PaymentRow } from '@/types';
import { CornerDownRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ payments: PaymentRow[] }>();

const typeLabel: Record<PaymentRow['type'], string> = {
    downpayment: 'Downpayment',
    balance: 'Balance',
    full: 'Full payment',
    refund: 'Refund',
};

const methodLabel: Record<PaymentRow['method'], string> = { paymongo: 'PayMongo', cash: 'Cash' };

const statusLabel: Record<PaymentRow['status'], string> = {
    paid: 'Paid',
    needs_review: 'Needs review',
    refund_pending: 'Refund pending',
    refunded: 'Refunded',
    refund_failed: 'Refund failed',
};

function statusTone(status: PaymentRow['status']): string {
    switch (status) {
        case 'paid':
        case 'refunded':
            return 'bg-success-chip text-success-strong';
        case 'needs_review':
        case 'refund_failed':
            return 'bg-danger-chip text-danger-strong';
        default:
            return 'bg-warning-chip text-warning-strong';
    }
}

/** Each refund is listed right under the payment it refunds; orphans keep their own place. */
const rows = computed(() => {
    const ids = new Set(props.payments.map((p) => p.id));
    const childrenOf = new Map<number, PaymentRow[]>();
    for (const p of props.payments) {
        if (p.parent_id !== null && ids.has(p.parent_id)) {
            childrenOf.set(p.parent_id, [...(childrenOf.get(p.parent_id) ?? []), p]);
        }
    }

    const out: { payment: PaymentRow; nested: boolean }[] = [];
    for (const p of props.payments) {
        if (p.parent_id !== null && ids.has(p.parent_id)) continue;
        out.push({ payment: p, nested: false });
        for (const child of childrenOf.get(p.id) ?? []) out.push({ payment: child, nested: true });
    }

    return out;
});
</script>

<template>
    <p v-if="payments.length === 0" class="text-sm text-muted-foreground">No payments recorded yet.</p>
    <ul v-else class="space-y-2">
        <li
            v-for="{ payment: p, nested } in rows"
            :key="p.id"
            :class="[
                'rounded-md border p-3 text-sm',
                nested ? 'ml-6' : '',
                p.status === 'needs_review' || p.status === 'refund_failed' ? 'border-danger-border bg-danger-surface' : 'border-border',
            ]"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="flex items-center gap-1.5 font-medium">
                    <CornerDownRight v-if="nested" class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    {{ typeLabel[p.type] }}
                    <span class="font-normal text-muted-foreground">by {{ methodLabel[p.method] }}</span>
                </span>
                <span class="font-semibold tabular-nums">{{ p.type === 'refund' ? '−' : '' }}{{ formatPeso(p.amount) }}</span>
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-muted-foreground">
                <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', statusTone(p.status)]">{{ statusLabel[p.status] }}</span>
                <span>{{ formatDateTime(p.created_at) }}</span>
                <span class="text-xs">Payment #{{ p.id }}</span>
                <span v-if="p.parent_id !== null" class="text-xs">Refunds payment #{{ p.parent_id }}</span>
                <span v-if="p.provider_ref" class="break-all font-mono text-xs">Ref {{ p.provider_ref }}</span>
            </div>
            <p v-if="p.note" class="mt-2 whitespace-pre-line text-foreground">{{ p.note }}</p>
            <p v-if="p.reviewed_at" class="mt-1 text-xs text-muted-foreground">Reviewed {{ formatDateTime(p.reviewed_at) }}</p>
        </li>
    </ul>
</template>
