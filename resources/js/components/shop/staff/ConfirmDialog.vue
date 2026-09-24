<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

/**
 * Confirmation step for actions that cannot be undone from the page (deleting, starting preparation, receiving stock).
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        confirmLabel: string;
        cancelLabel?: string;
        destructive?: boolean;
        processing?: boolean;
    }>(),
    { description: undefined, cancelLabel: 'Cancel', destructive: false, processing: false },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: [] }>();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-[calc(100vw-2rem)] rounded-lg sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">{{ description }}</DialogDescription>
            </DialogHeader>
            <slot />
            <DialogFooter class="gap-2 sm:gap-0">
                <Button type="button" variant="outline" :disabled="processing" @click="open = false">{{ cancelLabel }}</Button>
                <Button type="button" :variant="destructive ? 'destructive' : 'default'" :disabled="processing" @click="emit('confirm')">
                    {{ processing ? 'Working…' : confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
