<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import AccountLayout from '@/layouts/AccountLayout.vue';
import { Head } from '@inertiajs/vue3';

const breadcrumbs = [{ title: 'My reservations', href: route('account.home') }];
</script>

<template>
    <Head title="My reservations" />

    <AccountLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading title="My reservations" />
            <div class="rounded-lg border border-dashed border-border bg-card p-6 text-sm text-muted-foreground">
                You have no reservations yet. Once the catalog opens, items you reserve appear here with their pickup deadline.
            </div>
        </div>
    </AccountLayout>
</template>
