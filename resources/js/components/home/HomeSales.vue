<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui';
import { h, resolveComponent } from 'vue';
import type { HomeSale } from '@/types/home';

defineProps<{
    sales: HomeSale[];
}>();

const UBadge = resolveComponent('UBadge');

const formatBrl = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
}).format;

function formatDate(value: string): string {
    return new Date(value).toLocaleString('pt-BR', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

const STATUS_LABELS: Record<HomeSale['status'], string> = {
    paid: 'Paga',
    failed: 'Falhou',
    refunded: 'Estornada',
};

const columns: TableColumn<HomeSale>[] = [
    {
        accessorKey: 'id',
        header: 'ID',
        cell: ({ row }) => `#${row.getValue('id')}`,
    },
    {
        accessorKey: 'date',
        header: 'Data',
        cell: ({ row }) => formatDate(row.getValue('date')),
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => {
            const status = row.getValue('status') as HomeSale['status'];
            const color = {
                paid: 'success' as const,
                failed: 'error' as const,
                refunded: 'neutral' as const,
            }[status];

            return h(
                UBadge,
                { class: 'capitalize', variant: 'subtle', color },
                () => STATUS_LABELS[status] ?? status,
            );
        },
    },
    {
        accessorKey: 'email',
        header: 'E-mail',
    },
    {
        accessorKey: 'amount',
        header: () => h('div', { class: 'text-right' }, 'Valor'),
        cell: ({ row }) => {
            const amount = Number.parseFloat(row.getValue('amount'));

            return h(
                'div',
                { class: 'text-right font-medium' },
                formatBrl(amount),
            );
        },
    },
];
</script>

<template>
    <UTable
        :data="sales"
        :columns="columns"
        class="shrink-0"
        :ui="{
            base: 'table-fixed border-separate border-spacing-0',
            thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
            td: 'border-b border-default',
        }"
        data-test="home-sales"
    />
</template>
