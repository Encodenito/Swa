<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import QuickLink from '@/components/shop/staff/QuickLink.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ClipboardList, PackageOpen, ScanLine, Truck } from 'lucide-vue-next';

const breadcrumbs = [{ title: 'Staff dashboard', href: route('staff.home') }];

const links = [
    {
        title: 'Pickup counter',
        description: 'Find a reservation by reference, mobile number or email and hand it over.',
        href: route('staff.pickup'),
        icon: ScanLine,
    },
    {
        title: 'Reservations',
        description: 'Search all reservations, start preparing orders and extend pickup deadlines.',
        href: route('staff.reservations.index'),
        icon: ClipboardList,
    },
    {
        title: 'Stock Receiving',
        description: 'Record supplier deliveries and receive them into stock.',
        href: route('staff.deliveries.index'),
        icon: Truck,
    },
    {
        title: 'Stock',
        description: 'Check what is on the shelf, batch by batch, and record damaged units.',
        href: route('staff.stock.index'),
        icon: PackageOpen,
    },
];
</script>

<template>
    <Head title="Staff dashboard" />

    <StaffLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading title="Staff dashboard" description="Where to start at the counter." />
            <nav aria-label="Staff tasks" class="grid gap-3 sm:grid-cols-2">
                <QuickLink v-for="link in links" :key="link.href" v-bind="link" />
            </nav>
        </div>
    </StaffLayout>
</template>
