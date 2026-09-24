<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { Area } from '@/lib/navigation';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
    area?: Area;
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    area: undefined,
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :area="area">
        <slot />
    </AppLayout>
</template>
