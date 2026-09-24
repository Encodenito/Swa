<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';

const breadcrumbs = [{ title: 'Admin dashboard', href: route('admin.home') }];
</script>

<template>
    <Head title="Admin dashboard" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading title="Admin dashboard" />
            <div class="rounded-lg border border-dashed border-border bg-card p-6 text-sm text-muted-foreground">
                Items, prices, suppliers, staff accounts and settings will be managed from here.
            </div>
        </div>
    </AdminLayout>
</template>
