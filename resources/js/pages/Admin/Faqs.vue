<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Chip from '@/components/shop/admin/Chip.vue';
import ConfirmDialog from '@/components/shop/staff/ConfirmDialog.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import FaqForm, { type Faq } from '@/components/shop/staff/FaqForm.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface TestResult {
    question: string;
    result: { matched: boolean; answer: string | null; question: string | null; related: string[]; handoff: boolean };
    threshold: number;
    scores: { id: number; question: string; score: number }[];
}

const props = defineProps<{ faqs: Faq[]; placeholders: string[]; test: TestResult | null }>();

const breadcrumbs = [
    { title: 'Admin dashboard', href: route('admin.home') },
    { title: 'Chatbot FAQs', href: route('admin.faqs.index') },
];

const nextSortOrder = computed(() => props.faqs.reduce((max, f) => Math.max(max, f.sort_order), 0) + 10);

// Add
const adding = ref(false);

// Edit (dialog)
const editTarget = ref<Faq | null>(null);
const editOpen = ref(false);

function edit(faq: Faq) {
    editTarget.value = faq;
    editOpen.value = true;
}

// Delete
const deleteTarget = ref<Faq | null>(null);
const deleteOpen = ref(false);
const deleteForm = useForm({});
const deleteError = computed(() => Object.values(deleteForm.errors as Record<string, string>)[0]);

function askDelete(faq: Faq) {
    deleteForm.clearErrors();
    deleteTarget.value = faq;
    deleteOpen.value = true;
}

function destroy() {
    if (!deleteTarget.value) return;
    deleteForm.delete(route('admin.faqs.destroy', { faq: deleteTarget.value.id }), {
        preserveScroll: true,
        onSuccess: () => (deleteOpen.value = false),
    });
}

// Test a question
const testForm = useForm({ question: props.test?.question ?? '' });

