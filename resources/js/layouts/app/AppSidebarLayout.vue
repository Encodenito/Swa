<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import FlashMessages from '@/components/shop/FlashMessages.vue';
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
    <AppShell variant="sidebar">
        <!-- Keyboard users skip the sidebar and breadcrumbs (about a dozen links) on every page. -->
        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-card focus:px-3 focus:py-2 focus:shadow-md focus:outline-none focus:ring-2 focus:ring-ring"
        >
            Skip to content
        </a>
        <AppSidebar :area="area" />
        <AppContent variant="sidebar">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <span id="main-content" tabindex="-1" class="sr-only">Page content</span>
            <FlashMessages class="px-4 pt-4" />
            <slot />
        </AppContent>
    </AppShell>
</template>
