<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="rounded-lg border border-dashed border-border bg-card px-4 py-8 text-center sm:px-6">
        <p class="font-medium">{{ title }}</p>
        <p v-if="description" class="mx-auto mt-1 max-w-prose text-sm text-muted-foreground">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4 flex justify-center">
            <slot />
        </div>
    </div>
</template>