function runTest() {
    testForm.post(route('admin.faqs.test'), { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head title="Chatbot FAQs" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading
                title="Chatbot FAQs"
                description="The chat answers customers from this list. It picks the FAQ whose keywords best match the question; if none match well, it offers to pass the question to staff."
            />

            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
                <section aria-labelledby="faq-list-heading" class="min-w-0">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 id="faq-list-heading" class="text-lg font-semibold">
                            FAQs <span class="text-sm font-normal text-muted-foreground">({{ faqs.length }})</span>
                        </h2>
                        <Button v-if="!adding" type="button" class="h-10" @click="adding = true">
                            <Plus class="size-4" aria-hidden="true" />
                            Add FAQ
                        </Button>
                    </div>

                    <div v-if="adding" class="mb-4 rounded-lg border border-border bg-card p-4">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h3 class="font-semibold">New FAQ</h3>
                            <Button type="button" variant="ghost" size="sm" class="h-9" @click="adding = false">Cancel</Button>
                        </div>
                        <FaqForm id-prefix="faq-new" :placeholders="placeholders" :next-sort-order="nextSortOrder" @saved="adding = false" />
                    </div>

                    <EmptyState
                        v-if="faqs.length === 0"
                        title="No FAQs yet."
                        description="Until you add some, the chat can only offer to pass questions to staff."
                    />

                    <ol v-else class="space-y-3">
                        <li
                            v-for="faq in faqs"
                            :key="faq.id"
                            :class="['rounded-lg border border-border bg-card p-4 text-sm', !faq.active && 'opacity-75']"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p class="min-w-0 flex-1 font-semibold">{{ faq.question }}</p>
                                <div class="flex items-center gap-2">
                                    <Chip v-if="!faq.active">Hidden</Chip>
                                    <span class="text-xs tabular-nums text-muted-foreground">Order {{ faq.sort_order }}</span>
                                </div>
                            </div>
                            <p class="mt-2 whitespace-pre-line break-words text-muted-foreground">{{ faq.answer }}</p>
                            <p class="mt-2 break-words text-xs"><span class="text-muted-foreground">Keywords: </span>{{ faq.keywords }}</p>
                            <div class="mt-3 flex gap-2">
                                <Button type="button" variant="outline" size="sm" class="h-9" @click="edit(faq)">
                                    <Pencil class="size-4" aria-hidden="true" />
                                    Edit<span class="sr-only">: {{ faq.question }}</span>
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="h-9 text-red-700 hover:text-red-800 dark:text-red-400"
                                    @click="askDelete(faq)"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                    Delete<span class="sr-only">: {{ faq.question }}</span>
                                </Button>
                            </div>
                        </li>
                    </ol>
                </section>

                <section aria-labelledby="faq-test-heading" class="rounded-lg border border-border bg-card p-4 lg:sticky lg:top-4">
                    <h2 id="faq-test-heading" class="text-lg font-semibold">Test a question</h2>
                    <p class="mb-3 text-sm text-muted-foreground">See what the chat would answer and how each FAQ scores. Use it to tune keywords.</p>

                    <form class="grid gap-2" @submit.prevent="runTest">
                        <Label for="faq-test-question">Question</Label>
                        <div class="flex gap-2">
                            <Input
                                id="faq-test-question"
                                v-model="testForm.question"
                                maxlength="300"
                                required
                                placeholder="Can I pick up tomorrow?"
                                :disabled="testForm.processing"
                                :aria-invalid="!!testForm.errors.question || undefined"
                                aria-describedby="faq-test-question-error"
                            />
                            <Button type="submit" class="h-10 shrink-0" :disabled="testForm.processing">
                                <Search class="size-4" aria-hidden="true" />
                                {{ testForm.processing ? 'Testing…' : 'Test' }}
                            </Button>
                        </div>
                        <InputError id="faq-test-question-error" :message="testForm.errors.question" />
                    </form>

                    <div v-if="test" class="mt-4 space-y-3 text-sm" aria-live="polite">
                        <p class="text-muted-foreground">
                            Result for <span class="font-medium text-foreground">"{{ test.question }}"</span>
                        </p>
                        <div v-if="test.result.matched" class="rounded-md bg-muted px-3 py-2">
                            <p class="text-xs font-semibold text-muted-foreground">Answers with: {{ test.result.question }}</p>
                            <p class="mt-1 whitespace-pre-line break-words">{{ test.result.answer }}</p>
                        </div>
                        <p
                            v-else
                            class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
                        >
                            No FAQ scores high enough. The chat says "I'm not sure about that one." and offers a person.
                        </p>
                        <p v-if="test.result.related.length" class="text-muted-foreground">Also suggested: {{ test.result.related.join('; ') }}</p>

                        <table v-if="test.scores.length" class="w-full text-left">
                            <caption class="mb-1 text-left text-xs text-muted-foreground">
                                Top scores. An FAQ needs at least
                                {{
                                    test.threshold
                                }}
                                to be used.
                            </caption>
                            <thead>
                                <tr class="border-b border-border text-xs text-muted-foreground">
                                    <th scope="col" class="py-1.5 pr-2 font-medium">FAQ</th>
                                    <th scope="col" class="py-1.5 text-right font-medium">Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in test.scores" :key="row.id" class="border-b border-border last:border-0">
                                    <td class="py-1.5 pr-2">{{ row.question }}</td>
                                    <td class="py-1.5 text-right tabular-nums">
                                        <span :class="row.score >= test.threshold ? 'font-semibold text-emerald-700 dark:text-emerald-400' : ''">{{
                                            row.score
                                        }}</span>
                                        <span v-if="row.score >= test.threshold" class="sr-only"> (high enough)</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-muted-foreground">No active FAQs to score.</p>
                    </div>
                </section>
            </div>
        </div>

        <Dialog v-model:open="editOpen">
            <DialogContent class="max-h-[calc(100dvh-2rem)] max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Edit FAQ</DialogTitle>
                    <DialogDescription>Changes apply to the chat straight away.</DialogDescription>
                </DialogHeader>
                <FaqForm
                    v-if="editTarget"
                    :key="`faq-edit-${editTarget.id}`"
                    :id-prefix="`faq-edit-${editTarget.id}`"
                    :placeholders="placeholders"
                    :faq="editTarget"
                    @saved="editOpen = false"
                    @cancel="editOpen = false"
                />
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Delete this FAQ?"
            :description="
                deleteTarget ? `The chat will stop answering “${deleteTarget.question}”. To hide it for now, untick Active instead.` : undefined
            "
            confirm-label="Delete FAQ"
            destructive
            :processing="deleteForm.processing"
            @confirm="destroy"
        >
            <InputError :message="deleteError" />
        </ConfirmDialog>
    </AdminLayout>
</template>
