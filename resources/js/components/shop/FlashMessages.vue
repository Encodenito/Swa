<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

const page = usePage<SharedData>();

// Sticky so the result of a form submitted at the bottom of a long page is still seen on a phone.
const success = ref<string | null>(null);
const error = ref<string | null>(null);

watch(
    () => page.props.flash,
    (flash) => {
        success.value = flash?.success ?? null;
        error.value = flash?.error ?? null;
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div aria-live="polite" class="sticky top-[var(--flash-top,0.5rem)] z-30 space-y-2">
        <p
            v-if="success"
            class="flex items-start gap-3 rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 shadow-sm dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100"
        >
            <span class="flex-1">{{ success }}</span>
            <button
                type="button"
                class="-m-1 rounded p-1 hover:bg-emerald-100 dark:hover:bg-emerald-900"
                aria-label="Dismiss message"
                @click="success = null"
            >
                <X class="size-4" />
            </button>
        </p>
        <p
            v-if="error"
            role="alert"
            class="flex items-start gap-3 rounded-md border border-destructive/40 bg-card px-4 py-3 text-sm text-destructive shadow-sm"
        >
            <span class="flex-1">{{ error }}</span>
            <button type="button" class="-m-1 rounded p-1 hover:bg-destructive/10" aria-label="Dismiss message" @click="error = null">
                <X class="size-4" />
            </button>
        </p>
    </div>
</template>
