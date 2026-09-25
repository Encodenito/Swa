<script setup lang="ts">
import { computed } from 'vue';

type Tone = 'neutral' | 'info' | 'accent' | 'warning' | 'danger';

const props = withDefaults(defineProps<{ tone?: Tone }>(), { tone: 'neutral' });

const toneClass = computed(
    () =>
        ({
            neutral: 'bg-muted text-muted-foreground',
            info: 'bg-blue-100 text-blue-900 dark:bg-blue-900/40 dark:text-blue-100',
            accent: 'bg-violet-100 text-violet-900 dark:bg-violet-900/40 dark:text-violet-100',
            warning: 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100',
            danger: 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100',
        })[props.tone],
);
</script>

<template>
    <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', toneClass]"><slot /></span>
</template>
