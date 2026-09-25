<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/shop/Pagination.vue';
import Chip from '@/components/shop/admin/Chip.vue';
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import NativeSelect from '@/components/shop/staff/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { formatDateTime } from '@/lib/dates';
import type { Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Info } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Channel = 'mail' | 'sms';

interface MessageRow {
    id: number;
    channel: Channel;
    notification: string;
    recipient: string;
    subject: string | null;
    sent_at: string | null;
    user: { id: number; name: string } | null;
    reference_code: string | null;
}

const props = defineProps<{
    messages: Paginated<MessageRow>;
    filters: { channel: Channel | null; notification: string | null };
    notifications: string[];
}>();

const breadcrumbs = [
    { title: 'Admin dashboard', href: route('admin.home') },
    { title: 'Messages sent', href: route('admin.messages') },
];

const channelLabel: Record<Channel, string> = { mail: 'Email', sms: 'SMS' };

/** "ReservationConfirmed" → "Reservation confirmed". */
function notificationLabel(name: string): string {
    const words = name
        .replace(/Notification$/, '')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/[_-]+/g, ' ')
        .trim()
        .toLowerCase();

    return words ? words.charAt(0).toUpperCase() + words.slice(1) : name;
}

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

const channel = ref<string>(props.filters.channel ?? '');
const notification = ref<string>(props.filters.notification ?? '');
const loading = ref(false);

const hasFilters = computed(() => !!(props.filters.channel || props.filters.notification));

function apply() {
    const params: Record<string, string> = {};
    if (channel.value) params.channel = channel.value;
    if (notification.value) params.notification = notification.value;

    router.get(route('admin.messages'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

function clear() {
    channel.value = '';
    notification.value = '';
    apply();
}
</script>

<template>
    <Head title="Messages sent" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading title="Messages sent" description="Emails and text messages the shop sent to customers, newest first." />

            <p class="mb-4 flex items-start gap-2 rounded-md border border-border bg-muted/50 px-3 py-2 text-sm">
                <Info class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                Only the subject and a masked address are kept; message contents are not stored.
            </p>

            <form
                class="mb-4 grid gap-3 rounded-lg border border-border bg-card p-4 sm:grid-cols-[10rem_1fr_auto] sm:items-end"
                @submit.prevent="apply"
            >
                <div class="grid gap-1.5">
                    <Label for="messages-channel">Channel</Label>
                    <NativeSelect
                        id="messages-channel"
                        v-model="channel"
                        :aria-invalid="!!errors.channel || undefined"
                        aria-describedby="messages-channel-error"
                        @change="apply"
                    >
                        <option value="">All channels</option>
                        <option value="mail">Email</option>
                        <option value="sms">SMS</option>
                    </NativeSelect>
                    <InputError id="messages-channel-error" :message="errors.channel" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="messages-notification">Message type</Label>
                    <NativeSelect
                        id="messages-notification"
                        v-model="notification"
                        :aria-invalid="!!errors.notification || undefined"
                        aria-describedby="messages-notification-error"
                        @change="apply"
                    >
                        <option value="">All types</option>
                        <option v-for="n in notifications" :key="n" :value="n">{{ notificationLabel(n) }}</option>
                    </NativeSelect>
                    <InputError id="messages-notification-error" :message="errors.notification" />
                </div>
                <div class="flex gap-2">
                    <Button type="submit" class="h-10 flex-1 sm:flex-none" :disabled="loading">{{ loading ? 'Loading…' : 'Filter' }}</Button>
                    <Button v-if="hasFilters" type="button" variant="outline" class="h-10" :disabled="loading" @click="clear">Clear</Button>
                </div>
            </form>

            <div :class="['transition-opacity', loading ? 'opacity-60' : '']" :aria-busy="loading">
                <EmptyState
                    v-if="messages.data.length === 0"
                    :title="hasFilters ? 'No messages match these filters.' : 'No messages sent yet.'"
                    :description="
                        hasFilters
                            ? 'Try another channel or message type.'
                            : 'Reservation confirmations, reminders and refund notices will be listed here once they go out.'
                    "
                />

                <template v-else>
                    <p class="mb-2 text-sm text-muted-foreground">Showing {{ messages.from }}–{{ messages.to }} of {{ messages.total }}</p>

                    <div class="overflow-hidden rounded-lg border border-border">
                        <table class="w-full text-sm">
                            <thead class="hidden bg-muted/50 text-left text-xs text-muted-foreground md:table-header-group">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Sent</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Message</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">To</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Customer</th>
                                    <th scope="col" class="px-4 py-2.5 font-medium">Reservation</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="m in messages.data" :key="m.id" class="grid gap-1 p-4 md:table-row md:p-0">
                                    <td class="text-muted-foreground md:whitespace-nowrap md:px-4 md:py-3 md:align-top">
                                        <time :datetime="m.sent_at ?? undefined">{{ formatDateTime(m.sent_at) }}</time>
                                    </td>
                                    <td class="md:px-4 md:py-3 md:align-top">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <Chip :tone="m.channel === 'sms' ? 'accent' : 'info'">{{ channelLabel[m.channel] ?? m.channel }}</Chip>
                                            <span class="font-medium">{{ notificationLabel(m.notification) }}</span>
                                        </div>
                                        <p v-if="m.subject" class="mt-1 break-words text-muted-foreground">{{ m.subject }}</p>
                                    </td>
                                    <td class="break-all font-mono text-xs md:px-4 md:py-3 md:align-top">
                                        <span class="font-sans text-muted-foreground md:hidden">To </span>{{ m.recipient }}
                                    </td>
                                    <td class="md:px-4 md:py-3 md:align-top">
                                        <span class="text-muted-foreground md:hidden">Customer: </span>
                                        <Link
                                            v-if="m.user"
                                            :href="route('staff.customers.show', { customer: m.user.id })"
                                            class="font-medium underline underline-offset-4 hover:text-primary"
                                            >{{ m.user.name }}</Link
                                        >
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="md:px-4 md:py-3 md:align-top">
                                        <span class="text-muted-foreground md:hidden">Reservation: </span>
                                        <Link
                                            v-if="m.reference_code"
                                            :href="route('staff.reservations.show', { reservation: m.reference_code })"
                                            class="font-mono font-semibold underline underline-offset-4 hover:text-primary"
                                            >{{ m.reference_code }}</Link
                                        >
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <Pagination :links="messages.links" />
                </template>
            </div>
        </div>
    </AdminLayout>
</template>
