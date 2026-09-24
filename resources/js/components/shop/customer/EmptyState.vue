<script setup lang="ts">
/** Dashed panel for "nothing here yet" states. Put the next action in the default slot. */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="rounded-lg border border-dashed border-border bg-card px-6 py-10 text-center">
        <p class="text-lg font-semibold">{{ title }}</p>
        <p v-if="description" class="mx-auto mt-1 max-w-prose text-sm text-muted-foreground">{{ description }}</p>
        <div v-if="$slots.default" class="mt-5 flex flex-wrap justify-center gap-3">
            <slot />
        </div>
    </div>
</template>
