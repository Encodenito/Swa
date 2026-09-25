<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { MessageCircleQuestion, SendHorizontal, Trash2, UserRound } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import ChatBubble from './ChatBubble.vue';
import ChatHandoffForm from './ChatHandoffForm.vue';
import {
    EXPIRED_MESSAGE,
    FRIENDLY_ERROR,
    MAX_TURNS,
    OFFLINE_MESSAGE,
    THROTTLED_MESSAGE,
    clearTurns,
    fieldErrors,
    loadTurns,
    postJson,
    saveTurns,
    setChatOwner,
    turnId,
    type AskResponse,
    type ChatTurn,
} from './chat';

/**
 * FAQ chat for customers and visitors. Answers come from the shop's FAQ list on the server;
 * unanswered questions can be handed to staff. Mounted by PublicLayout and AccountLayout only.
 */
const SUGGESTIONS = ['How does reserving work?', 'Can I cancel?', 'Do you deliver?', 'How can I pay?'];

const isDesktop = useMediaQuery('(min-width: 640px)');

const open = ref(false);
const view = ref<'chat' | 'handoff'>('chat');
const turns = ref<ChatTurn[]>([]);
const question = ref('');
const inputError = ref<string | undefined>(undefined);
const sending = ref(false);

const launcher = ref<HTMLButtonElement | null>(null);
const questionInput = ref<InstanceType<typeof Input> | null>(null);
const list = ref<HTMLElement | null>(null);
const handoffForm = ref<InstanceType<typeof ChatHandoffForm> | null>(null);

// The history belongs to whoever is signed in (or "guest"), so the next person on a shared
// computer never sees it.
const page = usePage<SharedData>();
setChatOwner(page.props.auth.user?.id ?? null);

onMounted(() => {
    turns.value = loadTurns();
});

watch(turns, (value) => saveTurns(value), { deep: true });

// The last thing the visitor asked, to prefill the handoff message.
const lastQuestion = computed(() => [...turns.value].reverse().find((t) => t.from === 'user')?.text ?? '');

function push(turn: Omit<ChatTurn, 'id'>) {
    turns.value = [...turns.value, { id: turnId(), ...turn }].slice(-MAX_TURNS);
    scrollToEnd();
}

function scrollToEnd() {
    nextTick(() => {
        if (list.value) list.value.scrollTop = list.value.scrollHeight;
    });
}

function focusInput() {
    nextTick(() => (questionInput.value?.$el as HTMLInputElement | undefined)?.focus());
}

async function ask(text: string) {
    const q = text.trim();
    if (sending.value) return;
    if (q.length < 2) {
        inputError.value = 'Type a question first.';
        focusInput();
        return;
    }

    inputError.value = undefined;
    push({ from: 'user', text: q.slice(0, 300) });
    question.value = '';
    sending.value = true;

    try {
        const { status, data } = await postJson(route('chatbot.ask'), { question: q.slice(0, 300) });
        const body = data as AskResponse | null;

        if (status === 200 && body) {
            if (body.matched && body.answer) {
                push({ from: 'bot', text: body.answer, about: body.question, related: body.related ?? [] });
            } else {
                push({ from: 'bot', text: "I'm not sure about that one.", related: body.related ?? [], handoff: true });
            }
        } else if (status === 429) {
            push({ from: 'bot', text: THROTTLED_MESSAGE, tone: 'error' });
        } else if (status === 422) {
            push({ from: 'bot', text: fieldErrors(data).question ?? FRIENDLY_ERROR, tone: 'error' });
        } else if (status === 419) {
            push({ from: 'bot', text: EXPIRED_MESSAGE, tone: 'error' });
        } else {
            push({ from: 'bot', text: FRIENDLY_ERROR, tone: 'error' });
        }
    } catch {
        push({ from: 'bot', text: OFFLINE_MESSAGE, tone: 'error' });
    } finally {
        sending.value = false;
        focusInput();
    }
}

function clearChat() {
    turns.value = [];
    clearTurns();
    view.value = 'chat';
    focusInput();
}

function startHandoff() {
    view.value = 'handoff';
    nextTick(() => handoffForm.value?.focus());
}

function backToChat() {
    view.value = 'chat';
    scrollToEnd();
    focusInput();
}

function onHandoffSent(message: string) {
    push({ from: 'bot', text: message, tone: 'notice' });
}

function onOpenAutoFocus(event: Event) {
    event.preventDefault();
    if (view.value === 'handoff') {
        nextTick(() => handoffForm.value?.focus());
    } else {
        scrollToEnd();
        focusInput();
    }
}

function onCloseAutoFocus(event: Event) {
    event.preventDefault();
    launcher.value?.focus();
}
</script>

