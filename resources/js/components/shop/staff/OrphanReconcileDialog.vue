<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextArea from '@/components/shop/staff/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatPeso } from '@/lib/money';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Admin settles an unknown-outcome refund of a payment that matched no reservation, after checking the PayMongo dashboard.
 * Nothing is sent to PayMongo from here: the admin records what the dashboard shows.
 */
const props = defineProps<{ orphanId: number; amount: string; providerRef: string | null }>();
const open = defineModel<boolean>('open', { required: true });

const form = useForm({ outcome: '' as '' | 'found' | 'not_found', paymongo_refund_id: '', reason: '' });

const otherError = computed(() => {
    const known = ['outcome', 'paymongo_refund_id', 'reason'];

    return Object.entries(form.errors as Record<string, string | undefined>).find(([key]) => !known.includes(key))?.[1];
});

function submit() {
    form.post(route('admin.orphans.reconcile', { orphan: props.orphanId }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}

function onOpenChange(value: boolean) {
    if (form.processing) return;
    open.value = value;
    if (!value) form.clearErrors();
}

const prefix = computed(() => `orphan-reconcile-${props.orphanId}`);
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="max-h-[calc(100dvh-2rem)] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Reconcile refund of unknown payment</DialogTitle>
                <DialogDescription>
                    In the PayMongo dashboard, open payment <span class="break-all font-mono">{{ providerRef ?? '—' }}</span> and look for a refund of
                    {{ formatPeso(amount) }} with metadata <span class="font-mono">refund_id = orphan-{{ orphanId }}</span
                    >. Record what you find. Nothing is sent to PayMongo from here.
                </DialogDescription>
            </DialogHeader>

            <form :id="prefix" class="space-y-4" @submit.prevent="submit">
                <fieldset class="space-y-2" :disabled="form.processing" :aria-describedby="`${prefix}-outcome-error`">
                    <legend class="text-sm font-medium">What does PayMongo show?</legend>
                    <label class="flex items-start gap-3 rounded-md border border-border p-3">
                        <input v-model="form.outcome" type="radio" value="found" class="mt-1" />
                        <span>The refund is there. Record it as refunded.</span>
                    </label>
                    <label class="flex items-start gap-3 rounded-md border border-border p-3">
                        <input v-model="form.outcome" type="radio" value="not_found" class="mt-1" />
                        <span>There is no such refund. Mark it failed so it can be retried.</span>
                    </label>
                    <InputError :id="`${prefix}-outcome-error`" :message="form.errors.outcome" />
                </fieldset>

                <div v-if="form.outcome === 'found'" class="space-y-1">
                    <Label :for="`${prefix}-ref`">PayMongo refund id</Label>
                    <Input
                        :id="`${prefix}-ref`"
                        v-model="form.paymongo_refund_id"
                        placeholder="ref_…"
                        autocomplete="off"
                        class="font-mono"
                        :disabled="form.processing"
                        :aria-invalid="!!form.errors.paymongo_refund_id || undefined"
                        :aria-describedby="`${prefix}-ref-error`"
                    />
                    <InputError :id="`${prefix}-ref-error`" :message="form.errors.paymongo_refund_id" />
                </div>

                <div class="space-y-1">
                    <Label :for="`${prefix}-reason`">Reason</Label>
                    <TextArea
                        :id="`${prefix}-reason`"
                        v-model="form.reason"
                        rows="2"
                        maxlength="255"
                        placeholder="What you checked in the dashboard"
                        :disabled="form.processing"
                        :aria-invalid="!!form.errors.reason || undefined"
                        :aria-describedby="`${prefix}-reason-error`"
                    />
                    <InputError :id="`${prefix}-reason-error`" :message="form.errors.reason" />
                </div>
                <InputError :message="otherError" />
            </form>

            <DialogFooter class="gap-2">
                <Button type="button" variant="outline" :disabled="form.processing" @click="onOpenChange(false)">Cancel</Button>
                <Button type="submit" :form="prefix" :disabled="form.processing || !form.outcome">
                    {{ form.processing ? 'Working…' : 'Record outcome' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
