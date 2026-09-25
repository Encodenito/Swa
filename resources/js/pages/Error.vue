<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Friendly error page. Standalone (no layout): it must render even when the request never reached
 * the app's middleware, so it relies on no shared props.
 */
const props = defineProps<{ status: 403 | 404 | 500 | 503 }>();

const copy = computed(
    () =>
        ({
            403: { title: 'You can’t open this page', body: 'Your account doesn’t have access here. If you think it should, ask the shop.' },
            404: { title: 'Page not found', body: 'The link may be old or mistyped. The item may also have been removed from the catalog.' },
            500: { title: 'Something went wrong on our side', body: 'Nothing was charged because of this error. Please try again in a moment.' },
            503: { title: 'We’ll be right back', body: 'The shop site is being updated. Please try again in a few minutes.' },
        })[props.status],
);
</script>

<template>
    <Head :title="copy.title" />

    <main class="flex min-h-svh items-center justify-center bg-background px-4 py-10 text-foreground">
        <div class="w-full max-w-md text-center">
            <a
                href="/"
                class="mb-8 inline-flex items-center gap-2 rounded-md font-bold tracking-tight focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <AppLogoIcon class="size-8" />
                SWA I.T. Supplies
            </a>
            <p class="text-sm font-semibold tabular-nums text-muted-foreground">Error {{ status }}</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight">{{ copy.title }}</h1>
            <p class="mt-3 text-muted-foreground">{{ copy.body }}</p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a
                    href="/items"
                    class="inline-flex h-11 items-center justify-center rounded-md bg-primary px-5 font-semibold text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                >
                    Browse items
                </a>
                <a
                    href="/"
                    class="inline-flex h-11 items-center justify-center rounded-md border border-border bg-card px-5 font-semibold hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    Go to the home page
                </a>
            </div>
        </div>
    </main>
</template>
