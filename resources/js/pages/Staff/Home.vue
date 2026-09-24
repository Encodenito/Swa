<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { Head } from '@inertiajs/vue3';

const breadcrumbs = [{ title: 'Staff dashboard', href: route('staff.home') }];
</script>

<template>
    <Head title="Staff dashboard" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading title="Staff dashboard" />
            <div class="rounded-lg border border-dashed border-border bg-card p-6 text-sm text-muted-foreground">
                Pickups due today, reservations about to expire and failed refunds will show here.
            </div>
        </div>
    </StaffLayout>
</template>
