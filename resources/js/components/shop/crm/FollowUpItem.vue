<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/dates';
import { Link, router } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
import { ref } from 'vue';
import { dateOnlyIso, type FollowUpRow } from './crm';

/** One follow-up with its due date, state and a "Mark done" action. The server re-checks the status. */
const props = withDefaults(defineProps<{ followUp: FollowUpRow; showCustomer?: boolean }>(), { showCustomer: false });

const processing = ref(false);

function markDone() {
    router.post(
        route('staff.follow-ups.complete', { followUp: props.followUp.id }),
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 space-y-1">
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                <span :class="['font-semibold', followUp.overdue ? 'text-danger' : '', followUp.done ? 'text-muted-foreground line-through' : '']">
                    Due {{ formatDate(dateOnlyIso(followUp.due_on)) }}
                </span>
                <span v-if="followUp.overdue" class="rounded bg-danger-chip px-1.5 py-0.5 text-xs font-semibold text-danger-strong">Overdue</span>
                <span v-if="followUp.done" class="text-xs text-muted-foreground">Done {{ formatDateTime(followUp.completed_at) }}</span>
            </p>
            <p v-if="showCustomer && followUp.customer" class="text-sm">
                <Link
                    :href="route('staff.customers.show', { customer: followUp.customer.id })"
                    class="font-medium underline-offset-4 hover:underline"
                    >{{ followUp.customer.name ?? 'Customer' }}</Link
                >
            </p>
            <p :class="['whitespace-pre-line break-words text-sm', followUp.done ? 'text-muted-foreground line-through' : '']">
                {{ followUp.note }}
            </p>
            <p class="text-xs text-muted-foreground">
                <template v-if="followUp.assignee">For {{ followUp.assignee }} · </template>
                <template v-if="followUp.reservation"
                    ><span class="font-mono">{{ followUp.reservation }}</span> ·
                </template>
                Added by {{ followUp.author ?? 'a former staff member' }}
            </p>
        </div>
        <Button v-if="!followUp.done" type="button" variant="outline" size="sm" class="h-9 shrink-0" :disabled="processing" @click="markDone">
            <Check class="size-4" aria-hidden="true" />
            {{ processing ? 'Saving…' : 'Mark done' }}
        </Button>
    </div>
</template>
