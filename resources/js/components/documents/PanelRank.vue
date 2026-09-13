<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui';
import { computed, h, resolveComponent } from 'vue';

const UBadge = resolveComponent('UBadge');

const props = withDefaults(
    defineProps<{
        rows: { id: number; name: string; value: number }[];
        title?: string;
        description?: string;
    }>(),
    {
        title: 'Clientes com maior movimento',
        description:
            'Comparativo da carteira no período. Valor = volume de documentos.',
    },
);

const columns: TableColumn<{ id: number; name: string; value: number }>[] = [
    {
        accessorKey: 'name',
        header: 'Cliente',
    },
    {
        accessorKey: 'value',
        header: 'Documentos',
        cell: ({ row }) =>
            h(UBadge, { variant: 'subtle', color: 'neutral' }, () =>
                row.original.value.toLocaleString('pt-BR'),
            ),
    },
];

const top = computed(() => props.rows.slice(0, 8));
</script>

<template>
    <UCard data-test="documents-rank">
        <template #header>
            <div>
                <h2 class="text-highlighted text-sm font-semibold">
                    {{ title }}
                </h2>
                <p class="text-muted text-xs">{{ description }}</p>
            </div>
        </template>

        <UEmpty
            v-if="top.length === 0"
            icon="i-lucide-trophy"
            title="Sem volume na carteira"
            description="Não há documentos de clientes neste período."
        />

        <UTable
            v-else
            :data="top"
            :columns="columns"
            :ui="{
                base: 'table-fixed border-separate border-spacing-0',
                thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
                tbody: '[&>tr]:last:[&>td]:border-b-0',
                th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
                td: 'border-b border-default',
                separator: 'h-0',
            }"
            data-test="documents-rank-table"
        />
    </UCard>
</template>
