<script setup lang="ts">
import type { Table } from '@tanstack/table-core';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PlanLimitWarning from '@/components/PlanLimitWarning.vue';
import CertificateUploadModal from '@/components/documents/CertificateUploadModal.vue';
import CredentialsRailCard from '@/components/documents/CredentialsRailCard.vue';
import DanfeModal from '@/components/documents/DanfeModal.vue';
import DetailSlideover from '@/components/documents/DetailSlideover.vue';
import DocumentsTable from '@/components/documents/DocumentsTable.vue';
import FiltersToolbar from '@/components/documents/FiltersToolbar.vue';
import PortalPasswordModal from '@/components/documents/PortalPasswordModal.vue';
import SyncStateCard from '@/components/documents/SyncStateCard.vue';
import {
    destroy,
    edit,
    index as clientsIndex,
    show as clientShow,
} from '@/routes/clients';
import { danfe, download } from '@/routes/fiscal';
import type {
    CertificateState,
    DocumentFilters,
    DocumentSortKey,
    FiscalDocumentRow,
    SortDir,
    SyncState,
} from '@/types/documents';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Clients',
                href: clientsIndex(),
            },
            {
                title: 'Detalhes',
                href: clientsIndex(),
            },
        ],
    },
});

interface Client {
    id: number;
    cnpj: string;
    razao_social: string;
    regime: string;
    contador_responsavel: string;
    created_at?: string | null;
    updated_at?: string | null;
}

