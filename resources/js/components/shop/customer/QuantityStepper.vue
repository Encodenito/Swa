<script setup lang="ts">
import { Minus, Plus } from 'lucide-vue-next';

/**
 * Whole-number quantity input with - / + buttons. Emits a clamped value; the server re-validates it.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        modelValue: number;
        min?: number;
        max: number;
        disabled?: boolean;
        invalid?: boolean;
        describedby?: string;
    }>(),
    { min: 1, disabled: false, invalid: false, describedby: undefined },
);

const emit = defineEmits<{ 'update:modelValue': [value: number] }>();

function clamp(value: number): number {
    return Math.min(Math.max(value, props.min), Math.max(props.max, props.min));
}

function step(delta: number) {
    const next = clamp(props.modelValue + delta);
    if (next !== props.modelValue) emit('update:modelValue', next);
}

function onChange(event: Event) {
    const input = event.target as HTMLInputElement;
    const parsed = Number.parseInt(input.value, 10);

    if (Number.isNaN(parsed)) {
        input.value = String(props.modelValue);

        return;
    }

    const next = clamp(parsed);
    input.value = String(next);
    if (next !== props.modelValue) emit('update:modelValue', next);
}
</script>

<template>
    <div class="inline-flex items-stretch rounded-md border border-input bg-background" :class="{ 'border-destructive': invalid }">
        <button
            type="button"
            class="inline-flex size-11 items-center justify-center rounded-l-md hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40"
            :disabled="disabled || modelValue <= min"
            :aria-label="`Decrease ${label.toLowerCase()}`"
            :aria-controls="id"
            @click="step(-1)"
        >
            <Minus class="size-4" aria-hidden="true" />
        </button>
        <input
            :id="id"
            type="number"
            inputmode="numeric"
            :min="min"
            :max="max"
            step="1"
            :value="modelValue"
            :disabled="disabled"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedby"
            class="w-14 appearance-none border-x border-input bg-transparent text-center font-semibold tabular-nums focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring disabled:opacity-50 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
            @change="onChange"
        />
        <button
            type="button"
            class="inline-flex size-11 items-center justify-center rounded-r-md hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40"
            :disabled="disabled || modelValue >= max"
            :aria-label="`Increase ${label.toLowerCase()}`"
            :aria-controls="id"
            @click="step(1)"
        >
            <Plus class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>
