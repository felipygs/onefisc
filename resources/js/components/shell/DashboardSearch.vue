<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { index as auditIndex } from '@/routes/audit';
import { index as clientsIndex } from '@/routes/clients';
import { index as documentsIndex } from '@/routes/documents';
import { edit as editProfile } from '@/routes/profile';

const open = defineModel<boolean>('open', { default: false });

const page = usePage();

interface SearchClient {
    id: number;
    razao_social: string;
    cnpj: string;
}

const searchClients = computed<SearchClient[]>(() => {
    if (!canOperateClients.value) {
        return [];
    }

    const clients = page.props.clients as { data?: SearchClient[] } | undefined;

    return clients?.data ?? [];
});

const canOperateClients = computed(
    () => page.props.permissions?.['operate-clients'] === true,
);

function go(url: string): void {
    open.value = false;
    router.visit(url);
}

const groups = computed(() => [
    {
        id: 'links',
        label: 'Ir para',
        items: [
            {
                id: 'go-home',
                label: 'Home',
                icon: 'i-lucide-house',
                onSelect: () => go(dashboard.url()),
            },
            ...(canOperateClients.value
                ? [
                      {
                          id: 'go-clients',
                          label: 'Clients',
                          icon: 'i-lucide-users',
                          onSelect: () => go(clientsIndex.url()),
                      },
                      {
                          id: 'go-documents',
                          label: 'Documentos',
                          icon: 'i-lucide-file-text',
                          onSelect: () => go(documentsIndex.url()),
                      },
                  ]
                : []),
            {
                id: 'go-settings',
                label: 'Ajustes',
                icon: 'i-lucide-settings',
                onSelect: () => go(editProfile.url()),
            },
            {
                id: 'go-audit',
                label: 'Auditoria',
                icon: 'i-lucide-scroll-text',
                onSelect: () => go(auditIndex.url()),
            },
        ],
    },
    ...(searchClients.value.length > 0
        ? [
              {
                  id: 'clients',
                  label: 'Clients',
                  items: searchClients.value.slice(0, 5).map((client) => ({
                      id: `client-${client.id}`,
                      label: client.razao_social,
                      suffix: client.cnpj,
                      icon: 'i-lucide-building-2',
                      onSelect: () =>
                          go(clientsIndex.url() + `?search=${client.cnpj}`),
                  })),
              },
          ]
        : []),
]);
</script>

<template>
    <UDashboardSearch v-model:open="open" :groups="groups" />
</template>
