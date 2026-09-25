<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextArea from '@/components/shop/staff/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Confirm a money or destructive action that must record why it was taken (void, refund, review).
 * POSTs { reason } to `url`; the server re-checks everything.
 */
const props = withDefaults(
    defineProps<{
        idPrefix: string;
        url: string;
        title: string;
        description?: string;
        confirmLabel: string;
        destructive?: boolean;
        reasonLabel?: string;
        placeholder?: string;
    }>(),
    { description: undefined, destructive: false, reasonLabel: 'Reason', placeholder: undefined },
);

const open = defineModel<boolean>('open', { default: false });

const form = useForm({ reason: '' });

// Errors other than `reason` (e.g. a status conflict) still need to be visible in the dialog.
const otherError = computed(() => {
    const entries = Object.entries(form.errors as Record<string, string | undefined>).filter(([key]) => key !== 'reason');

    return entries[0]?.[1];
});

function submit() {
    form.post(props.url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
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
        <DialogContent class="max-h-[calc(100dvh-2rem)] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">{{ description }}</DialogDescription>
            </DialogHeader>

            <slot />

            <form :id="`${idPrefix}-form`" class="grid gap-1.5" @submit.prevent="submit">
                <Label :for="`${idPrefix}-reason`">{{ reasonLabel }}</Label>
                <TextArea
                    :id="`${idPrefix}-reason`"
                    v-model="form.reason"
                    required
                    minlength="3"
                    maxlength="255"
                    :placeholder="placeholder"
                    :disabled="form.processing"
                    :aria-invalid="!!form.errors.reason || undefined"
                    :aria-describedby="`${idPrefix}-reason-error`"
                />
                <InputError :id="`${idPrefix}-reason-error`" :message="form.errors.reason" />
                <InputError :message="otherError" />
            </form>

            <DialogFooter class="gap-2 sm:gap-0">
                <Button type="button" variant="outline" :disabled="form.processing" @click="onOpenChange(false)">Keep as is</Button>
                <Button type="submit" :form="`${idPrefix}-form`" :variant="destructive ? 'destructive' : 'default'" :disabled="form.processing">
                    {{ form.processing ? 'Working…' : confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
