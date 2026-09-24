<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextArea from '@/components/shop/staff/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

/** Record damaged units or a signed count correction on one stock batch. */
const props = defineProps<{ batchId: number; batchLabel: string; remaining: number }>();

const open = ref(false);
const form = useForm({ type: 'damaged' as 'damaged' | 'adjusted', quantity: '', reason: '' });

const idp = `adjust-${props.batchId}`;

function submit() {
    form.post(route('staff.stock.adjust', { batch: props.batchId }), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}

function onOpenChange(value: boolean) {
    open.value = value;
    if (!value) form.clearErrors();
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogTrigger as-child>
            <Button type="button" variant="outline" size="sm">Adjust</Button>
        </DialogTrigger>
        <DialogContent class="max-h-[calc(100dvh-2rem)] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Adjust stock</DialogTitle>
                <DialogDescription>{{ batchLabel }}, {{ remaining }} left in this batch.</DialogDescription>
            </DialogHeader>

            <form :id="`${idp}-form`" class="space-y-4" @submit.prevent="submit">
                <fieldset class="space-y-2">
                    <legend class="mb-1 text-sm font-medium">What happened?</legend>
                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-border p-3 has-[:checked]:border-primary">
                        <input v-model="form.type" type="radio" :name="`${idp}-type`" value="damaged" class="mt-1" />
                        <span class="text-sm">
                            <span class="block font-medium">Damaged or lost</span>
                            <span class="block text-muted-foreground">Units taken off the shelf.</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-border p-3 has-[:checked]:border-primary">
                        <input v-model="form.type" type="radio" :name="`${idp}-type`" value="adjusted" class="mt-1" />
                        <span class="text-sm">
                            <span class="block font-medium">Count correction</span>
                            <span class="block text-muted-foreground">The shelf count differs from the system.</span>
                        </span>
                    </label>
                    <InputError :message="form.errors.type" />
                </fieldset>

                <div class="grid gap-1.5">
                    <Label :for="`${idp}-qty`">{{ form.type === 'damaged' ? 'Units removed' : 'Change in units' }}</Label>
                    <Input
                        :id="`${idp}-qty`"
                        v-model="form.quantity"
                        type="number"
                        step="1"
                        :min="form.type === 'damaged' ? 1 : undefined"
                        :max="form.type === 'damaged' ? remaining : undefined"
                        required
                        class="tabular-nums"
                        :aria-invalid="!!form.errors.quantity"
                        :aria-describedby="`${idp}-qty-help ${idp}-qty-error`"
                    />
                    <p :id="`${idp}-qty-help`" class="text-sm text-muted-foreground">
                        <template v-if="form.type === 'damaged'">How many units to take out, for example 2.</template>
                        <template v-else>Use a minus sign to remove units (for example -2) or a plain number to add them (for example 3).</template>
                    </p>
                    <InputError :id="`${idp}-qty-error`" :message="form.errors.quantity" />
                </div>

                <div class="grid gap-1.5">
                    <Label :for="`${idp}-reason`">Reason</Label>
                    <TextArea
                        :id="`${idp}-reason`"
                        v-model="form.reason"
                        required
                        minlength="3"
                        maxlength="255"
                        placeholder="For example: box crushed in the stockroom"
                        :aria-invalid="!!form.errors.reason"
                        :aria-describedby="`${idp}-reason-error`"
                    />
                    <InputError :id="`${idp}-reason-error`" :message="form.errors.reason" />
                </div>
            </form>

            <DialogFooter class="gap-2 sm:gap-0">
                <Button type="button" variant="outline" :disabled="form.processing" @click="onOpenChange(false)">Cancel</Button>
                <Button type="submit" :form="`${idp}-form`" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save adjustment' }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
