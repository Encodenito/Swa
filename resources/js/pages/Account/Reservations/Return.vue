<script setup lang="ts">
import AccountLayout from '@/layouts/AccountLayout.vue';
import type { ReservationSummary } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    reservation: ReservationSummary;
}>();

const POLL_MS = 3000;
const GIVE_UP_MS = 2 * 60 * 1000;

const code = computed(() => props.reservation.reference_code);
const breadcrumbs = computed(() => [
    { title: 'My reservations', href: route('account.home') },
    { title: code.value, href: route('account.reservations.show', { reservation: code.value }) },
]);

const timedOut = ref(false);
let timer: ReturnType<typeof setInterval> | null = null;
let startedAt = 0;
let inFlight = false;
let leaving = false;

function stop() {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

function goToReservation() {
    if (leaving) return;
    leaving = true;
    stop();
    router.visit(route('account.reservations.show', { reservation: code.value }));
}

/**
 * Asks the server for the current status. Only the server (via PayMongo's webhook) confirms a payment;
 * this page never assumes success on its own.
 */
async function check() {
    if (Date.now() - startedAt >= GIVE_UP_MS) {
        stop();
        timedOut.value = true;

        return;
    }
    if (inFlight || leaving) return;
    inFlight = true;

    try {
        const response = await fetch(route('account.reservations.status', { reservation: code.value }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) return;

        const data = (await response.json()) as { status?: string };
        if (data.status && data.status !== 'pending_payment') goToReservation();
    } catch {
        // Network hiccup: try again on the next tick.
    } finally {
        inFlight = false;
    }
}

onMounted(() => {
    if (props.reservation.status !== 'pending_payment') {
        goToReservation();

        return;
    }
    startedAt = Date.now();
    timer = setInterval(check, POLL_MS);
});

onBeforeUnmount(stop);
</script>

<template>
    <Head title="Confirming your payment" />

    <AccountLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-lg px-4 py-10">
            <div class="rounded-lg border border-border bg-card p-6 text-center" aria-live="polite" :aria-busy="!timedOut">
                <template v-if="!timedOut">
                    <LoaderCircle class="mx-auto size-10 animate-spin text-primary motion-reduce:animate-none" aria-hidden="true" />
                    <h1 class="mt-4 text-xl font-bold">Confirming your payment…</h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        We're waiting for PayMongo to confirm the downpayment for
                        <span class="font-mono font-semibold text-foreground">{{ code }}</span
                        >. This usually takes a few seconds. Please keep this page open.
                    </p>
                </template>
                <template v-else>
                    <h1 class="text-xl font-bold">Payment not confirmed yet</h1>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Still waiting for PayMongo to confirm. You can leave this page — your reservation updates automatically.
                    </p>
                    <Link
                        :href="route('account.reservations.show', { reservation: code })"
                        class="mt-5 inline-flex h-11 items-center rounded-md bg-primary px-5 font-semibold text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    >
                        View reservation {{ code }}
                    </Link>
                </template>
            </div>
        </div>
    </AccountLayout>
</template>
