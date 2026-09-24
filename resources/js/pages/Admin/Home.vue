<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import QuickLink from '@/components/shop/staff/QuickLink.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ClipboardList, Cog, FolderTree, PackageCheck, PackageOpen, ScanLine, Tags, Truck } from 'lucide-vue-next';

const breadcrumbs = [{ title: 'Admin dashboard', href: route('admin.home') }];

const catalog = [
    {
        title: 'Items',
        description: 'Add items, change prices and photos, or remove them from the catalog.',
        href: route('admin.items.index'),
        icon: PackageCheck,
    },
    { title: 'Categories', description: 'Group items so shoppers can find them.', href: route('admin.categories.index'), icon: FolderTree },
    { title: 'Suppliers', description: 'Who delivers stock, and how to reach them.', href: route('staff.suppliers.index'), icon: Tags },
    { title: 'Settings', description: 'Downpayment, pickup window, refunds and reservation limits.', href: route('admin.settings.edit'), icon: Cog },
];

const counter = [
    { title: 'Pickup counter', description: 'Hand over reservations and take the balance.', href: route('staff.pickup'), icon: ScanLine },
    { title: 'Reservations', description: 'Search and manage all reservations.', href: route('staff.reservations.index'), icon: ClipboardList },
    { title: 'Stock Receiving', description: 'Record and receive supplier deliveries.', href: route('staff.deliveries.index'), icon: Truck },
    { title: 'Stock', description: 'Batches, adjustments and clearance prices.', href: route('staff.stock.index'), icon: PackageOpen },
];
</script>

<template>
    <Head title="Admin dashboard" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-8 px-4 py-6">
            <Heading title="Admin dashboard" description="Manage the catalog and shop rules, or jump to the counter." />
            <section aria-labelledby="catalog-heading">
                <h3 id="catalog-heading" class="mb-3 text-base font-semibold">Catalog and shop rules</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    <QuickLink v-for="link in catalog" :key="link.href" v-bind="link" />
                </div>
            </section>
            <section aria-labelledby="counter-heading">
                <h3 id="counter-heading" class="mb-3 text-base font-semibold">Counter and stock</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    <QuickLink v-for="link in counter" :key="link.href" v-bind="link" />
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
