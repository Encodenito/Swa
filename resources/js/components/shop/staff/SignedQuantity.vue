<script setup lang="ts">
import { computed } from 'vue';

/** A stock movement quantity with an explicit + or − sign, so direction never depends on colour alone. */
const props = defineProps<{ quantity: number }>();

const display = computed(() => {
    if (props.quantity > 0) return { text: `+${props.quantity}`, sr: `${props.quantity} added`, tone: 'text-success' };
    if (props.quantity < 0) return { text: `−${Math.abs(props.quantity)}`, sr: `${Math.abs(props.quantity)} removed`, tone: 'text-danger' };

    return { text: '0', sr: 'no change', tone: 'text-muted-foreground' };
});
</script>

<template>
    <span :class="['font-semibold tabular-nums', display.tone]">
        <span aria-hidden="true">{{ display.text }}</span>
        <span class="sr-only">{{ display.sr }}</span>
    </span>
</template>
