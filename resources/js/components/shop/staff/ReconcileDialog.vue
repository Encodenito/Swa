<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextArea from '@/components/shop/staff/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatPeso } from '@/lib/money';
import { useForm } from '@inertiajs/vue3';

/**
 * Admin settles a refund whose outcome is unknown, after checking the PayMongo dashboard.
 * Nothing is sent to PayMongo from here: the admin records what the dashboard shows.
 */
const props = defineProps<{ refundId: number; amount: string; originalRef: string | null }>();
const open = defineModel<boolean>('open', { required: true });

const form = useForm({ outcome: '' as '' | 'found' | 'not_found', paymongo_refund_id: '', reason: '' });

function submit() {
    form.post(route('admin.payments.reconcile', { payment: props.refundId }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle>Reconcile refund #{{ refundId }}</DialogTitle>
                <DialogDescription>
                    In the PayMongo dashboard, open payment <span class="font-mono">{{ originalRef ?? '—' }}</span> and look for a refund of
                    {{ formatPeso(amount) }} with metadata <span class="font-mono">refund_id = {{ refundId }}</span
                    >. Record what you find. Nothing is sent to PayMongo from here.
                </DialogDescription>
            </DialogHeader>

            <form :id="`reconcile-${refundId}`" class="space-y-4" @submit.prevent="submit">
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium">What does PayMongo show?</legend>
                    <label class="flex items-start gap-3 rounded-md border border-border p-3">
                        <input v-model="form.outcome" type="radio" value="found" class="mt-1" />
                        <span>The refund is there. Record it as refunded.</span>
                    </label>
                    <label class="flex items-start gap-3 rounded-md border border-border p-3">
                        <input v-model="form.outcome" type="radio" value="not_found" class="mt-1" />
                        <span>There is no such refund. Mark it failed so it can be retried.</span>
                    </label>
                    <InputError :message="form.errors.outcome" />
                </fieldset>

                <div v-if="form.outcome === 'found'" class="space-y-1">
                    <Label :for="`reconcile-ref-${refundId}`">PayMongo refund id</Label>
                    <Input
                        :id="`reconcile-ref-${refundId}`"
                        v-model="form.paymongo_refund_id"
                        placeholder="ref_…"
                        autocomplete="off"
                        class="font-mono"
                    />
                    <InputError :message="form.errors.paymongo_refund_id" />
                </div>

                <div class="space-y-1">
                    <Label :for="`reconcile-reason-${refundId}`">Reason</Label>
                    <TextArea :id="`reconcile-reason-${refundId}`" v-model="form.reason" rows="2" placeholder="What you checked in the dashboard" />
                    <InputError :message="form.errors.reason" />
                </div>
            </form>

            <DialogFooter class="gap-2">
                <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                <Button type="submit" :form="`reconcile-${refundId}`" :disabled="form.processing || !form.outcome">Record outcome</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
