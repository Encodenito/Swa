<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import { ImageOff } from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';

/**
 * Product photo. Shows a skeleton until the photo has decoded, then fades it in; a cached photo
 * appears at once (no fade), and a missing or broken one gets a neutral placeholder.
 */
const props = withDefaults(defineProps<{ src: string | null; alt: string; eager?: boolean }>(), { eager: false });

const img = ref<HTMLImageElement | null>(null);
const failed = ref(false);
const loaded = ref(false);
const instant = ref(false);

watch(
    () => props.src,
    () => {
        failed.value = false;
        loaded.value = false;
        instant.value = false;
    },
);

onMounted(() => {
    // Already in the browser cache: skip the skeleton and the fade entirely.
    if (img.value?.complete && img.value.naturalWidth > 0) {
        loaded.value = true;
        instant.value = true;
    }
});
</script>

<template>
    <div v-if="src && !failed" class="relative size-full">
        <Skeleton v-if="!loaded" class="absolute inset-0" />
        <img
            ref="img"
            :src="src"
            :alt="alt"
            :loading="eager ? 'eager' : 'lazy'"
            decoding="async"
            :class="[
                'relative size-full object-contain',
                instant ? '' : 'transition-opacity duration-200 ease-out motion-reduce:transition-none',
                loaded ? 'opacity-100' : 'opacity-0',
            ]"
            @load="loaded = true"
            @error="failed = true"
        />
    </div>
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
