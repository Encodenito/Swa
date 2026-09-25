<script setup lang="ts">
import EmptyState from '@/components/shop/staff/EmptyState.vue';
import PickupCard from '@/components/shop/staff/PickupCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { ReservationDetail } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{ q: string; results: ReservationDetail[] }>();

const breadcrumbs = [
    { title: 'Staff dashboard', href: route('staff.home') },
    { title: 'Pickup counter', href: route('staff.pickup') },
];

const query = ref(props.q ?? '');
const searching = ref(false);

function search() {
    const q = query.value.trim();
    router.get(route('staff.pickup'), q === '' ? {} : { q }, {
        preserveState: false,
        onStart: () => (searching.value = true),
        onFinish: () => (searching.value = false),
    });
}
</script>

<template>
    <Head title="Pickup counter" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <h1 class="mb-4 text-xl font-semibold tracking-tight">Pickup counter</h1>

            <form role="search" class="mb-8" @submit.prevent="search">
                <Label for="pickup-search" class="text-base">Reference code, mobile number or email</Label>
                <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                    <div class="relative flex-1">
                        <Search
                            class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            id="pickup-search"
                            v-model="query"
                            type="search"
                            autofocus
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                            placeholder="SWA-7K2P9QXM, 0917 123 4567 or juan@example.com"
                            class="h-14 pl-12 text-lg md:text-lg"
                        />
                    </div>
                    <Button type="submit" size="lg" class="h-14 px-8 text-base" :disabled="searching">
                        {{ searching ? 'Searching…' : 'Find' }}
                    </Button>
                </div>
            </form>

            <div :aria-busy="searching" aria-live="polite">
                <div v-if="q === ''" class="max-w-prose rounded-lg border border-dashed border-border bg-card p-6 text-sm text-muted-foreground">
                    <p class="font-medium text-foreground">How to hand over a reservation</p>
                    <ol class="mt-2 list-decimal space-y-1 pl-5">
                        <li>Ask for the reference code from the confirmation email, or the mobile number or email on the account.</li>
                        <li>Check the items and the customer against the account. Check an ID for high-value items.</li>
                        <li>Take the balance due, type the amount received and complete the pickup.</li>
                    </ol>
                </div>

                <EmptyState
                    v-else-if="results.length === 0"
                    :title="`No reservation awaiting pickup matches “${q}”`"
                    description="Check the spelling, or use the exact email or mobile number on the account. Reservations still unpaid, already picked up or cancelled do not appear here."
                />

                <div v-else class="space-y-6">
                    <p class="text-sm text-muted-foreground">
                        {{ results.length === 1 ? '1 reservation' : `${results.length} reservations` }} awaiting pickup, earliest deadline first.
                    </p>
                    <PickupCard v-for="r in results" :key="r.reference_code" :reservation="r" />
                </div>
            </div>
        </div>
    </StaffLayout>
</template>
