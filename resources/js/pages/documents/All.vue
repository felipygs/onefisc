<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui';
import { Head, router } from '@inertiajs/vue3';
import { h, resolveComponent } from 'vue';
import { all as documentsAll } from '@/routes/documents';
import type { FiscalDocumentRow, FiscalFamily } from '@/types/documents';

const UBadge = resolveComponent('UBadge');

const props = defineProps<{
    documents: {
        data: FiscalDocumentRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    family: FiscalFamily | null;
    clients: { id: number; name: string }[];
}>();

type BadgeColor =
    | 'primary'
    | 'secondary'
    | 'success'
    | 'info'
    | 'warning'
    | 'error'
    | 'neutral';

const FAMILY_LABELS: Record<FiscalFamily, string> = {
    nfe: 'NF-e',
    cte: 'CT-e',
    nfse: 'NFS-e',
};

const FAMILY_OPTIONS = [
    { label: 'Todas as famílias', value: 'all' },
    { label: 'NF-e', value: 'nfe' },
    { label: 'CT-e', value: 'cte' },
    { label: 'NFS-e', value: 'nfse' },
];

const STATUS_COLORS: Record<string, BadgeColor> = {
    authorized: 'success',
    cancelled: 'error',
    denied: 'error',
    pending: 'warning',
};

const clientNames = new Map(props.clients.map((c) => [c.id, c.name]));

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(parsed);
}

// Interim simple table: Task 4.3 replaces it with the final advanced
// table (DocumentsTable + FiltersToolbar). Columns stay essential only.
const columns: TableColumn<FiscalDocumentRow>[] = [
    {
        accessorKey: 'number',
        header: 'Documento',
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-2' }, [
                h(
                    UBadge,
                    { variant: 'subtle', color: 'info' },
                    () =>
                        FAMILY_LABELS[row.original.family] ??
                        row.original.family,
                ),
                h(
                    'span',
                    { class: 'text-sm tabular-nums' },
                    row.original.number ?? row.original.key?.slice(-8) ?? '—',
                ),
            ]),
    },
    {
        accessorKey: 'client',
        header: 'Cliente',
        cell: ({ row }) =>
            h(
                'span',
                { class: 'block max-w-48 truncate text-sm' },
                row.original.client?.name ??
                    clientNames.get(row.original.client?.id ?? -1) ??
                    '—',
            ),
    },
    {
        accessorKey: 'issuer_name',
        header: 'Emitente',
        cell: ({ row }) =>
            h(
                'span',
                { class: 'block max-w-48 truncate text-sm' },
                row.original.issuer_name ?? '—',
            ),
    },
    {
        accessorKey: 'emission_at',
        header: 'Emissão',
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-sm tabular-nums' },
                formatDate(row.original.emission_at),
            ),
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) =>
            h(
                UBadge,
                {
                    variant: 'subtle',
                    color:
                        STATUS_COLORS[row.original.status ?? ''] ?? 'neutral',
                },
                () => row.original.status_label,
            ),
    },
    {
        accessorKey: 'has_xml',
        header: 'XML',
        cell: ({ row }) =>
            h(
                UBadge,
                {
                    variant: 'subtle',
                    color: row.original.has_xml ? 'success' : 'warning',
                },
                () => (row.original.has_xml ? 'Completo' : 'Pendente'),
            ),
    },
];

function reloadFamily(next: string): void {
    router.get(documentsAll.url(), next === 'all' ? {} : { family: next }, {
        preserveScroll: true,
    });
}

function goToPage(nextPage: number): void {
    router.get(
        documentsAll.url(),
        {
            ...(props.family ? { family: props.family } : {}),
            page: nextPage,
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Todos os documentos" />

    <UDashboardPanel id="documents-all">
        <template #header>
            <UDashboardNavbar title="Todos os documentos">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <div class="flex flex-wrap items-center justify-between gap-1.5">
                <p class="text-muted text-sm tabular-nums">
                    {{ documents.total.toLocaleString('pt-BR') }}
                    documento(s) na carteira.
                </p>

                <USelect
                    :model-value="family ?? 'all'"
                    :items="FAMILY_OPTIONS"
                    value-key="value"
                    placeholder="Filtrar família"
                    class="min-w-40"
                    data-test="documents-family-filter"
                    @update:model-value="reloadFamily"
                />
            </div>

            <UEmpty
                v-if="documents.data.length === 0"
                icon="i-lucide-file-text"
                title="Nenhum documento encontrado"
                description="Ajuste o filtro de família ou aguarde a próxima sincronização."
                data-test="documents-all-empty"
            />

            <UTable
                v-else
                :data="documents.data"
                :columns="columns"
                :ui="{
                    base: 'table-fixed border-separate border-spacing-0',
                    thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
                    tbody: '[&>tr]:last:[&>td]:border-b-0',
                    th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
                    td: 'border-b border-default',
                    separator: 'h-0',
                }"
                data-test="documents-table"
            />

            <div
                class="border-default mt-auto flex items-center justify-between gap-3 border-t pt-4"
            >
                <div class="text-muted text-sm tabular-nums">
                    Página {{ documents.current_page }} de
                    {{ documents.last_page }}.
                </div>

                <UPagination
                    v-if="documents.last_page > 1"
                    :page="documents.current_page"
                    :items-per-page="documents.per_page"
                    :total="documents.total"
                    data-test="documents-pagination"
                    @update:page="goToPage"
                />
            </div>
        </template>
    </UDashboardPanel>
</template>
