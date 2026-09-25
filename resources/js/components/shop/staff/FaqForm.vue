<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextArea from '@/components/shop/staff/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';

export interface Faq {
    id: number;
    question: string;
    answer: string;
    keywords: string;
    active: boolean;
    sort_order: number;
}

/**
 * Add or edit one chatbot FAQ. Without `faq` it POSTs a new one; with it, PUTs the changes.
 */
const props = defineProps<{ idPrefix: string; placeholders: string[]; faq?: Faq | null; nextSortOrder?: number }>();
const emit = defineEmits<{ saved: []; cancel: [] }>();

const form = useForm({
    question: props.faq?.question ?? '',
    answer: props.faq?.answer ?? '',
    keywords: props.faq?.keywords ?? '',
    active: props.faq?.active ?? true,
    sort_order: props.faq?.sort_order ?? props.nextSortOrder ?? 0,
});

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (!props.faq) form.reset();
            emit('saved');
        },
    };

    if (props.faq) {
        form.put(route('admin.faqs.update', { faq: props.faq.id }), options);
    } else {
        form.post(route('admin.faqs.store'), options);
    }
}

const id = (field: string) => `${props.idPrefix}-${field}`;
</script>

<template>
    <form class="grid gap-4" @submit.prevent="submit">
        <div class="grid gap-1.5">
            <Label :for="id('question')">Question</Label>
            <Input
                :id="id('question')"
                v-model="form.question"
                maxlength="255"
                required
                :disabled="form.processing"
                :aria-invalid="!!form.errors.question || undefined"
                :aria-describedby="id('question-error')"
            />
            <InputError :id="id('question-error')" :message="form.errors.question" />
        </div>

        <div class="grid gap-1.5">
            <Label :for="id('answer')">Answer</Label>
            <TextArea
                :id="id('answer')"
                v-model="form.answer"
                rows="5"
                maxlength="2000"
                required
                :disabled="form.processing"
                :aria-invalid="!!form.errors.answer || undefined"
                :aria-describedby="`${id('answer-hint')} ${id('answer-error')}`"
            />
            <p :id="id('answer-hint')" class="text-xs text-muted-foreground">
                Plain text; line breaks are kept. These are replaced with the current settings:
                <span v-for="(p, i) in placeholders" :key="p"
                    ><code class="rounded bg-muted px-1 py-0.5 text-[0.7rem]">{{ p }}</code
                    >{{ i < placeholders.length - 1 ? ' ' : '' }}</span
                >
            </p>
            <InputError :id="id('answer-error')" :message="form.errors.answer" />
        </div>

        <div class="grid gap-1.5">
            <Label :for="id('keywords')">Keywords</Label>
            <Input
                :id="id('keywords')"
                v-model="form.keywords"
                maxlength="1000"
                required
                placeholder="reserve, reservation, how to order"
                :disabled="form.processing"
                :aria-invalid="!!form.errors.keywords || undefined"
                :aria-describedby="`${id('keywords-hint')} ${id('keywords-error')}`"
            />
            <p :id="id('keywords-hint')" class="text-xs text-muted-foreground">Separate with commas. Words customers might type for this question.</p>
            <InputError :id="id('keywords-error')" :message="form.errors.keywords" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2 sm:items-end">
            <div class="grid gap-1.5">
                <Label :for="id('sort')">Order</Label>
                <Input
                    :id="id('sort')"
                    v-model.number="form.sort_order"
                    type="number"
                    inputmode="numeric"
                    min="0"
                    max="100000"
                    required
                    :disabled="form.processing"
                    :aria-invalid="!!form.errors.sort_order || undefined"
                    :aria-describedby="`${id('sort-hint')} ${id('sort-error')}`"
                />
                <p :id="id('sort-hint')" class="text-xs text-muted-foreground">Lower numbers win ties.</p>
                <InputError :id="id('sort-error')" :message="form.errors.sort_order" />
            </div>
            <div class="flex min-h-10 items-center gap-2 pb-5">
                <Checkbox :id="id('active')" v-model:checked="form.active" :disabled="form.processing" />
                <Label :for="id('active')">Active (the chat can use it)</Label>
            </div>
        </div>
        <InputError :message="form.errors.active" />

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button v-if="faq" type="button" variant="outline" class="h-10" :disabled="form.processing" @click="emit('cancel')">Cancel</Button>
            <Button type="submit" class="h-10" :disabled="form.processing">
                {{ form.processing ? 'Saving…' : faq ? 'Save FAQ' : 'Add FAQ' }}
            </Button>
        </div>
    </form>
</template>
