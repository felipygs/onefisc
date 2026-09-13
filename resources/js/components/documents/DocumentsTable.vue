<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui';
import type { Row, Table } from '@tanstack/table-core';
import {
    computed,
    getCurrentInstance,
    h,
    resolveComponent,
    useTemplateRef,
} from 'vue';
import type {
    DocumentSortKey,
    FiscalDocumentRow,
    FiscalFamily,
    SortDir,
} from '@/types/documents';

const UButton = resolveComponent('UButton');
const UBadge = resolveComponent('UBadge');
const UDropdownMenu = resolveComponent('UDropdownMenu');

const props = withDefaults(
    defineProps<{
        rows: FiscalDocumentRow[];
        sort: DocumentSortKey;
        dir: SortDir;
        showClient?: boolean;
    }>(),
    { showClient: true },
);

const emit = defineEmits<{
    sort: [key: DocumentSortKey];
    'open-detail': [row: FiscalDocumentRow];
    'download-xml': [row: FiscalDocumentRow];
    'view-danfe': [row: FiscalDocumentRow];
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

const STATUS_COLORS: Record<string, BadgeColor> = {
    authorized: 'success',
    cancelled: 'error',
    denied: 'error',
    pending: 'warning',
};

// The actions column only exists when the parent listens to at least one
// row action (task 4.4 connects them; without handlers there are no dead
// buttons). Declared emits are kept out of attrs, so inspect vnode props.
const instance = getCurrentInstance();
const hasActions = computed<boolean>(() => {
    const vnodeProps = instance?.vnode.props ?? {};

    return (
        vnodeProps['onOpenDetail'] != null ||
        vnodeProps['onDownloadXml'] != null ||
        vnodeProps['onViewDanfe'] != null
    );
});

function docNumber(row: FiscalDocumentRow): string {
    if (row.number) {
        return row.series ? `${row.number}/${row.series}` : row.number;
    }

    return row.key?.slice(-8) ?? '—';
}

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

function sortIcon(key: DocumentSortKey): string {
    if (props.sort !== key) {
        return 'i-lucide-arrow-up-down';
    }

    return props.dir === 'asc'
        ? 'i-lucide-arrow-up-narrow-wide'
        : 'i-lucide-arrow-down-wide-narrow';
}

function getRowItems(row: Row<FiscalDocumentRow>): object[] {
    const document = row.original;

    return [
        { type: 'label', label: 'Ações' },
        {
            label: 'Abrir detalhes',
            icon: 'i-lucide-eye',
            onSelect: () => emit('open-detail', document),
        },
        {
            label: document.has_xml ? 'Baixar XML' : 'XML indisponível',
            icon: 'i-lucide-download',
            disabled: !document.has_xml,
            onSelect: () => emit('download-xml', document),
        },
        {
            label: document.family === 'nfse' ? 'Ver DANFSe' : 'Ver DANFE',
            icon: 'i-lucide-file-text',
            disabled: !document.has_danfe,
            onSelect: () => emit('view-danfe', document),
        },
    ];
}

const columns = computed<TableColumn<FiscalDocumentRow>[]>(() => {
    const list: TableColumn<FiscalDocumentRow>[] = [
        {
            id: 'documento',
            header: () =>
                h(UButton, {
                    color: 'neutral',
                    variant: 'ghost',
                    label: 'Documento',
                    icon: sortIcon('documento'),
                    class: '-mx-2.5',
                    'data-test': 'documents-sort-documento',
                    onClick: () => emit('sort', 'documento'),
                }),
            cell: ({ row }) =>
                h('div', { class: 'flex min-w-0 flex-col gap-1' }, [
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
                            docNumber(row.original),
                        ),
                        row.original.derived_from_key
                            ? h(
                                  UBadge,
                                  {
                                      variant: 'subtle',
                                      color: 'neutral',
                                  },
                                  () => 'Derivado',
                              )
                            : null,
                    ]),
                    row.original.key
                        ? h(
                              'span',
                              {
                                  class: 'text-muted max-w-56 truncate font-mono text-xs',
                                  title: row.original.key,
                              },
                              row.original.key,
                          )
                        : null,
                ]),
        },
        {
            id: 'emissao',
            header: () =>
                h(UButton, {
                    color: 'neutral',
                    variant: 'ghost',
                    label: 'Emissão',
                    icon: sortIcon('emissao'),
                    class: '-mx-2.5',
                    'data-test': 'documents-sort-emissao',
                    onClick: () => emit('sort', 'emissao'),
                }),
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'text-sm tabular-nums' },
                    formatDate(row.original.emission_at),
                ),
        },
        {
            id: 'emitente',
            header: 'Emitente / Prestador',
            cell: ({ row }) =>
                h('div', { class: 'min-w-0' }, [
                    h(
                        'p',
                        { class: 'max-w-48 truncate text-sm' },
                        row.original.issuer_name ?? '—',
                    ),
                    row.original.issuer_tax_id
                        ? h(
                              'p',
                              {
                                  class: 'text-muted text-xs tabular-nums',
                              },
                              row.original.issuer_tax_id,
                          )
                        : null,
                ]),
        },
        {
            id: 'destinatario',
            header: 'Destinatário / Tomador',
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'block max-w-48 truncate text-sm' },
                    row.original.recipient_name ?? '—',
                ),
        },
    ];

    if (props.showClient) {
        list.push({
            id: 'cliente',
            header: 'Cliente',
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'block max-w-48 truncate text-sm' },
                    row.original.client?.name ?? '—',
                ),
        });
    }

    list.push({
        id: 'status',
        header: () =>
            h(UButton, {
                color: 'neutral',
                variant: 'ghost',
                label: 'Status',
                icon: sortIcon('status'),
                class: '-mx-2.5',
                'data-test': 'documents-sort-status',
                onClick: () => emit('sort', 'status'),
            }),
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
    });

    if (hasActions.value) {
        list.push({
            id: 'acoes',
            enableHiding: false,
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
                                'aria-label': 'Ações do documento',
                                'data-test': `documents-row-actions-${row.original.id}`,
                                onClick: (e: Event) => e.stopPropagation(),
                            }),
                    ),
                ),
        });
    }

    return list;
});

// No select column and no row-selection binding: bulk selection stays
// disabled on v1 by construction. Exposed for the FiltersToolbar
// Exibição menu, which drives column visibility through it.
const table = useTemplateRef<{ tableApi: Table<FiscalDocumentRow> }>('table');
const tableApi = computed<Table<FiscalDocumentRow> | null>(
    () => table.value?.tableApi ?? null,
);

defineExpose({ tableApi });
</script>

<template>
    <UTable
        ref="table"
        :data="rows"
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
</template>
