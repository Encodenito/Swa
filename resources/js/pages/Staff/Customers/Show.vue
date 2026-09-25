<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/shop/StatusBadge.vue';
import Chip from '@/components/shop/admin/Chip.vue';
import FollowUpItem from '@/components/shop/crm/FollowUpItem.vue';
import TagChip from '@/components/shop/crm/TagChip.vue';
import { channelLabel, humanizeNotification, manilaToday, TAG_COLOURS, type FollowUpRow, type TagRef } from '@/components/shop/crm/crm';
import ConfirmDialog from '@/components/shop/staff/ConfirmDialog.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import NativeSelect from '@/components/shop/staff/NativeSelect.vue';
import TextArea from '@/components/shop/staff/TextArea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { formatDate, formatDateTime } from '@/lib/dates';
import { formatPeso } from '@/lib/money';
import type { ReservationSummary } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Mail, Phone, Trash2, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Note {
    id: number;
    body: string;
    author: string | null;
    created_at: string | null;
    can_delete: boolean;
}

interface MessageRow {
    id: number;
    channel: string;
    notification: string;
    recipient: string | null;
    subject: string | null;
    sent_at: string | null;
}

const props = defineProps<{
    customer: {
        id: number;
        name: string;
        email: string;
        phone: string | null;
        verified: boolean;
        active: boolean;
        created_at: string | null;
        tags: Required<TagRef>[];
    };
    stats: { reservations: number; completed: number; cancelled: number; forfeited: number; spent: string };
    reservations: ReservationSummary[];
    notes: Note[];
    followUps: FollowUpRow[];
    messages: MessageRow[];
    allTags: Required<TagRef>[];
    staff: { id: number; name: string }[];
    can: { createTag: boolean };
}>();

const breadcrumbs = computed(() => [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Customers', href: route('staff.customers.index') },
    { title: props.customer.name, href: route('staff.customers.show', { customer: props.customer.id }) },
]);

const statCards = computed(() => [
    { label: 'Reservations', value: String(props.stats.reservations) },
    { label: 'Completed', value: String(props.stats.completed) },
    { label: 'Cancelled', value: String(props.stats.cancelled) },
    { label: 'Forfeited', value: String(props.stats.forfeited) },
    { label: 'Spent (completed)', value: formatPeso(props.stats.spent) },
]);

// Tags
const availableTags = computed(() => {
    const current = new Set(props.customer.tags.map((t) => t.id));
    return props.allTags.filter((t) => !current.has(t.id));
});
const addTagId = ref<string>('');
const tagBusy = ref(false);

function attachTag() {
    if (addTagId.value === '') return;
    router.post(
        route('staff.customers.tags.attach', { customer: props.customer.id, tag: Number(addTagId.value) }),
        {},
        {
            preserveScroll: true,
            onStart: () => (tagBusy.value = true),
            onFinish: () => (tagBusy.value = false),
            onSuccess: () => (addTagId.value = ''),
        },
    );
}

function detachTag(tagId: number) {
    router.delete(route('staff.customers.tags.detach', { customer: props.customer.id, tag: tagId }), {
        preserveScroll: true,
        onStart: () => (tagBusy.value = true),
        onFinish: () => (tagBusy.value = false),
    });
}

const tagForm = useForm({ name: '', colour: 'neutral' });

function createTag() {
    tagForm.post(route('admin.tags.store'), {
        preserveScroll: true,
        onSuccess: () => tagForm.reset(),
    });
}

// Notes
const noteForm = useForm({ body: '' });
const sortedNotes = computed(() => [...props.notes].sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '')));

