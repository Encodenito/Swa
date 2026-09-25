<script setup lang="ts">
import ChatWidget from '@/components/shop/chat/ChatWidget.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItemType[] }>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppLayout area="account" :breadcrumbs="breadcrumbs">
        <slot />
        <ChatWidget />
    </AppLayout>
</template>
