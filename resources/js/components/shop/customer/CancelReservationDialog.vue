<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { formatPeso } from '@/lib/money';
import type { ReservationDetail } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/** Customer self-cancel. The server decides whether it is still allowed and refunds in full. */
const props = defineProps<{ reservation: ReservationDetail }>();

const open = ref(false);
const form = useForm({});
const error = computed(() => Object.values(form.errors as Record<string, string>)[0]);

const paid = computed(() => props.reservation.status === 'confirmed');

function submit() {
    form.post(route('account.reservations.cancel', { reservation: props.reservation.reference_code }), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}

function onOpenChange(value: boolean) {
    if (form.processing) return;
    open.value = value;
    if (!value) form.clearErrors();
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogTrigger as-child>
            <Button type="button" variant="outline" class="h-11 w-full sm:w-auto sm:px-6">Cancel reservation</Button>
        </DialogTrigger>
        <DialogContent class="max-h-[calc(100dvh-2rem)] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Cancel reservation {{ reservation.reference_code }}?</DialogTitle>
                <DialogDescription>The items go back on sale and this cannot be undone.</DialogDescription>
            </DialogHeader>

            <p v-if="paid" class="rounded-md bg-secondary px-3 py-2 text-sm">
                Your downpayment of <span class="font-semibold tabular-nums">{{ formatPeso(reservation.downpayment_amount) }}</span> will be refunded
                in full to your original payment method. GCash usually within a day; cards can take up to 30 days.
            </p>
            <p v-else class="rounded-md bg-secondary px-3 py-2 text-sm">Nothing was paid, so there is nothing to refund.</p>

            <InputError :message="error" />

            <DialogFooter class="gap-2 sm:gap-0">
                <Button type="button" variant="outline" :disabled="form.processing" @click="onOpenChange(false)">Keep reservation</Button>
                <Button type="button" variant="destructive" :disabled="form.processing" @click="submit">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" aria-hidden="true" />
                    Yes, cancel it
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
