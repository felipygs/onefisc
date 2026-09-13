<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PanelAttention from '@/components/documents/PanelAttention.vue';
import PanelCards from '@/components/documents/PanelCards.vue';
import PanelChart, {
    type DocumentChartPoint,
} from '@/components/documents/PanelChart.vue';
import PanelFamilies from '@/components/documents/PanelFamilies.vue';
import PanelRank from '@/components/documents/PanelRank.vue';
import PanelRecent from '@/components/documents/PanelRecent.vue';
import {
    all as documentsAll,
    clients as documentsClients,
    index as documentsIndex,
} from '@/routes/documents';
import type {
    DocumentsOverview,
    FiscalDocumentRow,
    FiscalFamily,
} from '@/types/documents';

const props = defineProps<{
    overview: DocumentsOverview;
    period: string;
    chart: DocumentChartPoint[];
}>();

type TabValue = 'visao' | 'mercadorias' | 'servicos' | 'operacao';

const TABS = [
    {
        label: 'Visão',
        value: 'visao' as const,
        icon: 'i-lucide-layout-dashboard',
    },
    {
        label: 'Mercadorias',
        value: 'mercadorias' as const,
        icon: 'i-lucide-package',
    },
    {
        label: 'Serviços',
        value: 'servicos' as const,
        icon: 'i-lucide-briefcase',
    },
    {
        label: 'Operação',
        value: 'operacao' as const,
        icon: 'i-lucide-list-checks',
    },
];

const PERIOD_OPTIONS = [
    { label: 'Últimos 7 dias', value: '7d' },
    { label: 'Últimos 30 dias', value: '30d' },
    { label: 'Últimos 90 dias', value: '90d' },
];

// Tabs filter the panels client-side over the server aggregates (local
// state on purpose: the interim table in All.vue is replaced by Task 4.3,
// not the tab contract). Rankings are portfolio-wide, so they only render
// on Visão; Operação surfaces chart + attention + recent.
const activeTab = ref<TabValue>('visao');
const reloading = ref(false);

const tabFamilies = computed<FiscalFamily[] | null>(() => {
    if (activeTab.value === 'mercadorias') {
        return ['nfe', 'cte'];
    }

    if (activeTab.value === 'servicos') {
        return ['nfse'];
    }

    return null;
});

const visibleFamilies = computed(() =>
    tabFamilies.value === null
        ? props.overview.families
        : props.overview.families.filter((entry) =>
              tabFamilies.value?.includes(entry.family),
          ),
);

const visibleRecent = computed<FiscalDocumentRow[]>(() =>
    tabFamilies.value === null
        ? props.overview.recent
        : props.overview.recent.filter((row) =>
              tabFamilies.value?.includes(row.family),
          ),
);

const showRank = computed(() => activeTab.value === 'visao');
const showFamilies = computed(() => activeTab.value !== 'operacao');
const showChart = computed(
    () => activeTab.value === 'visao' || activeTab.value === 'operacao',
);
const showAttention = computed(
    () => activeTab.value === 'visao' || activeTab.value === 'operacao',
);

function reloadPeriod(next: string): void {
    reloading.value = true;
    router.get(
        documentsIndex.url(),
        { period: next },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['overview', 'chart', 'period'],
            onFinish: () => {
                reloading.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Documentos" />

    <UDashboardPanel id="documents">
        <template #header>
            <UDashboardNavbar title="Documentos">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>

                <template #right>
                    <UButton
                        color="neutral"
                        variant="outline"
                        icon="i-lucide-users"
                        :to="documentsClients.url()"
                        data-test="documents-go-clients"
                    >
                        Atenção da carteira
                    </UButton>
                    <UButton
                        icon="i-lucide-files"
                        :to="documentsAll.url()"
                        data-test="documents-go-all"
                    >
                        Ver todos
                    </UButton>
                </template>
            </UDashboardNavbar>

            <UDashboardToolbar>
                <template #left>
                    <USelect
                        :model-value="period"
                        :items="PERIOD_OPTIONS"
                        value-key="value"
                        class="min-w-40"
                        data-test="documents-period"
                        @update:model-value="reloadPeriod"
                    />
                </template>
            </UDashboardToolbar>
        </template>

        <template #body>
            <UEmpty
                v-if="overview.totals.clients === 0"
                icon="i-lucide-users"
                title="Nenhum cliente ativo encontrado"
                description="Cadastre um cliente ativo para disponibilizar a consulta de documentos."
                data-test="documents-empty"
            />

            <div v-else class="flex flex-col gap-4 sm:gap-6">
                <PanelCards :overview="overview" />

                <UTabs
                    v-model="activeTab"
                    :items="TABS"
                    :content="false"
                    variant="link"
                    color="primary"
                    aria-label="Seções do painel"
                    class="w-full"
                    :ui="{
                        root: 'w-full',
                        list: 'w-full',
                        trigger:
                            'min-w-0 flex-1 justify-center gap-1.5 whitespace-nowrap px-1 sm:flex-none sm:px-3',
                        leadingIcon: 'size-5',
                    }"
                    data-test="documents-tabs"
                />

                <PanelChart
                    v-if="showChart"
                    :points="chart"
                    :loading="reloading"
                />

                <UAlert
                    v-if="showAttention && overview.attention.length > 0"
                    color="warning"
                    variant="subtle"
                    icon="i-lucide-triangle-alert"
                    :title="`${overview.attention.length.toLocaleString('pt-BR')} pendência(s) na carteira`"
                    description="Resolva certificados, sincronização e XML pendente para manter a cobertura em dia."
                    data-test="documents-attention-alert"
                />

                <PanelRank v-if="showRank" :rows="overview.rankings.clients" />

                <PanelFamilies
                    v-if="showFamilies"
                    :families="visibleFamilies"
                    :total-documents="overview.totals.documents"
                />

                <PanelRecent :rows="visibleRecent" />

                <PanelAttention
                    v-if="showAttention"
                    :rows="overview.attention"
                />
            </div>
        </template>
    </UDashboardPanel>
</template>
