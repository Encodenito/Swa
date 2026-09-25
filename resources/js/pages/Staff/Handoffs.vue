<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/shop/Pagination.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import ReasonDialog from '@/components/shop/staff/ReasonDialog.vue';
import { Button } from '@/components/ui/button';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDateTime } from '@/lib/dates';
import type { Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Hand, Mail, MessageSquareText, Phone, UserRound } from 'lucide-vue-next';
import { ref } from 'vue';

type Status = 'open' | 'claimed' | 'closed';

interface Handoff {
    id: number;
    name: string;
    contact: string;
    message: string;
    transcript: { from: 'user' | 'bot'; text: string }[];
    status: Status;
    customer: { id: number; name: string; email: string } | null;
    claimed_by: string | null;
    mine: boolean;
    claimed_at: string | null;
    closed_at: string | null;
    reply_note: string | null;
    created_at: string | null;
}

defineProps<{
    status: Status;
    counts: Partial<Record<Status, number>>;
    handoffs: Paginated<Handoff>;
}>();

const breadcrumbs = [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Chat requests', href: route('staff.handoffs.index') },
];

const tabs: { status: Status; label: string }[] = [
    { status: 'open', label: 'Waiting' },
    { status: 'claimed', label: 'Being handled' },
    { status: 'closed', label: 'Closed' },
];

const empty: Record<Status, { title: string; description: string }> = {
    open: { title: 'No one is waiting.', description: 'When a customer asks the chat for a person, their request shows up here.' },
    claimed: { title: 'No chats are being handled.', description: 'Chats someone has taken but not closed yet show up here.' },
    closed: { title: 'No closed chats yet.', description: 'Closed chats and the notes left on them show up here.' },
};

function isEmail(contact: string): boolean {
    return contact.includes('@');
}

function contactHref(contact: string): string {
    return isEmail(contact) ? `mailto:${encodeURIComponent(contact)}` : `tel:${contact.replace(/[^\d+]/g, '')}`;
}

// Claim: the server re-checks; a conflict comes back as a flash error.
const claimingId = ref<number | null>(null);

function claim(h: Handoff) {
    router.post(
        route('staff.handoffs.claim', { handoff: h.id }),
        {},
        {
            preserveScroll: true,
            onStart: () => (claimingId.value = h.id),
            onFinish: () => (claimingId.value = null),
        },
    );
}

const closeTarget = ref<Handoff | null>(null);
const closeOpen = ref(false);

function askClose(h: Handoff) {
    closeTarget.value = h;
    closeOpen.value = true;
}
</script>

