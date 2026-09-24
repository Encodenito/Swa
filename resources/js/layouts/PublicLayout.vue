<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage<SharedData>();
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-card focus:px-3 focus:py-2"
        >
            Skip to content
        </a>

        <header class="sticky top-0 z-40 border-b border-border bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/80">
            <div class="mx-auto flex h-14 max-w-6xl items-center gap-3 px-4">
                <Link
                    :href="route('home')"
                    class="flex min-w-0 items-center gap-2 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <AppLogoIcon class="size-8 shrink-0" />
                    <span class="hidden truncate font-bold tracking-tight sm:inline">SWA I.T. Supplies</span>
                    <span class="sr-only sm:hidden">SWA I.T. Supplies home</span>
                </Link>

                <nav aria-label="Main" class="ml-auto flex items-center gap-1 text-sm">
                    <template v-if="page.props.auth.user">
                        <Link
                            :href="route('dashboard')"
                            class="inline-flex h-10 items-center whitespace-nowrap rounded-md bg-primary px-4 font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            {{ page.props.auth.user.role === 'customer' ? 'My reservations' : 'Open dashboard' }}
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="inline-flex h-10 items-center whitespace-nowrap rounded-md px-3 font-medium hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="route('register')"
                            class="inline-flex h-10 items-center whitespace-nowrap rounded-md bg-primary px-4 font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            Create account
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main id="main" class="mx-auto w-full max-w-6xl flex-1 px-4 py-6 md:py-10">
            <slot />
        </main>

        <footer class="border-t border-border bg-card">
            <div class="mx-auto grid max-w-6xl gap-4 px-4 py-8 text-sm text-muted-foreground sm:grid-cols-2">
                <div>
                    <p class="font-semibold text-foreground">SWA I.T. Supplies Center</p>
                    <p>Pickup only. We don't deliver.</p>
                </div>
                <p class="sm:text-right">Prices in Philippine pesos (₱). Reserve online with a downpayment, pay the balance at the counter.</p>
            </div>
        </footer>
    </div>
</template>
