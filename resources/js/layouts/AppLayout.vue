<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <UApp>
        <AppLayout :breadcrumbs="breadcrumbs">
            <slot />
        </AppLayout>
    </UApp>
</template>
