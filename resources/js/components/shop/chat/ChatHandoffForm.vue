<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextArea from '@/components/shop/staff/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2 } from 'lucide-vue-next';
import { reactive, ref } from 'vue';
import { EXPIRED_MESSAGE, FRIENDLY_ERROR, OFFLINE_MESSAGE, THROTTLED_MESSAGE, fieldErrors, postJson, type ChatTurn } from './chat';

/**
 * "Talk to a person": sends the question and the last 10 chat turns to staff, who reply by email or text.
 */
const props = defineProps<{ turns: ChatTurn[]; initialMessage?: string }>();
const emit = defineEmits<{ back: []; sent: [message: string] }>();

const page = usePage<SharedData>();

const form = reactive({
    name: page.props.auth.user?.name ?? '',
    contact: '',
    message: props.initialMessage ?? '',
});
const errors = ref<Record<string, string>>({});
const generalError = ref<string | null>(null);
const processing = ref(false);
const sentMessage = ref<string | null>(null);

const nameInput = ref<InstanceType<typeof Input> | null>(null);

function transcript() {
    return props.turns
        .filter((t) => !t.tone)
        .slice(-10)
        .map((t) => ({ from: t.from, text: t.text.slice(0, 1000) }));
}

async function submit() {
    if (processing.value) return;
    processing.value = true;
    errors.value = {};
    generalError.value = null;

    try {
        const { status, data } = await postJson(route('chatbot.handoff'), { ...form, transcript: transcript() });

        if (status === 201 || status === 200) {
            const message = (data as { message?: string } | null)?.message ?? 'Thanks! Our staff will contact you during shop hours.';
            sentMessage.value = message;
            emit('sent', message);
        } else if (status === 422) {
            errors.value = fieldErrors(data);
            if (Object.keys(errors.value).every((k) => k.startsWith('transcript'))) {
                generalError.value = 'We could not attach the chat. Clear the chat and try again.';
            }
        } else if (status === 409) {
            // Already waiting for staff: the server says so.
            sentMessage.value = (data as { message?: string } | null)?.message ?? 'We already have your request and will contact you soon.';
        } else if (status === 429) {
            generalError.value = THROTTLED_MESSAGE;
        } else if (status === 419) {
            generalError.value = EXPIRED_MESSAGE;
        } else {
            generalError.value = FRIENDLY_ERROR;
        }
    } catch {
        generalError.value = OFFLINE_MESSAGE;
    } finally {
        processing.value = false;
    }
}

defineExpose({
    focus: () => (nameInput.value?.$el as HTMLInputElement | undefined)?.focus(),
});
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div v-if="sentMessage" class="flex flex-1 flex-col items-center justify-center gap-3 px-6 text-center" role="status">
            <CheckCircle2 class="size-10 text-emerald-600" aria-hidden="true" />
            <p class="max-w-xs text-sm">{{ sentMessage }}</p>
            <Button type="button" variant="outline" class="h-10" @click="emit('back')">Back to chat</Button>
        </div>

        <form v-else class="flex min-h-0 flex-1 flex-col" novalidate @submit.prevent="submit">
            <div class="flex-1 space-y-4 overflow-y-auto px-4 py-4">
                <p class="text-sm text-muted-foreground">
                    Leave your details and a staff member will reply during shop hours. Your chat so far is included so you don't have to repeat
                    yourself.
                </p>

                <div class="grid gap-1.5">
                    <Label for="chat-handoff-name">Your name</Label>
                    <Input
                        id="chat-handoff-name"
                        ref="nameInput"
                        v-model="form.name"
                        autocomplete="name"
                        maxlength="100"
                        required
                        :disabled="processing"
                        :aria-invalid="!!errors.name || undefined"
                        aria-describedby="chat-handoff-name-error"
                    />
                    <InputError id="chat-handoff-name-error" :message="errors.name" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="chat-handoff-contact">Email or mobile number</Label>
                    <Input
                        id="chat-handoff-contact"
                        v-model="form.contact"
                        autocomplete="email"
                        inputmode="email"
                        maxlength="150"
                        required
                        placeholder="you@example.com or 09171234567"
                        :disabled="processing"
                        :aria-invalid="!!errors.contact || undefined"
                        aria-describedby="chat-handoff-contact-error"
                    />
                    <InputError id="chat-handoff-contact-error" :message="errors.contact" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="chat-handoff-message">Your message</Label>
                    <TextArea
                        id="chat-handoff-message"
                        v-model="form.message"
                        rows="4"
                        maxlength="1000"
                        required
                        :disabled="processing"
                        :aria-invalid="!!errors.message || undefined"
                        aria-describedby="chat-handoff-message-error"
                    />
                    <InputError id="chat-handoff-message-error" :message="errors.message" />
                </div>

                <p v-if="generalError" role="alert" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-900 dark:bg-red-950 dark:text-red-100">
                    {{ generalError }}
                </p>
            </div>

            <div class="flex gap-2 border-t border-border px-4 py-3">
                <Button type="button" variant="ghost" class="h-10" :disabled="processing" @click="emit('back')">
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    Back
                </Button>
                <Button type="submit" class="h-10 flex-1" :disabled="processing">{{ processing ? 'Sending…' : 'Send to staff' }}</Button>
            </div>
        </form>
    </div>
</template>
