<script setup lang="ts">
import PublicLayout from '@/layouts/PublicLayout.vue';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps<{
    downpaymentPercent: number;
    pickupDays: number;
}>();

const page = usePage<SharedData>();
</script>

<template>
    <Head title="Reserve online, pick up in store" />

    <PublicLayout>
        <section class="grid gap-8 md:grid-cols-[1.1fr_1fr] md:items-center md:gap-12">
            <div>
                <h1 class="text-3xl font-bold leading-tight tracking-tight sm:text-4xl md:text-5xl">Reserve it online. Pick it up at the counter.</h1>
                <p class="mt-4 max-w-prose text-base text-muted-foreground sm:text-lg">
                    Laptops, printers, monitors, ink and cables from SWA I.T. Supplies Center. Hold an item with a small downpayment and collect it at
                    the shop.
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <Link
                        v-if="!page.props.auth.user"
                        :href="route('register')"
                        class="inline-flex h-11 items-center rounded-md bg-primary px-5 font-semibold text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    >
                        Create an account
                    </Link>
                    <p class="self-center text-sm text-muted-foreground">The item catalog opens here soon.</p>
                </div>
            </div>

            <!-- A real sequence, so the steps are numbered. -->
            <ol class="space-y-3" aria-label="How reserving works">
                <li class="flex gap-4 rounded-lg border border-border bg-card p-4">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-secondary font-bold tabular-nums">1</span>
                    <div>
                        <p class="font-semibold">Pick your items</p>
                        <p class="text-sm text-muted-foreground">We hold the stock for you while you pay.</p>
                    </div>
                </li>
                <li class="flex gap-4 rounded-lg border border-border bg-card p-4">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-secondary font-bold tabular-nums">2</span>
                    <div>
                        <p class="font-semibold">
                            Pay a
                            <span class="rounded-sm bg-tag px-1.5 py-0.5 font-bold tabular-nums text-tag-foreground">{{ downpaymentPercent }}%</span>
                            downpayment
                        </p>
                        <p class="text-sm text-muted-foreground">Through GCash or card on PayMongo's secure page.</p>
                    </div>
                </li>
                <li class="flex gap-4 rounded-lg border border-border bg-card p-4">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-secondary font-bold tabular-nums">3</span>
                    <div>
                        <p class="font-semibold">Pick up within {{ pickupDays }} days</p>
                        <p class="text-sm text-muted-foreground">Show your reference code at the counter and pay the balance.</p>
                    </div>
                </li>
            </ol>
        </section>
    </PublicLayout>
</template>