const props = defineProps<{
    client: Client;
    certificate: CertificateState;
    documents: {
        data: FiscalDocumentRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: DocumentFilters;
    sync: SyncState;
    syncFamilies: string[];
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

const TABS = [
    { label: 'Dados', value: 'dados' },
    { label: 'Monitoramento', value: 'monitoramento' },
    { label: 'Histórico', value: 'historico' },
    { label: 'Fiscal', value: 'fiscal' },
];

const FISCAL_TABS = [
    { label: 'Documentos', value: 'documentos' },
    { label: 'Sincronização', value: 'sincronizacao' },
    { label: 'Certificado', value: 'certificado' },
];

// Refs survive every filter/pagination visit: all router.get calls below
// use preserveState, so the active tabs never reset on prop reloads.
const activeTab = ref('dados');
const fiscalTab = ref('documentos');

const page = usePage();

const canOperate = computed<boolean>(
    () => page.props.permissions?.['operate'] === true,
);

const showUrl = computed<string>(() =>
    clientShow.url({ client: props.client.id }),
);

const missingCertificate = computed<boolean>(
    () => props.certificate.status === 'missing',
);

const hasDocuments = computed<boolean>(() => props.documents.data.length > 0);

function formatCnpj(value: string): string {
    const digits = value.replace(/\D/g, '').slice(0, 14);

    if (digits.length <= 12) {
        return digits;
    }

    return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8, 12)}-${digits.slice(12)}`;
}

function formatDate(value?: string | null): string {
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

// Controller shares only the client; history is derived from its timestamps.
const timelineItems = computed(() => {
    const items = [
        {
            date: formatDate(props.client.created_at),
            title: 'Client cadastrado',
            description: `${props.client.razao_social} entrou na carteira.`,
        },
    ];

    if (
        props.client.updated_at &&
        props.client.updated_at !== props.client.created_at
    ) {
        items.push({
            date: formatDate(props.client.updated_at),
            title: 'Última atualização',
            description: 'Dados cadastrais atualizados.',
        });
    }

    return items;
});

function onDelete() {
    if (!confirm(`Remover ${props.client.razao_social}?`)) {
        return;
    }

    router.delete(destroy.url({ client: props.client.id }));
}

// Fiscal > Documentos: same filter/sort/pagination contract as the global
// table, scoped to this client via the show URL.
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
        showUrl.value,
        { ...filterParams(), sort: key, dir },
        { preserveScroll: true, preserveState: true },
    );
}

function goToPage(nextPage: number): void {
    router.get(
        showUrl.value,
        { ...filterParams(), page: nextPage },
        { preserveScroll: true, preserveState: true },
    );
}

// Task 4.4 overlays, same wiring as the global table: the table emits row
// actions (disabled without these handlers); listening also switches the
// actions column on.
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

// Fiscal > Certificado: rail + modals from task 2.3. The modals keep their
// own manage-users gate; this page only toggles them.
const uploadOpen = ref(false);
const portalOpen = ref(false);

function goToCertificate(): void {
    fiscalTab.value = 'certificado';
}
</script>

<template>
    <Head :title="client.razao_social" />

    <div class="flex flex-col gap-6 p-4">
        <PlanLimitWarning />

        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">
                    {{ client.razao_social }}
                </h1>
                <p class="text-muted text-sm tabular-nums">
                    {{ formatCnpj(client.cnpj) }}
                </p>
            </div>
            <div v-if="canOperate" class="flex items-center gap-2">
                <UButton
                    variant="outline"
                    :to="edit.url({ client: client.id })"
                    data-test="client-edit"
                >
                    Editar
                </UButton>
                <UButton
                    color="error"
                    variant="outline"
                    data-test="client-delete"
                    @click="onDelete"
                >
                    Remover
                </UButton>
            </div>
        </div>

        <UTabs v-model="activeTab" :items="TABS" data-test="client-tabs" />

        <UCard v-if="activeTab === 'dados'" data-test="client-tab-dados">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-muted text-xs uppercase">CNPJ</dt>
                    <dd class="mt-1 text-sm tabular-nums">
                        {{ formatCnpj(client.cnpj) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted text-xs uppercase">Razão social</dt>
                    <dd class="mt-1 text-sm">{{ client.razao_social }}</dd>
                </div>
                <div>
                    <dt class="text-muted text-xs uppercase">Regime</dt>
                    <dd class="mt-1">
                        <UBadge
                            :color="REGIME_COLORS[client.regime] ?? 'neutral'"
                            variant="soft"
                        >
                            {{ REGIME_LABELS[client.regime] ?? client.regime }}
                        </UBadge>
                    </dd>
                </div>
                <div>
                    <dt class="text-muted text-xs uppercase">
                        Contador responsável
                    </dt>
                    <dd class="mt-1 text-sm">
                        {{ client.contador_responsavel }}
                    </dd>
                </div>
            </dl>

            <template #footer>
                <UButton
                    variant="outline"
                    size="sm"
                    :to="clientsIndex.url()"
                    data-test="client-back"
                >
                    Voltar para clients
                </UButton>
            </template>
        </UCard>

        <UCard
            v-if="activeTab === 'monitoramento'"
            data-test="client-tab-monitoramento"
        >
            <p class="text-muted text-sm">
                Nenhuma verificação de monitoramento compartilhada com esta
                página.
            </p>
        </UCard>

        <UCard
            v-if="activeTab === 'historico'"
            data-test="client-tab-historico"
        >
            <UTimeline :items="timelineItems" />
        </UCard>

        <div v-if="activeTab === 'fiscal'" data-test="client-tab-fiscal">
            <UTabs
                v-model="fiscalTab"
                :items="FISCAL_TABS"
                data-test="client-fiscal-tabs"
            />

            <div
                v-if="fiscalTab === 'documentos'"
                class="mt-4 flex flex-col gap-4"
                data-test="client-fiscal-documentos"
            >
                <p
                    class="text-muted text-sm tabular-nums"
                    data-test="client-fiscal-count"
                >
                    {{ documents.total.toLocaleString('pt-BR') }}
                    documento(s) deste client.
                </p>

                <FiltersToolbar
                    :filters="filters"
                    :table-api="tableApi"
                    :base-url="showUrl"
                />

                <UEmpty
                    v-if="!hasDocuments && missingCertificate"
                    icon="i-lucide-key-round"
                    title="Nenhum documento sincronizado"
                    description="Suba o certificado A1 deste client para iniciar a sincronização com a SEFAZ."
                    data-test="client-fiscal-empty-cert"
                >
                    <template #actions>
                        <UButton
                            size="sm"
                            data-test="client-fiscal-empty-cert-cta"
                            @click="goToCertificate"
                        >
                            Ver certificado
                        </UButton>
                    </template>
                </UEmpty>

                <UEmpty
                    v-else-if="!hasDocuments"
                    icon="i-lucide-file-text"
                    title="Nenhum documento encontrado"
                    description="Ajuste os filtros ou aguarde a próxima sincronização."
                    data-test="client-fiscal-empty"
                />

                <DocumentsTable
                    v-else
                    :ref="setTableRef"
                    :rows="documents.data"
                    :sort="filters.sort"
                    :dir="filters.dir"
                    :show-client="false"
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
                    class="border-default flex items-center justify-between gap-3 border-t pt-4"
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
                        data-test="client-fiscal-pagination"
                        @update:page="goToPage"
                    />
                </div>
            </div>

            <div
                v-if="fiscalTab === 'sincronizacao'"
                class="mt-4"
                data-test="client-fiscal-sincronizacao"
            >
                <SyncStateCard :sync="sync" :families="syncFamilies" />
            </div>

            <div
                v-if="fiscalTab === 'certificado'"
                class="mt-4"
                data-test="client-fiscal-certificado"
            >
                <CredentialsRailCard
                    :certificate="certificate"
                    @open-upload="uploadOpen = true"
                    @open-portal="portalOpen = true"
                />

                <CertificateUploadModal
                    v-model:open="uploadOpen"
                    :client-id="client.id"
                />

                <PortalPasswordModal
                    v-model:open="portalOpen"
                    :client-id="client.id"
                />
            </div>
        </div>
    </div>
</template>
