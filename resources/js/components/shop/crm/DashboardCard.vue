<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, ArrowRight } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * One staff dashboard card: a big count, a label, a short list and a link to the full page.
 * `attention` adds a coloured left border AND a text label, so the state never depends on colour alone.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        count: number;
        href: string;
        linkLabel: string;
        attention?: 'amber' | 'red' | null;
        attentionLabel?: string;
        hint?: string;
        emptyText?: string;
    }>(),
    { attention: null, attentionLabel: 'Needs attention', hint: undefined, emptyText: 'Nothing here right now.' },
);

const headingId = computed(() => `card-${props.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`);

const border = computed(() => (props.attention === 'red' ? 'border-danger-border' : props.attention === 'amber' ? 'border-warning-border' : ''));

const badge = computed(() => (props.attention === 'red' ? 'bg-danger-chip text-danger-strong' : 'bg-warning-chip text-warning-strong'));
</script>

<template>
    <section :aria-labelledby="headingId" :class="['flex flex-col rounded-lg border border-border bg-card p-4', border]">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 :id="headingId" class="text-sm font-medium text-muted-foreground">{{ title }}</h2>
                <p class="mt-1 text-3xl font-semibold tabular-nums">{{ count }}</p>
                <p v-if="hint" class="mt-0.5 text-xs text-muted-foreground">{{ hint }}</p>
            </div>
            <span v-if="attention" :class="['inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold', badge]">
                <AlertTriangle class="size-3.5" aria-hidden="true" />
                {{ attentionLabel }}
            </span>
        </div>

        <div class="mt-3 flex-1 text-sm">
            <slot v-if="count > 0" />
            <p v-else class="text-muted-foreground">{{ emptyText }}</p>
        </div>

        <Link
            :href="href"
            class="mt-4 inline-flex items-center gap-1 self-start text-sm font-medium underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
        >
            {{ linkLabel }}
            <ArrowRight class="size-4" aria-hidden="true" />
        </Link>
    </section>
</template>
