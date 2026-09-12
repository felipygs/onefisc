<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { getInitials } from '@/composables/useInitials';
import {
    destroy,
    index as switcherIndex,
    select as switcherSelect,
} from '@/routes/switcher';
import type { SwitchableAccount } from '@/types/shell';

defineProps<{
    collapsed?: boolean;
}>();

const page = usePage();

const currentAccount = computed(() => page.props.currentAccount);
const isSwitching = computed(() => page.props.isSwitching === true);
const canManagePlatform = computed(
    () => page.props.permissions?.['manage-platform'] === true,
);
const switchableAccounts = computed<SwitchableAccount[]>(
    () => page.props.switchableAccounts ?? [],
);

function selectAccount(id: number): void {
    if (currentAccount.value?.id !== id) {
        router.post(switcherSelect.url({ account: id }));
    }
}

const items = computed<DropdownMenuItem[][]>(() => {
    const rows: DropdownMenuItem[][] = [];

    if (canManagePlatform.value && switchableAccounts.value.length > 0) {
        rows.push(
            switchableAccounts.value.map((account) => ({
                label: account.name,
                type: 'checkbox' as const,
                checked: account.id === currentAccount.value?.id,
                onSelect() {
                    selectAccount(account.id);
                },
            })),
        );
    }

    rows.push([
        ...(canManagePlatform.value
            ? [
                  {
                      label: 'Gerenciar accounts',
                      icon: 'i-lucide-cog',
                      to: switcherIndex.url(),
                  },
              ]
            : []),
        ...(isSwitching.value
            ? [
                  {
                      label: 'Voltar à origem',
                      icon: 'i-lucide-undo-2',
                      onSelect() {
                          router.delete(destroy.url());
                      },
                  },
              ]
            : []),
    ]);

    return rows;
});
</script>

<template>
    <UDropdownMenu
        :items="items"
        :content="{ align: 'center', collisionPadding: 12 }"
        :ui="{
            content: collapsed
                ? 'w-40'
                : 'w-(--reka-dropdown-menu-trigger-width)',
        }"
    >
        <UButton
            :label="collapsed ? undefined : (currentAccount?.name ?? '—')"
            :avatar="{
                text: getInitials(currentAccount?.name) || '—',
                alt: currentAccount?.name ?? 'Account',
            }"
            :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
            color="neutral"
            variant="ghost"
            block
            :square="collapsed"
            class="data-[state=open]:bg-elevated"
            :class="[!collapsed && 'py-2']"
            :ui="{ trailingIcon: 'text-dimmed' }"
            data-test="teams-menu"
        />
    </UDropdownMenu>
</template>
