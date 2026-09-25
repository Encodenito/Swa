<script setup lang="ts">
import { UserRound } from 'lucide-vue-next';
import type { ChatTurn } from './chat';

/** One chat turn. Text only (white-space: pre-line keeps the answer's line breaks); no HTML is ever rendered. */
defineProps<{ turn: ChatTurn; disabled?: boolean }>();
const emit = defineEmits<{ ask: [question: string]; handoff: [] }>();
</script>

<template>
    <li :class="['flex flex-col gap-2', turn.from === 'user' ? 'items-end' : 'items-start']">
        <p class="sr-only">{{ turn.from === 'user' ? 'You said:' : 'SWA assistant said:' }}</p>
        <div
            :class="[
                'max-w-[85%] whitespace-pre-line break-words rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed',
                turn.from === 'user'
                    ? 'rounded-br-md bg-primary text-primary-foreground'
                    : turn.tone === 'error'
                      ? 'rounded-bl-md border border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100'
                      : 'rounded-bl-md bg-muted text-foreground',
            ]"
        >
            <p v-if="turn.about" class="mb-1 text-xs font-semibold text-muted-foreground">{{ turn.about }}</p>
            <p>{{ turn.text }}</p>
        </div>

        <div v-if="turn.related?.length" class="flex max-w-full flex-wrap gap-1.5">
            <span class="w-full text-xs text-muted-foreground">{{ turn.handoff ? 'Did you mean' : 'Related questions' }}</span>
            <button
                v-for="q in turn.related"
                :key="q"
                type="button"
                :disabled="disabled"
                class="min-h-9 rounded-full border border-border bg-background px-3 py-1.5 text-left text-xs font-medium hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                @click="emit('ask', q)"
            >
                {{ q }}
            </button>
        </div>

        <button
            v-if="turn.handoff"
            type="button"
            class="inline-flex min-h-9 items-center gap-1.5 rounded-full bg-foreground px-3 py-1.5 text-xs font-semibold text-background hover:bg-foreground/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            @click="emit('handoff')"
        >
            <UserRound class="size-3.5" aria-hidden="true" />
            Talk to a person
        </button>
    </li>
</template>
