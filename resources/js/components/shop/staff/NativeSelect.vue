<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

/** A native <select> styled like the ui Input: fast with the keyboard and on phones. */
const props = defineProps<{ class?: HTMLAttributes['class'] }>();
const model = defineModel<string | number | null>();
</script>

<template>
    <select
        v-model="model"
        :class="
            cn(
                'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                props.class,
            )
        "
    >
        <slot />
    </select>
</template>
