<script setup lang="ts">
import type { Table } from '@tanstack/table-core';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import DanfeModal from '@/components/documents/DanfeModal.vue';
import DetailSlideover from '@/components/documents/DetailSlideover.vue';
import DocumentsTable from '@/components/documents/DocumentsTable.vue';
import FiltersToolbar from '@/components/documents/FiltersToolbar.vue';
import { danfe, download } from '@/routes/fiscal';
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

// Task 4.4 overlays: the table emits row actions (disabled without the
// artifact); listening also switches the actions column on.
const toast = useToast();

const selected = ref<FiscalDocumentRow | null>(null);
const detailOpen = ref(false);

const danfeOpen = ref(false);
const danfeTitle = ref('DANFE');
const danfePdfUrl = ref<string | null>(null);
const danfeDownloadUrl = ref<string | null>(null);
const danfeError = ref<string | null>(null);
const danfeRetryable = ref(false);
const danfePending = ref<FiscalDocumentRow | null>(null);

function onOpenDetail(row: FiscalDocumentRow): void {
    selected.value = row;
    detailOpen.value = true;
}

async function onDownloadXml(row: FiscalDocumentRow): Promise<void> {
    if (!row.has_xml) {
        return;
    }

    try {
        const response = await fetch(download.url({ document: row.id }), {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(`download ${response.status}`);
        }

        const data = (await response.json()) as {
            download_url?: unknown;
        };

        if (typeof data.download_url !== 'string' || data.download_url === '') {
            throw new Error('download sem url');
        }

        window.location.href = data.download_url;
    } catch {
        toast.add({
            title: 'Não foi possível baixar o XML.',
            description: 'Tente novamente em instantes.',
        });
    }
}

function onViewDanfe(row: FiscalDocumentRow): void {
    if (!row.has_danfe) {
        return;
    }

    danfePending.value = row;
    danfeTitle.value = row.family === 'nfse' ? 'DANFSe' : 'DANFE';
    danfeOpen.value = true;
    void loadDanfe(row);
}

async function loadDanfe(row: FiscalDocumentRow): Promise<void> {
    danfePdfUrl.value = null;
    danfeDownloadUrl.value = null;
    danfeError.value = null;
    danfeRetryable.value = false;

    try {
        const pdfResponse = await fetch(danfe.url({ document: row.id }), {
            headers: { Accept: 'application/json' },
        });

        // Gone artifact is final: no retry, honest message.
        if (pdfResponse.status === 404) {
            danfeError.value =
                'O documento auxiliar não está mais disponível para este documento.';
            return;
        }

        if (!pdfResponse.ok) {
            throw new Error(`danfe ${pdfResponse.status}`);
        }

        const pdfData = (await pdfResponse.json()) as {
            pdf_url?: unknown;
        };

        if (typeof pdfData.pdf_url !== 'string' || pdfData.pdf_url === '') {
            throw new Error('danfe sem url');
        }

        danfePdfUrl.value = pdfData.pdf_url;

        if (row.has_xml) {
            try {
                const xmlResponse = await fetch(
                    download.url({ document: row.id }),
                    { headers: { Accept: 'application/json' } },
                );

                if (xmlResponse.ok) {
                    const xmlData = (await xmlResponse.json()) as {
                        download_url?: unknown;
                    };

                    if (typeof xmlData.download_url === 'string') {
                        danfeDownloadUrl.value = xmlData.download_url;
                    }
                }
            } catch {
                // XML button simply stays disabled; the preview is unaffected.
            }
        }
    } catch {
        danfeError.value =
            'Não foi possível carregar a pré-visualização. Verifique a conexão.';
        danfeRetryable.value = true;
    }
}

function onRetryDanfe(): void {
    if (danfePending.value) {
        void loadDanfe(danfePending.value);
    }
}

function onDanfeDownload(): void {
    if (danfeDownloadUrl.value) {
        window.location.href = danfeDownloadUrl.value;
    }
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
                @open-detail="onOpenDetail"
                @download-xml="onDownloadXml"
                @view-danfe="onViewDanfe"
            />

            <DetailSlideover
                v-model:open="detailOpen"
                :document="selected"
            />

            <DanfeModal
                v-model:open="danfeOpen"
                :title="danfeTitle"
                :pdf-url="danfePdfUrl"
                :download-url="danfeDownloadUrl"
                :error="danfeError"
                :retryable="danfeRetryable"
                @retry="onRetryDanfe"
                @download="onDanfeDownload"
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
