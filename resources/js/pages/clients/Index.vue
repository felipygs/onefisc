<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui';
import type { Column, Row, Table } from '@tanstack/table-core';
import { Link, router, usePage } from '@inertiajs/vue3';
import { upperFirst } from 'scule';
import { computed, h, ref, resolveComponent, useTemplateRef } from 'vue';
import PlanLimitWarning from '@/components/PlanLimitWarning.vue';
import {
    bulkDestroy as bulkDestroyClients,
    create,
    destroy as destroyClient,
    index as clientsIndex,
    show,
} from '@/routes/clients';

const UAvatar = resolveComponent('UAvatar');
const UButton = resolveComponent('UButton');
const UBadge = resolveComponent('UBadge');
const UDropdownMenu = resolveComponent('UDropdownMenu');
const UCheckbox = resolveComponent('UCheckbox');

interface ClientRow {
    id: number;
    cnpj: string;
    razao_social: string;
    regime: string;
    contador_responsavel: string;
}

interface ClientsPaginator {
    data: ClientRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const props = defineProps<{
    clients: ClientsPaginator;
}>();

type BadgeColor =
    | 'primary'
    | 'secondary'
    | 'success'
    | 'info'
    | 'warning'
    | 'error'
    | 'neutral';

const REGIME_LABELS: Record<string, string> = {
    simples: 'Simples Nacional',
    presumido: 'Lucro Presumido',
    real: 'Lucro Real',
    mei: 'MEI',
};

const REGIME_COLORS: Record<string, BadgeColor> = {
    simples: 'success',
    presumido: 'info',
    real: 'warning',
    mei: 'neutral',
};

const REGIME_OPTIONS = [
    { label: 'Todos os regimes', value: 'all' },
    { label: 'Simples Nacional', value: 'simples' },
    { label: 'Lucro Presumido', value: 'presumido' },
    { label: 'Lucro Real', value: 'real' },
    { label: 'MEI', value: 'mei' },
];

const table = useTemplateRef<{ tableApi: Table<ClientRow> }>('table');

const search = ref('');
const regimeFilter = ref('all');
const rowSelection = ref({});
const clientToDelete = ref<ClientRow | null>(null);
const singleDeleteOpen = computed<boolean>({
    get: () => clientToDelete.value !== null,
    set: (value: boolean) => {
        if (!value) {
            clientToDelete.value = null;
        }
    },
});
const bulkConfirmOpen = ref(false);
const deleting = ref(false);

const page = usePage();

const canOperate = computed<boolean>(
    () => page.props.permissions?.['operate'] === true,
);

function formatCnpj(value: string): string {
    const digits = value.replace(/\D/g, '').slice(0, 14);

    if (digits.length <= 2) {
        return digits;
    }

    if (digits.length <= 5) {
        return `${digits.slice(0, 2)}.${digits.slice(2)}`;
    }

    if (digits.length <= 8) {
        return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5)}`;
    }

    if (digits.length <= 12) {
        return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8)}`;
    }

    return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8, 12)}-${digits.slice(12)}`;
}

function initials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

// Local-only filter over the current server page (no backend change).
const filtered = computed<ClientRow[]>(() => {
    const term = search.value.trim().toLowerCase();
    const digits = search.value.replace(/\D/g, '');

    return props.clients.data.filter((client) => {
        if (
            regimeFilter.value !== 'all' &&
            client.regime !== regimeFilter.value
        ) {
            return false;
        }

        if (term === '') {
            return true;
        }

        if (digits !== '' && client.cnpj.includes(digits)) {
            return true;
        }

        return (
            client.razao_social.toLowerCase().includes(term) ||
            client.contador_responsavel.toLowerCase().includes(term)
        );
    });
});

const selectedCount = computed(
    () => table.value?.tableApi?.getFilteredSelectedRowModel().rows.length ?? 0,
);
const selectedIds = computed<number[]>(() =>
    (table.value?.tableApi?.getFilteredSelectedRowModel().rows ?? []).map(
        (row: Row<ClientRow>) => row.original.id,
    ),
);

function copyClientId(row: Row<ClientRow>): void {
    void navigator.clipboard.writeText(row.original.id.toString());
    useToast().add({
        title: 'Copiado',
        description: 'Identificador do Client copiado.',
    });
}

function confirmSingleDelete(row: Row<ClientRow>): void {
    clientToDelete.value = row.original;
}

function deleteSingle(): void {
    if (!clientToDelete.value) {
        return;
    }

    deleting.value = true;
    router.delete(destroyClient.url({ client: clientToDelete.value.id }), {
        onFinish: () => {
            deleting.value = false;
            clientToDelete.value = null;
            rowSelection.value = {};
        },
    });
}

function deleteSelected(): void {
    if (selectedIds.value.length === 0) {
        return;
    }

    deleting.value = true;
    router.post(
        bulkDestroyClients.url(),
        { ids: selectedIds.value },
        {
            onFinish: () => {
                deleting.value = false;
                bulkConfirmOpen.value = false;
                rowSelection.value = {};
            },
        },
    );
}

function getRowItems(row: Row<ClientRow>): object[] {
    return [
        { type: 'label', label: 'Ações' },
        {
            label: 'Copiar identificador',
            icon: 'i-lucide-copy',
            onSelect: () => copyClientId(row),
        },
        { type: 'separator' },
        {
            label: 'Ver detalhes',
            icon: 'i-lucide-list',
            onSelect: () => router.visit(show.url({ client: row.original.id })),
        },
        { type: 'separator' },
        {
            label: 'Excluir client',
            icon: 'i-lucide-trash',
            color: 'error',
            onSelect: () => confirmSingleDelete(row),
        },
    ];
}

const columns: TableColumn<ClientRow>[] = [
    {
        id: 'select',
        header: ({ table: api }) =>
            h(UCheckbox, {
                modelValue: api.getIsSomePageRowsSelected()
                    ? 'indeterminate'
                    : api.getIsAllPageRowsSelected(),
                'onUpdate:modelValue': (value: boolean | 'indeterminate') =>
                    api.toggleAllPageRowsSelected(!!value),
                ariaLabel: 'Selecionar todos',
            }),
        cell: ({ row }) =>
            h(UCheckbox, {
                modelValue: row.getIsSelected(),
                'onUpdate:modelValue': (value: boolean | 'indeterminate') =>
                    row.toggleSelected(!!value),
                ariaLabel: 'Selecionar linha',
            }),
    },
    {
        accessorKey: 'id',
        header: 'ID',
    },
    {
        accessorKey: 'razao_social',
        header: ({ column }) => {
            const isSorted = column.getIsSorted();

            return h(UButton, {
                color: 'neutral',
                variant: 'ghost',
                label: 'Client',
                icon: isSorted
                    ? isSorted === 'asc'
                        ? 'i-lucide-arrow-up-narrow-wide'
                        : 'i-lucide-arrow-down-wide-narrow'
                    : 'i-lucide-arrow-up-down',
                class: '-mx-2.5',
                onClick: () =>
                    column.toggleSorting(column.getIsSorted() === 'asc'),
            });
        },
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-3' }, [
                h(UAvatar, {
                    text: initials(row.original.razao_social),
                    size: 'lg',
                }),
                h('div', undefined, [
                    h(
                        Link,
                        {
                            href: show.url({ client: row.original.id }),
                            class: 'text-primary font-medium hover:underline',
                        },
                        () => row.original.razao_social,
                    ),
                    h(
                        'p',
                        { class: 'tabular-nums' },
                        formatCnpj(row.original.cnpj),
                    ),
                ]),
            ]),
    },
    {
        accessorKey: 'regime',
        header: 'Regime',
        cell: ({ row }) => {
            const regime = row.original.regime;
            const color = REGIME_COLORS[regime] ?? 'neutral';

            return h(
                UBadge,
                { class: 'capitalize', variant: 'subtle', color },
                () => REGIME_LABELS[regime] ?? regime,
            );
        },
    },
    {
        accessorKey: 'contador_responsavel',
        header: 'Contador',
    },
    {
        id: 'actions',
        cell: ({ row }) =>
            h(
                'div',
                { class: 'text-right' },
                h(
                    UDropdownMenu,
                    {
                        content: { align: 'end' },
                        items: getRowItems(row),
                    },
                    () =>
                        h(UButton, {
                            icon: 'i-lucide-ellipsis-vertical',
                            color: 'neutral',
                            variant: 'ghost',
                            class: 'ml-auto',
                        }),
                ),
            ),
    },
];

function goToPage(nextPage: number): void {
    router.get(
        clientsIndex.url(),
        { page: nextPage },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Clients" />

    <UDashboardPanel id="clients">
        <template #header>
            <UDashboardNavbar title="Clients">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>

                <template #right>
                    <UButton
                        v-if="canOperate"
                        :to="create.url()"
                        icon="i-lucide-plus"
                        data-test="client-new"
                    >
                        Novo client
                    </UButton>
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <PlanLimitWarning />

            <div class="flex flex-wrap items-center justify-between gap-1.5">
                <UInput
                    v-model="search"
                    class="max-w-sm"
                    icon="i-lucide-search"
                    placeholder="Buscar por CNPJ, razão ou contador…"
                    data-test="client-search"
                />

                <div class="flex flex-wrap items-center gap-1.5">
                    <UButton
                        v-if="selectedCount > 0"
                        label="Excluir"
                        color="error"
                        variant="subtle"
                        icon="i-lucide-trash"
                        data-test="client-bulk-delete"
                        @click="bulkConfirmOpen = true"
                    >
                        <template #trailing>
                            <UKbd>{{ selectedCount }}</UKbd>
                        </template>
                    </UButton>

                    <USelect
                        v-model="regimeFilter"
                        :items="REGIME_OPTIONS"
                        :ui="{
                            trailingIcon:
                                'group-data-[state=open]:rotate-180 transition-transform duration-200',
                        }"
                        placeholder="Filtrar regime"
                        class="min-w-28"
                        data-test="client-regime-filter"
                    />
                    <UDropdownMenu
                        :items="
                            table?.tableApi
                                ?.getAllColumns()
                                .filter((column: Column<ClientRow>) =>
                                    column.getCanHide(),
                                )
                                .map((column: Column<ClientRow>) => ({
                                    label: upperFirst(column.id),
                                    type: 'checkbox' as const,
                                    checked: column.getIsVisible(),
                                    onUpdateChecked(checked: boolean) {
                                        table?.tableApi
                                            ?.getColumn(column.id)
                                            ?.toggleVisibility(!!checked);
                                    },
                                    onSelect(e?: Event) {
                                        e?.preventDefault();
                                    },
                                }))
                        "
                        :content="{ align: 'end' }"
                    >
                        <UButton
                            label="Exibir"
                            color="neutral"
                            variant="outline"
                            trailing-icon="i-lucide-settings-2"
                        />
                    </UDropdownMenu>
                </div>
            </div>

            <UTable
                ref="table"
                v-model:row-selection="rowSelection"
                class="shrink-0"
                :data="filtered"
                :columns="columns"
                :ui="{
                    base: 'table-fixed border-separate border-spacing-0',
                    thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
                    tbody: '[&>tr]:last:[&>td]:border-b-0',
                    th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
                    td: 'border-b border-default',
                    separator: 'h-0',
                }"
                data-test="client-table"
            />

            <div
                class="border-default mt-auto flex items-center justify-between gap-3 border-t pt-4"
            >
                <div class="text-muted text-sm">
                    {{ selectedCount }} de {{ filtered.length }} selecionado(s)
                    nesta página.
                </div>

                <UPagination
                    v-if="clients.last_page > 1"
                    :page="clients.current_page"
                    :items-per-page="clients.per_page"
                    :total="clients.total"
                    data-test="client-pagination"
                    @update:page="goToPage"
                />
            </div>

            <UModal
                v-model:open="bulkConfirmOpen"
                title="Excluir clients selecionados"
                description="Esta ação não pode ser desfeita."
            >
                <template #body>
                    <p class="text-sm">
                        Excluir {{ selectedCount }} client(s) da carteira desta
                        Account?
                    </p>
                </template>

                <template #footer>
                    <div class="flex justify-end gap-2">
                        <UButton
                            color="neutral"
                            variant="ghost"
                            label="Cancelar"
                            @click="bulkConfirmOpen = false"
                        />
                        <UButton
                            color="error"
                            label="Excluir"
                            :loading="deleting"
                            data-test="client-bulk-confirm"
                            @click="deleteSelected"
                        />
                    </div>
                </template>
            </UModal>

            <UModal
                v-model:open="singleDeleteOpen"
                title="Excluir client"
                description="Esta ação não pode ser desfeita."
            >
                <template #body>
                    <p class="text-sm">
                        Excluir {{ clientToDelete?.razao_social }} da carteira
                        desta Account?
                    </p>
                </template>

                <template #footer>
                    <div class="flex justify-end gap-2">
                        <UButton
                            color="neutral"
                            variant="ghost"
                            label="Cancelar"
                            @click="clientToDelete = null"
                        />
                        <UButton
                            color="error"
                            label="Excluir"
                            :loading="deleting"
                            data-test="client-delete-confirm"
                            @click="deleteSingle"
                        />
                    </div>
                </template>
            </UModal>
        </template>
    </UDashboardPanel>
</template>
