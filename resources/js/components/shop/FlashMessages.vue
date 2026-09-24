<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<SharedData>();
const flash = computed(() => page.props.flash);
</script>

<template>
    <div aria-live="polite" class="space-y-2">
        <p
            v-if="flash?.success"
            class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100"
        >
            {{ flash.success }}
        </p>
        <p v-if="flash?.error" role="alert" class="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
            {{ flash.error }}
        </p>
    </div>
</template>
