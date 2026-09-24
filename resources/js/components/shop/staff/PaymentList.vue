<script setup lang="ts">
import { formatDateTime } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { PaymentRow } from '@/types';

defineProps<{ payments: PaymentRow[] }>();

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
            return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100';
        case 'needs_review':
        case 'refund_failed':
            return 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100';
        default:
            return 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100';
    }
}
</script>

<template>
    <p v-if="payments.length === 0" class="text-sm text-muted-foreground">No payments recorded yet.</p>
    <ul v-else class="space-y-2">
        <li
            v-for="p in payments"
            :key="p.id"
            :class="[
                'rounded-md border p-3 text-sm',
                p.status === 'needs_review' || p.status === 'refund_failed'
                    ? 'border-red-400 bg-red-50 dark:border-red-800 dark:bg-red-950/40'
                    : 'border-border',
            ]"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium">
                    {{ typeLabel[p.type] }}
                    <span class="font-normal text-muted-foreground">by {{ methodLabel[p.method] }}</span>
                </span>
                <span class="font-semibold tabular-nums">{{ p.type === 'refund' ? '−' : '' }}{{ formatPeso(p.amount) }}</span>
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-muted-foreground">
                <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', statusTone(p.status)]">{{ statusLabel[p.status] }}</span>
                <span>{{ formatDateTime(p.created_at) }}</span>
                <span v-if="p.provider_ref" class="break-all font-mono text-xs">Ref {{ p.provider_ref }}</span>
            </div>
            <p v-if="p.note" class="mt-2 whitespace-pre-line text-foreground">{{ p.note }}</p>
        </li>
    </ul>
</template>