function addNote() {
    noteForm.post(route('staff.customers.notes.store', { customer: props.customer.id }), {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
}

const deleteTarget = ref<Note | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);

function askDelete(note: Note) {
    deleteTarget.value = note;
    deleteOpen.value = true;
}

function confirmDelete() {
    if (!deleteTarget.value) return;
    router.delete(route('staff.notes.destroy', { note: deleteTarget.value.id }), {
        preserveScroll: true,
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
        onSuccess: () => (deleteOpen.value = false),
    });
}

// Follow-ups
const today = manilaToday();
const followUpForm = useForm({ due_on: '', note: '', assigned_to: '' as string | number });

function addFollowUp() {
    followUpForm
        .transform((data) => ({ ...data, assigned_to: data.assigned_to === '' ? null : Number(data.assigned_to) }))
        .post(route('staff.customers.follow-ups.store', { customer: props.customer.id }), {
            preserveScroll: true,
            onSuccess: () => followUpForm.reset(),
        });
}

// Reservations
function reservationHref(r: ReservationSummary): string {
    return r.type === 'walk_in'
        ? route('staff.walk-ins.show', { reservation: r.reference_code })
        : route('staff.reservations.show', { reservation: r.reference_code });
}
</script>

<template>
    <Head :title="customer.name" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 px-4 py-6">
            <!-- Header -->
            <header class="space-y-2">
                <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">{{ customer.name }}</h1>
                <div class="flex flex-wrap gap-1.5">
                    <Chip :tone="customer.verified ? 'info' : 'warning'">{{ customer.verified ? 'Email verified' : 'Email not verified' }}</Chip>
                    <Chip :tone="customer.active ? 'neutral' : 'danger'">{{ customer.active ? 'Active' : 'Deactivated' }}</Chip>
                </div>
                <ul class="flex flex-col gap-1 text-sm sm:flex-row sm:flex-wrap sm:gap-x-5">
                    <li>
                        <a
                            :href="`mailto:${encodeURIComponent(customer.email)}`"
                            class="inline-flex min-h-9 items-center gap-2 break-all underline-offset-4 hover:underline"
                        >
                            <Mail class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            {{ customer.email }}
                        </a>
                    </li>
                    <li v-if="customer.phone">
                        <a :href="`tel:${customer.phone}`" class="inline-flex min-h-9 items-center gap-2 underline-offset-4 hover:underline">
                            <Phone class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            {{ customer.phone }}
                        </a>
                    </li>
                    <li v-if="customer.created_at" class="inline-flex min-h-9 items-center text-muted-foreground">
                        Customer since {{ formatDate(customer.created_at) }}
                    </li>
                </ul>
            </header>

            <!-- Stats -->
            <section aria-label="Customer history">
                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <div
                        v-for="(s, i) in statCards"
                        :key="s.label"
                        :class="['rounded-lg border border-border bg-card p-3', i === statCards.length - 1 ? 'col-span-2 sm:col-span-1' : '']"
                    >
                        <dt class="text-xs text-muted-foreground">{{ s.label }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums">{{ s.value }}</dd>
                    </div>
                </dl>
            </section>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <div class="space-y-6">
                    <!-- Follow-ups -->
                    <section aria-labelledby="followups-heading" class="rounded-lg border border-border bg-card">
                        <div class="border-b border-border p-4">
                            <h2 id="followups-heading" class="font-semibold">Follow-ups</h2>
                        </div>
                        <form class="grid gap-3 border-b border-border p-4 sm:grid-cols-2" @submit.prevent="addFollowUp">
                            <div class="grid gap-1.5">
                                <Label for="followup-due">Due on</Label>
                                <Input
                                    id="followup-due"
                                    v-model="followUpForm.due_on"
                                    type="date"
                                    :min="today"
                                    required
                                    :aria-invalid="!!followUpForm.errors.due_on"
                                    aria-describedby="followup-due-error"
                                />
                                <InputError id="followup-due-error" :message="followUpForm.errors.due_on" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="followup-assignee">Assign to (optional)</Label>
                                <NativeSelect id="followup-assignee" v-model="followUpForm.assigned_to" aria-describedby="followup-assignee-error">
                                    <option value="">Anyone</option>
                                    <option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </NativeSelect>
                                <InputError id="followup-assignee-error" :message="followUpForm.errors.assigned_to" />
                            </div>
                            <div class="grid gap-1.5 sm:col-span-2">
                                <Label for="followup-note">What to do</Label>
                                <TextArea
                                    id="followup-note"
                                    v-model="followUpForm.note"
                                    rows="2"
                                    maxlength="500"
                                    required
                                    placeholder="e.g. Call to confirm the pickup date"
                                    :aria-invalid="!!followUpForm.errors.note"
                                    aria-describedby="followup-note-error"
                                />
                                <InputError id="followup-note-error" :message="followUpForm.errors.note" />
                            </div>
                            <div class="sm:col-span-2">
                                <Button type="submit" class="h-10 w-full sm:w-auto" :disabled="followUpForm.processing">
                                    {{ followUpForm.processing ? 'Adding…' : 'Add follow-up' }}
                                </Button>
                            </div>
                        </form>
                        <p v-if="followUps.length === 0" class="p-4 text-sm text-muted-foreground">No follow-ups for this customer.</p>
                        <ul v-else class="divide-y divide-border">
                            <li v-for="f in followUps" :key="f.id">
                                <FollowUpItem :follow-up="f" />
                            </li>
                        </ul>
                    </section>

                    <!-- Notes -->
                    <section aria-labelledby="notes-heading" class="rounded-lg border border-border bg-card">
                        <div class="border-b border-border p-4">
                            <h2 id="notes-heading" class="font-semibold">Notes</h2>
                        </div>
                        <form class="grid gap-2 border-b border-border p-4" @submit.prevent="addNote">
                            <Label for="note-body">New note</Label>
                            <TextArea
                                id="note-body"
                                v-model="noteForm.body"
                                rows="3"
                                maxlength="2000"
                                required
                                :aria-invalid="!!noteForm.errors.body"
                                aria-describedby="note-body-help note-body-error"
                            />
                            <p id="note-body-help" class="text-xs text-muted-foreground">
                                Staff only — the customer never sees notes. Do not write card numbers or ID numbers.
                            </p>
                            <InputError id="note-body-error" :message="noteForm.errors.body" />
                            <div>
                                <Button type="submit" class="h-10 w-full sm:w-auto" :disabled="noteForm.processing">
                                    {{ noteForm.processing ? 'Saving…' : 'Add note' }}
                                </Button>
                            </div>
                        </form>
                        <p v-if="sortedNotes.length === 0" class="p-4 text-sm text-muted-foreground">No notes yet.</p>
                        <ul v-else class="divide-y divide-border">
                            <li v-for="n in sortedNotes" :key="n.id" class="flex items-start justify-between gap-3 p-4">
                                <div class="min-w-0">
                                    <p class="whitespace-pre-line break-words text-sm">{{ n.body }}</p>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        {{ n.author ?? 'A former staff member' }} · {{ formatDateTime(n.created_at) }}
                                    </p>
                                </div>
                                <Button
                                    v-if="n.can_delete"
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="size-9 shrink-0"
                                    :aria-label="`Delete note from ${formatDateTime(n.created_at)}`"
                                    @click="askDelete(n)"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </Button>
                            </li>
                        </ul>
                    </section>
                </div>

                <div class="space-y-6">
                    <!-- Tags -->
                    <section aria-labelledby="tags-heading" class="space-y-4 rounded-lg border border-border bg-card p-4">
                        <h2 id="tags-heading" class="font-semibold">Tags</h2>
                        <p v-if="customer.tags.length === 0" class="text-sm text-muted-foreground">No tags yet.</p>
                        <ul v-else class="flex flex-wrap gap-2">
                            <li v-for="t in customer.tags" :key="t.id" class="inline-flex items-center gap-0.5">
                                <TagChip :name="t.name" :colour="t.colour" />
                                <button
                                    type="button"
                                    class="inline-flex size-8 items-center justify-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                                    :aria-label="`Remove tag ${t.name}`"
                                    :disabled="tagBusy"
                                    @click="detachTag(t.id)"
                                >
                                    <X class="size-4" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>

                        <form v-if="availableTags.length > 0" class="flex items-end gap-2" @submit.prevent="attachTag">
                            <div class="grid flex-1 gap-1.5">
                                <Label for="add-tag">Add tag</Label>
                                <NativeSelect id="add-tag" v-model="addTagId">
                                    <option value="">Choose a tag</option>
                                    <option v-for="t in availableTags" :key="t.id" :value="String(t.id)">{{ t.name }}</option>
                                </NativeSelect>
                            </div>
                            <Button type="submit" variant="outline" class="h-10" :disabled="tagBusy || addTagId === ''">Add</Button>
                        </form>
                        <p v-else-if="allTags.length === 0" class="text-sm text-muted-foreground">No tags exist yet.</p>

                        <form v-if="can.createTag" class="space-y-3 border-t border-border pt-4" @submit.prevent="createTag">
                            <h3 class="text-sm font-medium">New tag</h3>
                            <div class="grid grid-cols-[1fr_8rem] gap-2">
                                <div class="grid gap-1.5">
                                    <Label for="tag-name">Name</Label>
                                    <Input
                                        id="tag-name"
                                        v-model="tagForm.name"
                                        maxlength="50"
                                        required
                                        autocomplete="off"
                                        :aria-invalid="!!(tagForm.errors.name || (tagForm.errors as Record<string, string>).slug)"
                                        aria-describedby="tag-name-error"
                                    />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="tag-colour">Colour</Label>
                                    <NativeSelect id="tag-colour" v-model="tagForm.colour" aria-describedby="tag-colour-error">
                                        <option v-for="c in TAG_COLOURS" :key="c.value" :value="c.value">{{ c.label }}</option>
                                    </NativeSelect>
                                </div>
                            </div>
                            <InputError id="tag-name-error" :message="tagForm.errors.name ?? (tagForm.errors as Record<string, string>).slug" />
                            <InputError id="tag-colour-error" :message="tagForm.errors.colour" />
                            <Button type="submit" variant="outline" class="h-10 w-full sm:w-auto" :disabled="tagForm.processing">
                                {{ tagForm.processing ? 'Creating…' : 'Create tag' }}
                            </Button>
                        </form>
                    </section>

                    <!-- Reservations -->
                    <section aria-labelledby="reservations-heading" class="rounded-lg border border-border bg-card">
                        <div class="border-b border-border p-4">
                            <h2 id="reservations-heading" class="font-semibold">Reservations</h2>
                        </div>
                        <div v-if="reservations.length === 0" class="p-4">
                            <EmptyState title="No reservations yet" description="This customer has not placed a reservation." />
                        </div>
                        <ul v-else class="divide-y divide-border">
                            <li v-for="r in reservations" :key="r.reference_code">
                                <Link :href="reservationHref(r)" class="block space-y-1 p-4 hover:bg-accent/50">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="font-mono text-sm font-semibold">{{ r.reference_code }}</span>
                                        <StatusBadge :status="r.status" :label="r.status_label" />
                                    </span>
                                    <span class="flex justify-between gap-2 text-sm text-muted-foreground">
                                        <span>{{ formatDate(r.created_at) }}<template v-if="r.type === 'walk_in'"> · Walk-in sale</template></span>
                                        <span class="font-medium tabular-nums text-foreground">{{ formatPeso(r.total) }}</span>
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </section>

                    <!-- Messages sent -->
                    <section aria-labelledby="messages-heading" class="rounded-lg border border-border bg-card">
                        <div class="border-b border-border p-4">
                            <h2 id="messages-heading" class="font-semibold">Messages sent</h2>
                            <p class="mt-0.5 text-xs text-muted-foreground">The last 30 emails and texts. Contents are not stored.</p>
                        </div>
                        <p v-if="messages.length === 0" class="p-4 text-sm text-muted-foreground">No messages sent yet.</p>
                        <ul v-else class="divide-y divide-border">
                            <li v-for="m in messages" :key="m.id" class="space-y-0.5 px-4 py-3 text-sm">
                                <p class="flex items-center justify-between gap-2">
                                    <span class="font-medium">{{ humanizeNotification(m.notification) }}</span>
                                    <Chip tone="neutral" class="shrink-0">{{ channelLabel(m.channel) }}</Chip>
                                </p>
                                <p v-if="m.subject" class="break-words text-muted-foreground">{{ m.subject }}</p>
                                <p class="text-xs text-muted-foreground">
                                    <template v-if="m.recipient">To {{ m.recipient }} · </template>{{ formatDateTime(m.sent_at) }}
                                </p>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>

        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Delete this note?"
            description="The note is removed for all staff. This is recorded in the audit log."
            confirm-label="Delete note"
            destructive
            :processing="deleting"
            @confirm="confirmDelete"
        >
            <p v-if="deleteTarget" class="line-clamp-4 whitespace-pre-line break-words rounded-md bg-muted p-3 text-sm">{{ deleteTarget.body }}</p>
        </ConfirmDialog>
    </StaffLayout>
</template>
