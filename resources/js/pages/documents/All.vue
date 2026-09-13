<script setup lang="ts">
import type { Table } from '@tanstack/table-core';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import DocumentsTable from '@/components/documents/DocumentsTable.vue';
import FiltersToolbar from '@/components/documents/FiltersToolbar.vue';
import { all as documentsAll } from '@/routes/documents';
import type {
    DocumentFilters,
    DocumentSortKey,
    FiscalDocumentRow,
    SortDir,
} from '@/types/documents';

const props = defineProps<{
    documents: {
        data: FiscalDocumentRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: DocumentFilters;
}>();

// Live TanStack table for the FiltersToolbar Exibição menu. A function
// ref keeps it fresh across child updates without extra events.
const tableApi = ref<Table<FiscalDocumentRow> | null>(null);

function setTableRef(el: unknown): void {
    tableApi.value =
        (el as { tableApi?: Table<FiscalDocumentRow> | null } | null)
            ?.tableApi ?? null;
}

function filterParams(): Record<string, string> {
    const params: Record<string, string> = {};

    if (props.filters.q !== '') {
        params.q = props.filters.q;
    }

    if (props.filters.family !== null) {
        params.family = props.filters.family;
    }

    if (props.filters.status !== null) {
        params.status = props.filters.status;
    }

    if (props.filters.origin !== null) {
        params.origin = props.filters.origin;
    }

    if (props.filters.sort !== 'emissao') {
        params.sort = props.filters.sort;
    }

    if (props.filters.dir !== 'desc') {
        params.dir = props.filters.dir;
    }

    return params;
}

function onSort(key: DocumentSortKey): void {
    const dir: SortDir =
        props.filters.sort === key && props.filters.dir === 'desc'
            ? 'asc'
            : 'desc';

    router.get(
        documentsAll.url(),
        { ...filterParams(), sort: key, dir },
        { preserveScroll: true, preserveState: true },
    );
}

function goToPage(nextPage: number): void {
    router.get(
        documentsAll.url(),
        { ...filterParams(), page: nextPage },
        { preserveScroll: true, preserveState: true },
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
                <p
                    class="text-muted text-sm tabular-nums"
                    data-test="documents-count"
                >
                    {{ documents.total.toLocaleString('pt-BR') }}
                    documento(s) na carteira.
                </p>
            </div>

            <FiltersToolbar :filters="filters" :table-api="tableApi" />

            <UEmpty
                v-if="documents.data.length === 0"
                icon="i-lucide-file-text"
                title="Nenhum documento encontrado"
                description="Ajuste os filtros ou aguarde a próxima sincronização."
                data-test="documents-all-empty"
            />

            <DocumentsTable
                v-else
                :ref="setTableRef"
                :rows="documents.data"
                :sort="filters.sort"
                :dir="filters.dir"
                @sort="onSort"
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
