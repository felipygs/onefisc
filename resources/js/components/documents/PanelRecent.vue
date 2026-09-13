<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui';
import { h, resolveComponent } from 'vue';
import type { FiscalDocumentRow, FiscalFamily } from '@/types/documents';

const UBadge = resolveComponent('UBadge');

defineProps<{
    rows: FiscalDocumentRow[];
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

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(
        parsed,
    );
}

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
</script>

<template>
    <UCard data-test="documents-recent">
        <template #header>
            <div>
                <h2 class="text-highlighted text-sm font-semibold">
                    Documentos recentes
                </h2>
                <p class="text-muted text-xs">
                    Últimos documentos indexados no período.
                </p>
            </div>
        </template>

        <UEmpty
            v-if="rows.length === 0"
            icon="i-lucide-file-text"
            title="Sem documentos recentes"
            description="Nenhum documento indexado neste recorte."
        />

        <UTable
            v-else
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
            data-test="documents-recent-table"
        />
    </UCard>
</template>