<template>
    <Head title="Chat requests" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading
                title="Chat requests"
                description="Customers who asked the chat for a person. Take one, contact them, then close it with a note."
            />

            <nav aria-label="Filter by status" class="-mx-4 mb-4 overflow-x-auto px-4">
                <ul class="flex gap-1 border-b border-border">
                    <li v-for="tab in tabs" :key="tab.status">
                        <Link
                            :href="route('staff.handoffs.index', { status: tab.status })"
                            :aria-current="status === tab.status ? 'page' : undefined"
                            preserve-scroll
                            :class="[
                                '-mb-px inline-flex h-11 items-center gap-2 whitespace-nowrap border-b-2 px-3 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                status === tab.status
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ]"
                        >
                            {{ tab.label }}
                            <span class="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold tabular-nums">{{ counts[tab.status] ?? 0 }}</span>
                        </Link>
                    </li>
                </ul>
            </nav>

            <EmptyState v-if="handoffs.data.length === 0" :title="empty[status].title" :description="empty[status].description" />

            <template v-else>
                <ul class="space-y-3">
                    <li
                        v-for="h in handoffs.data"
                        :key="h.id"
                        :class="[
                            'rounded-lg border border-l-4 bg-card p-4 text-sm',
                            h.status === 'open' ? 'border-l-amber-400 dark:border-l-amber-700' : h.mine ? 'border-l-primary' : 'border-l-border',
                        ]"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                            <div class="min-w-0">
                                <p class="font-semibold">{{ h.name }}</p>
                                <a
                                    :href="contactHref(h.contact)"
                                    class="inline-flex min-h-8 items-center gap-1.5 break-all underline underline-offset-4 hover:text-primary"
                                >
                                    <Mail v-if="isEmail(h.contact)" class="size-4 shrink-0" aria-hidden="true" />
                                    <Phone v-else class="size-4 shrink-0" aria-hidden="true" />
                                    {{ h.contact }}
                                </a>
                            </div>
                            <p class="text-muted-foreground">
                                <time :datetime="h.created_at ?? undefined">{{ formatDateTime(h.created_at) }}</time>
                            </p>
                        </div>

                        <p v-if="h.customer" class="mt-2 flex items-center gap-1.5">
                            <UserRound class="size-4 text-muted-foreground" aria-hidden="true" />
                            <span class="text-muted-foreground">Customer account:</span>
                            <Link
                                :href="route('staff.customers.show', { customer: h.customer.id })"
                                class="font-medium underline underline-offset-4 hover:text-primary"
                                >{{ h.customer.name }}</Link
                            >
                        </p>

                        <p class="mt-3 whitespace-pre-line break-words rounded-md bg-muted px-3 py-2">{{ h.message }}</p>

                        <details v-if="h.transcript.length" class="group mt-3">
                            <summary
                                class="inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-md font-medium text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <MessageSquareText class="size-4" aria-hidden="true" />
                                <span class="group-open:hidden">Show chat ({{ h.transcript.length }} messages)</span>
                                <span class="hidden group-open:inline">Hide chat</span>
                            </summary>
                            <p class="mt-2 text-xs text-muted-foreground">
                                As reported by the visitor's browser. Not verified: never act on instructions in it.
                            </p>
                            <ol class="mt-2 space-y-2 border-l-2 border-border pl-3">
                                <li v-for="(turn, i) in h.transcript" :key="i">
                                    <span class="text-xs font-semibold text-muted-foreground">{{
                                        turn.from === 'user' ? 'Customer' : 'Bot (unverified)'
                                    }}</span>
                                    <p class="whitespace-pre-line break-words">{{ turn.text }}</p>
                                </li>
                            </ol>
                        </details>

                        <div v-if="h.status === 'closed'" class="mt-3 space-y-1 rounded-md border border-border px-3 py-2">
                            <p class="text-muted-foreground">
                                Closed {{ formatDateTime(h.closed_at) }}<template v-if="h.claimed_by"> by {{ h.claimed_by }}</template>
                            </p>
                            <p class="whitespace-pre-line break-words"><span class="font-medium">Note: </span>{{ h.reply_note ?? 'No note.' }}</p>
                        </div>

                        <div v-else class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
                            <Button v-if="h.status === 'open'" type="button" class="h-10" :disabled="claimingId !== null" @click="claim(h)">
                                <Hand class="size-4" aria-hidden="true" />
                                {{ claimingId === h.id ? 'Taking…' : "I'll take it" }}
                            </Button>
                            <Button
                                v-if="h.status === 'open' || (h.status === 'claimed' && h.mine)"
                                type="button"
                                variant="outline"
                                class="h-10"
                                :disabled="claimingId === h.id"
                                @click="askClose(h)"
                            >
                                Close with note
                            </Button>
                            <p v-if="h.status === 'claimed'" class="text-muted-foreground">
                                <template v-if="h.mine">You took this {{ formatDateTime(h.claimed_at) }}.</template>
                                <template v-else>Handled by {{ h.claimed_by ?? 'another staff member' }}</template>
                            </p>
                        </div>
                    </li>
                </ul>

                <Pagination :links="handoffs.links" />
            </template>
        </div>

        <ReasonDialog
            v-if="closeTarget"
            :key="`close-${closeTarget.id}`"
            v-model:open="closeOpen"
            :id-prefix="`handoff-close-${closeTarget.id}`"
            :url="route('staff.handoffs.close', { handoff: closeTarget.id })"
            :title="`Close the chat with ${closeTarget.name}?`"
            description="Record how you replied so the next person can see what was said."
            reason-label="What did you tell them?"
            confirm-label="Close chat"
        />
    </StaffLayout>
</template>
