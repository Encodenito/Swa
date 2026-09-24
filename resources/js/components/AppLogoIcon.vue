<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- Shop mark: a yellow shelf price tag on an ink square. -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" :class="className" v-bind="$attrs" aria-hidden="true">
        <rect width="32" height="32" rx="7" fill="#14213D" />
        <path d="M9 8.5h9.2c.5 0 1 .2 1.4.6l5.3 5.5c.7.8.7 2 0 2.8l-5.3 5.5c-.4.4-.9.6-1.4.6H9c-1.1 0-2-.9-2-2V10.5c0-1.1.9-2 2-2Z" fill="#F2C230" />
        <circle cx="19.2" cy="16" r="1.8" fill="#14213D" />
        <path d="M10.5 13h5M10.5 16h4M10.5 19h5" stroke="#14213D" stroke-width="1.6" stroke-linecap="round" />
    </svg>
</template>
