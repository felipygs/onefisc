<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui';
import { edit as editAppearance } from '@/routes/appearance';
import { index as membersIndex } from '@/routes/members';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

const links = [
    [
        {
            label: 'General',
            icon: 'i-lucide-user',
            to: editProfile.url(),
            exact: true,
        },
        {
            label: 'Members',
            icon: 'i-lucide-users',
            to: membersIndex.url(),
        },
        {
            label: 'Notifications',
            icon: 'i-lucide-bell',
            to: editNotifications.url(),
        },
        {
            label: 'Security',
            icon: 'i-lucide-shield',
            to: editSecurity.url(),
        },
        {
            label: 'Appearance',
            icon: 'i-lucide-palette',
            to: editAppearance.url(),
        },
    ],
] satisfies NavigationMenuItem[][];
</script>

<template>
    <UDashboardPanel id="settings" :ui="{ body: 'lg:py-12' }">
        <template #header>
            <UDashboardNavbar title="Settings">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>
            </UDashboardNavbar>

            <UDashboardToolbar>
                <!-- NOTE: The `-mx-1` class is used to align with the `DashboardSidebarCollapse` button here. -->
                <UNavigationMenu
                    :items="links"
                    highlight
                    class="-mx-1 flex-1"
                />
            </UDashboardToolbar>
        </template>

        <template #body>
            <div
                class="mx-auto flex w-full flex-col gap-4 sm:gap-6 lg:max-w-2xl lg:gap-12"
            >
                <slot />
            </div>
        </template>
    </UDashboardPanel>
</template>
