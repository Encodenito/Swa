<script setup lang="ts">
import { ImageOff } from 'lucide-vue-next';
import { ref, watch } from 'vue';

/** Product photo with a neutral placeholder when there is no image or it fails to load. */
const props = withDefaults(defineProps<{ src: string | null; alt: string; eager?: boolean }>(), { eager: false });

const failed = ref(false);
watch(
    () => props.src,
    () => (failed.value = false),
);
</script>

<template>
    <img
        v-if="src && !failed"
        :src="src"
        :alt="alt"
        :loading="eager ? 'eager' : 'lazy'"
        decoding="async"
        class="size-full object-contain"
        @error="failed = true"
    />
    <div
        v-else
        class="flex size-full flex-col items-center justify-center gap-1 bg-muted text-muted-foreground"
        role="img"
        :aria-label="`No photo of ${alt}`"
    >
        <ImageOff class="size-6" aria-hidden="true" />
        <span class="text-xs">No photo yet</span>
    </div>
</template>
