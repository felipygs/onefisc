<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui';
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AccountSwitcher from '@/components/AccountSwitcher.vue';
import CookieConsent from '@/components/shell/CookieConsent.vue';
import DashboardSearch from '@/components/shell/DashboardSearch.vue';
import NotificationsSlideover from '@/components/shell/NotificationsSlideover.vue';
import TeamsMenu from '@/components/shell/TeamsMenu.vue';
import UserMenu from '@/components/shell/UserMenu.vue';
import { setupDashboardShortcuts } from '@/composables/useDashboard';
import { dashboard } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { index as clientsIndex } from '@/routes/clients';
import { index as documentsIndex } from '@/routes/documents';
import { index as membersIndex } from '@/routes/members';
import { edit as editNotifications } from '@/routes/notifications';
import { overview as workOverview } from '@/routes/work';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { BreadcrumbItem } from '@/types';

defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

const open = ref(false);
const searchOpen = ref(false);

const page = usePage();

const canOperateClients = computed(
    () => page.props.permissions?.['operate-clients'] === true,
);
const canManageUsers = computed(
    () => page.props.permissions?.['manage-users'] === true,
);
// Work é visível a todo membro autenticado (admin/operador/user): o
// isolamento de `user` sem atribuições resolve nas props filtradas,
// com o estado vazio honesto "no clients assigned".
const canViewWork = computed(() => page.props.auth?.user != null);

const links = computed<NavigationMenuItem[][]>(() => [
    [
        {
            label: 'Home',
            icon: 'i-lucide-house',
            to: dashboard.url(),
            onSelect: () => {
                open.value = false;
            },
        },
        ...(canOperateClients.value
            ? [
                  {
                      label: 'Clients',
                      icon: 'i-lucide-users',
                      to: clientsIndex.url(),
                      onSelect: () => {
                          open.value = false;
                      },
                  },
                  {
                      label: 'Documentos',
                      icon: 'i-lucide-file-text',
                      to: documentsIndex.url(),
                      onSelect: () => {
                          open.value = false;
                      },
                  },
              ]
            : []),
        ...(canViewWork.value
            ? [
                  {
                      label: 'Work',
                      icon: 'i-lucide-briefcase',
                      to: workOverview.url(),
                      onSelect: () => {
                          open.value = false;
                      },
                  },
              ]
            : []),
        {
            label: 'Settings',
            to: editProfile.url(),
            icon: 'i-lucide-settings',
            defaultOpen: true,
            type: 'trigger',
            children: [
                {
                    label: 'General',
                    to: editProfile.url(),
                    exact: true,
                    onSelect: () => {
                        open.value = false;
                    },
                },
                ...(canManageUsers.value
                    ? [
                          {
                              label: 'Members',
                              to: membersIndex.url(),
                              onSelect: () => {
                                  open.value = false;
                              },
                          },
                      ]
                    : []),
                {
                    label: 'Notifications',
                    to: editNotifications.url(),
                    onSelect: () => {
                        open.value = false;
                    },
                },
                {
                    label: 'Security',
                    to: editSecurity.url(),
                    onSelect: () => {
                        open.value = false;
                    },
                },
                {
                    label: 'Appearance',
                    to: editAppearance.url(),
                    onSelect: () => {
                        open.value = false;
                    },
                },
            ],
        },
    ],
    [
        {
            label: 'Feedback',
            icon: 'i-lucide-message-circle',
            to: 'https://github.com/laravel/vue-starter-kit',
            target: '_blank',
        },
        {
            label: 'Help & Support',
            icon: 'i-lucide-info',
            to: 'https://laravel.com/docs/starter-kits#vue',
            target: '_blank',
        },
    ],
]);

let cleanupShortcuts: (() => void) | undefined;

onMounted(() => {
    cleanupShortcuts = setupDashboardShortcuts();
});

onUnmounted(() => {
    cleanupShortcuts?.();
});
</script>

<template>
    <UApp>
        <AccountSwitcher />
        <UDashboardGroup unit="rem">
            <UDashboardSidebar
                id="default"
                v-model:open="open"
                collapsible
                resizable
                class="bg-elevated/25"
                :ui="{ footer: 'lg:border-t lg:border-default' }"
            >
                <template #header="{ collapsed }">
                    <TeamsMenu :collapsed="collapsed" />
                </template>

                <template #default="{ collapsed }">
                    <UDashboardSearchButton
                        :collapsed="collapsed"
                        class="ring-default bg-transparent"
                        @click="searchOpen = true"
                    />

                    <UNavigationMenu
                        :collapsed="collapsed"
                        :items="links[0]"
                        orientation="vertical"
                        tooltip
                        popover
                    />

                    <UNavigationMenu
                        :collapsed="collapsed"
                        :items="links[1]"
                        orientation="vertical"
                        tooltip
                        class="mt-auto"
                    />
                </template>

                <template #footer="{ collapsed }">
                    <UserMenu :collapsed="collapsed" />
                </template>
            </UDashboardSidebar>

            <DashboardSearch v-model:open="searchOpen" />

            <slot />

            <NotificationsSlideover />
            <CookieConsent />
        </UDashboardGroup>
    </UApp>
</template>
