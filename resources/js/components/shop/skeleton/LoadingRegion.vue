<script setup lang="ts">
import { useDelayedLoading } from '@/composables/useDelayedLoading';
import { toRef } from 'vue';
import CardGridSkeleton from './CardGridSkeleton.vue';
import ListSkeleton from './ListSkeleton.vue';

/**
 * Wraps a results area that reloads in place (search, filters, pagination). While a reload takes
 * longer than a blink, the results are swapped for a skeleton of the same shape; fast reloads
 * show nothing at all. The skeleton leaves quickly and the new results fade in.
 */
// Classes passed in (e.g. spacing) style the results, not the wrapper.
defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{ loading: boolean; variant?: 'list' | 'cards'; count?: number }>(), {
    variant: 'list',
    count: undefined,
});

const showSkeleton = useDelayedLoading(toRef(props, 'loading'));
</script>

<template>
    <div :aria-busy="loading">
        <span v-if="showSkeleton" class="sr-only" role="status">Loading…</span>
        <Transition
            mode="out-in"
            enter-active-class="transition-opacity duration-200 ease-out motion-reduce:transition-none"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-100 ease-out motion-reduce:transition-none"
            leave-to-class="opacity-0"
        >
            <CardGridSkeleton v-if="showSkeleton && variant === 'cards'" :count="count" />
            <ListSkeleton v-else-if="showSkeleton" :rows="count" />
            <div v-else v-bind="$attrs">
                <slot />
            </div>
        </Transition>
    </div>
</template>
