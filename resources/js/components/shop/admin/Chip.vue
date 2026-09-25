<script setup lang="ts">
import { computed } from 'vue';

type Tone = 'neutral' | 'info' | 'accent' | 'warning' | 'danger';

const props = withDefaults(defineProps<{ tone?: Tone }>(), { tone: 'neutral' });

const toneClass = computed(
    () =>
        ({
            neutral: 'bg-muted text-muted-foreground',
            info: 'bg-info-chip text-info-strong',
            accent: 'bg-violet-100 text-violet-900 dark:bg-violet-900/40 dark:text-violet-100',
            warning: 'bg-warning-chip text-warning-strong',
            danger: 'bg-danger-chip text-danger-strong',
        })[props.tone],
);
</script>

<template>
    <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', toneClass]"><slot /></span>
</template>
