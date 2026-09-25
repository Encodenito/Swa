<script setup lang="ts">
import { computed } from 'vue';

type Values = Record<string, unknown> | unknown[] | null;

/**
 * Compact "key: before → after" list for one audit entry. Values are rendered as plain text only.
 */
const props = defineProps<{ before: Values; after: Values }>();

function asRecord(values: Values): Record<string, unknown> {
    if (values === null || values === undefined) return {};
    if (Array.isArray(values)) return Object.fromEntries(values.map((v, i) => [String(i), v]));

    return values;
}

function show(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'object') return JSON.stringify(value);

    return String(value);
}

function keyLabel(key: string): string {
    const words = key.replace(/[_.]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

const rows = computed(() => {
    const before = asRecord(props.before);
    const after = asRecord(props.after);
    const keys = [...new Set([...Object.keys(before), ...Object.keys(after)])];

    return keys.map((key) => ({
        key,
        label: keyLabel(key),
        hasBefore: key in before,
        hasAfter: key in after,
        before: show(before[key]),
        after: show(after[key]),
    }));
});
</script>

<template>
    <dl v-if="rows.length" class="space-y-1 text-sm">
        <div v-for="row in rows" :key="row.key" class="grid gap-x-2 sm:grid-cols-[8rem_1fr]">
            <dt class="text-muted-foreground">{{ row.label }}</dt>
            <dd class="min-w-0 break-words">
                <template v-if="row.hasBefore && row.hasAfter">
                    <span class="text-muted-foreground">{{ row.before }}</span>
                    <span class="mx-1.5 text-muted-foreground" aria-hidden="true">→</span>
                    <span class="sr-only">changed to</span>
                    <span class="font-medium">{{ row.after }}</span>
                </template>
                <span v-else-if="row.hasAfter" class="font-medium">{{ row.after }}</span>
                <span v-else class="text-muted-foreground">Was {{ row.before }}</span>
            </dd>
        </div>
    </dl>
    <p v-else class="text-sm text-muted-foreground">No details recorded.</p>
</template>