<template>
    <!-- Keeps the last bit of page content clear of the floating button. -->
    <div aria-hidden="true" class="h-20" />

    <button
        ref="launcher"
        type="button"
        aria-haspopup="dialog"
        :aria-expanded="open"
        title="Questions? Chat with us"
        class="fixed bottom-[calc(1rem+env(safe-area-inset-bottom))] right-[calc(1rem+env(safe-area-inset-right))] z-40 inline-flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg ring-offset-background transition-transform hover:scale-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 motion-reduce:transition-none motion-reduce:hover:scale-100"
        @click="open = true"
    >
        <MessageCircleQuestion class="size-7" aria-hidden="true" />
        <span class="sr-only">Questions? Chat with us</span>
    </button>

    <Sheet v-model:open="open">
        <SheetContent
            :side="isDesktop ? 'right' : 'bottom'"
            :class="['flex flex-col gap-0 p-0', isDesktop ? 'w-full sm:max-w-md' : 'h-[85dvh] rounded-t-2xl pb-[env(safe-area-inset-bottom)]']"
            @open-auto-focus="onOpenAutoFocus"
            @close-auto-focus="onCloseAutoFocus"
        >
            <header class="border-b border-border px-4 pb-3 pr-12 pt-4">
                <SheetTitle class="text-base">{{ view === 'handoff' ? 'Talk to a person' : 'Ask SWA I.T. Supplies' }}</SheetTitle>
                <SheetDescription class="text-xs">
                    {{ view === 'handoff' ? 'Staff reply by email or text during shop hours.' : 'Quick answers about reserving, paying and pickup.' }}
                </SheetDescription>
            </header>

            <ChatHandoffForm
                v-if="view === 'handoff'"
                ref="handoffForm"
                :turns="turns"
                :initial-message="lastQuestion"
                @back="backToChat"
                @sent="onHandoffSent"
            />

            <template v-else>
                <div ref="list" class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4">
                    <div v-if="turns.length === 0" class="space-y-3">
                        <p class="text-sm text-muted-foreground">Hi! Ask a question, or start with one of these:</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="s in SUGGESTIONS"
                                :key="s"
                                type="button"
                                :disabled="sending"
                                class="min-h-10 rounded-full border border-border bg-background px-3.5 py-2 text-left text-sm font-medium hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                                @click="ask(s)"
                            >
                                {{ s }}
                            </button>
                        </div>
                    </div>

                    <ol aria-live="polite" aria-relevant="additions" aria-label="Chat messages" class="space-y-4">
                        <ChatBubble v-for="turn in turns" :key="turn.id" :turn="turn" :disabled="sending" @ask="ask" @handoff="startHandoff" />
                        <li v-if="sending" class="flex items-center gap-1 text-muted-foreground">
                            <span class="sr-only">Looking for an answer…</span>
                            <span class="size-2 animate-pulse rounded-full bg-current motion-reduce:animate-none" aria-hidden="true" />
                            <span
                                class="size-2 animate-pulse rounded-full bg-current [animation-delay:150ms] motion-reduce:animate-none"
                                aria-hidden="true"
                            />
                            <span
                                class="size-2 animate-pulse rounded-full bg-current [animation-delay:300ms] motion-reduce:animate-none"
                                aria-hidden="true"
                            />
                        </li>
                    </ol>
                </div>

                <form class="border-t border-border px-4 pb-3 pt-3" novalidate @submit.prevent="ask(question)">
                    <Label for="chat-question" class="sr-only">Your question</Label>
                    <div class="flex gap-2">
                        <Input
                            id="chat-question"
                            ref="questionInput"
                            v-model="question"
                            maxlength="300"
                            autocomplete="off"
                            enterkeyhint="send"
                            placeholder="Your question"
                            :aria-invalid="!!inputError || undefined"
                            aria-describedby="chat-question-error"
                            class="h-11"
                        />
                        <Button type="submit" class="h-11 shrink-0 px-4" :disabled="sending">
                            <SendHorizontal class="size-4" aria-hidden="true" />
                            Send
                        </Button>
                    </div>
                    <InputError id="chat-question-error" :message="inputError" class="mt-1" />
                    <div class="mt-2 flex items-center justify-between gap-2">
                        <Button type="button" variant="ghost" size="sm" class="h-9 px-2 text-xs" @click="startHandoff">
                            <UserRound class="size-3.5" aria-hidden="true" />
                            Talk to a person
                        </Button>
                        <Button
                            v-if="turns.length > 0"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="h-9 px-2 text-xs text-muted-foreground"
                            :disabled="sending"
                            @click="clearChat"
                        >
                            <Trash2 class="size-3.5" aria-hidden="true" />
                            Clear chat
                        </Button>
                    </div>
                </form>
            </template>
        </SheetContent>
    </Sheet>
</template>
