<script setup lang="ts">
import AccountSwitcher from '@/components/AccountSwitcher.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <UApp>
        <AccountSwitcher />
        <AppLayout :breadcrumbs="breadcrumbs">
            <slot />
        </AppLayout>
    </UApp>
</template>
