<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui';
import { computed, h, resolveComponent } from 'vue';
import type { AttentionRow } from '@/types/documents';

const UBadge = resolveComponent('UBadge');

const props = defineProps<{
    rows: AttentionRow[];
}>();

type BadgeColor =
    | 'primary'
    | 'secondary'
    | 'success'
    | 'info'
    | 'warning'
    | 'error'
    | 'neutral';

type Reason = AttentionRow['reason'];

const REASON_LABELS: Record<Reason, string> = {
    sync_failed: 'Falha na sincronização',
    coverage_limited: 'Cobertura limitada',
    certificate_missing: 'Sem certificado',
    certificate_expiring: 'Certificado vencendo',
    pending_xml: 'XML completo pendente',
};

const REASON_COLORS: Record<Reason, BadgeColor> = {
    sync_failed: 'error',
    coverage_limited: 'error',
    certificate_missing: 'warning',
    certificate_expiring: 'warning',
    pending_xml: 'warning',
};

function formatTaxId(value: string): string {
    const digits = value.replace(/\D/g, '');

    if (digits.length !== 14) {
        return value;
    }

    return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8, 12)}-${digits.slice(12)}`;
}

const columns = computed<TableColumn<AttentionRow>[]>(() => [
    {
        accessorKey: 'name',
        header: 'Cliente',
        cell: ({ row }) =>
            h('div', { class: 'min-w-0' }, [
                h(
                    'p',
                    { class: 'text-highlighted truncate font-medium' },
                    row.original.name,
                ),
                h(
                    'p',
                    {
                        class: 'text-muted truncate font-mono text-xs tabular-nums',
                    },
                    formatTaxId(row.original.tax_id),
                ),
            ]),
    },
    {
        accessorKey: 'reason',
        header: 'Motivo',
        cell: ({ row }) =>
            h(
                UBadge,
                {
                    variant: 'subtle',
                    size: 'sm',
                    color: REASON_COLORS[row.original.reason],
                },
                () => REASON_LABELS[row.original.reason],
            ),
    },
]);
</script>

<template>
    <UCard data-test="documents-attention">
        <template #header>
            <div>
                <h2 class="text-highlighted text-sm font-semibold">
                    Pendências administrativas
                </h2>
                <p class="text-muted text-xs">
                    Certificados, sincronização e XML completo.
                </p>
            </div>
        </template>

        <UEmpty
            v-if="rows.length === 0"
            icon="i-lucide-circle-check"
            title="Nenhuma pendência administrativa"
            description="Certificados, sincronização e XML completo estão em dia neste recorte."
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
            data-test="documents-attention-table"
        />
    </UCard>
</template>
