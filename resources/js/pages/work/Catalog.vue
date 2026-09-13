<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import WorkChildNav from '@/components/work/WorkChildNav.vue';
import {
    index as catalogIndex,
    show as catalogShow,
} from '@/routes/work/catalog';
import { create as createProcess } from '@/routes/work/processes';

// Contrato de leitura do Prontos (Task 4.2, mesma branch): `GET
// /work/marketplace` devolve `{listings: [{id, title, description, category,
// task_count, added, added_process_id}]}` e a instalação é `POST
// /work/marketplace/{listing}/install`. O backend do marketplace ainda não
// existe aqui, então não há helper Wayfinder para ele — a URL literal segue
// o contrato e a aba trata qualquer ausência como estado vazio honesto.
const MARKETPLACE_BASE = '/work/marketplace';

interface CatalogProcess {
    id: number;
    title: string;
    description: string | null;
    status: string;
    source: string;
    clients_count: number;
    task_count: number;
    client_ids: number[];
}

interface MarketplaceListing {
    id: number;
    title: string;
    description: string | null;
    category: string | null;
    task_count: number;
    added: boolean;
    added_process_id: number | null;
}

const props = withDefaults(
    defineProps<{
        search?: string;
        processes?: CatalogProcess[];
    }>(),
    {
        search: '',
        processes: () => [],
    },
);

type CatalogTab = 'conta' | 'prontos';
type ViewMode = 'cards' | 'lista';

const TAB_KEY = 'work.catalog.tab.v1';
const VIEW_KEY = 'work.catalog.view.v1';

function loadStored<T extends string>(
    key: string,
    fallback: T,
    allowed: T[],
): T {
    if (typeof window === 'undefined') {
        return fallback;
    }

    try {
        const raw = window.localStorage.getItem(key);
        return raw !== null && (allowed as string[]).includes(raw)
            ? (raw as T)
            : fallback;
    } catch {
        return fallback;
    }
}

function store(key: string, value: string): void {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Armazenamento indisponível: a aba segue funcional na sessão.
    }
}

const activeTab = ref<CatalogTab>(
    loadStored(TAB_KEY, 'conta', ['conta', 'prontos']),
);
const viewMode = ref<ViewMode>(
    loadStored(VIEW_KEY, 'cards', ['cards', 'lista']),
);

function selectTab(tab: CatalogTab): void {
    activeTab.value = tab;
    store(TAB_KEY, tab);

    if (tab === 'prontos') {
        void ensureListings();
    }
}

function selectView(mode: ViewMode): void {
    viewMode.value = mode;
    store(VIEW_KEY, mode);
}

// Busca server-side por título (?search=), com debounce — mesmo padrão da
// visão processo.
const searchInput = ref(props.search);
let searchTimer: number | undefined;

watch(searchInput, (value) => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        const trimmed = value.trim();
        router.get(
            catalogIndex.url(),
            trimmed === '' ? {} : { search: trimmed },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 350);
});

// Prontos: três estados honestos — `checking` (sondando), `down` (endpoint
// ausente ou erro ⇒ vazio honesto até a 4.2) e `up` (lista real da 4.2,
// possivelmente vazia).
type MarketplaceState = 'checking' | 'down' | 'up';

const marketplaceState = ref<MarketplaceState>('checking');
const listings = ref<MarketplaceListing[]>([]);
const listingsChecked = ref(false);
const installingId = ref<number | null>(null);

async function ensureListings(): Promise<void> {
    if (listingsChecked.value) {
        return;
    }

    listingsChecked.value = true;

    try {
        const response = await fetch(MARKETPLACE_BASE, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            marketplaceState.value = 'down';
            return;
        }

        const payload = (await response.json()) as {
            listings?: MarketplaceListing[];
        };
        listings.value = Array.isArray(payload.listings)
            ? payload.listings
            : [];
        marketplaceState.value = 'up';
    } catch {
        marketplaceState.value = 'down';
    }
}

const prontosSearch = ref('');
const prontosCategory = ref('all');

const categories = computed<string[]>(() => {
    const seen = new Set<string>();

    for (const listing of listings.value) {
        if (listing.category !== null && listing.category !== '') {
            seen.add(listing.category);
        }
    }

    return [...seen].sort((a, b) => a.localeCompare(b, 'pt-BR'));
});

const filteredListings = computed<MarketplaceListing[]>(() => {
    const term = prontosSearch.value.trim().toLowerCase();

    return listings.value.filter((listing) => {
        if (
            prontosCategory.value !== 'all' &&
            listing.category !== prontosCategory.value
        ) {
            return false;
        }

        if (term !== '' && !listing.title.toLowerCase().includes(term)) {
            return false;
        }

        return true;
    });
});

function installUrl(listing: MarketplaceListing): string {
    return `${MARKETPLACE_BASE}/${listing.id}/install`;
}

function install(listing: MarketplaceListing): void {
    if (
        marketplaceState.value !== 'up' ||
        listing.added ||
        installingId.value !== null
    ) {
        return;
    }

    installingId.value = listing.id;
    router.post(
        installUrl(listing),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                installingId.value = null;
            },
        },
    );
}

function editorUrl(processId: number): string {
    return catalogShow.url({ process: processId });
}

function processStatusLabel(status: string): string {
    return status === 'archived' ? 'Arquivado' : 'Ativo';
}

function sourceLabel(source: string): string {
    return source === 'marketplace' ? 'Marketplace' : 'Manual';
}
</script>

<template>
    <Head title="Catálogo" />

    <UDashboardPanel id="work-catalog">
        <template #header>
            <UDashboardNavbar title="Catálogo">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <div class="flex flex-col gap-4">
                <p class="text-muted text-sm">
                    Processos operacionais da Account e modelos prontos do
                    marketplace.
                </p>

                <WorkChildNav :active="'catalogo'" />

                <UTabs
                    :model-value="activeTab"
                    :items="[
                        { label: 'Na conta', value: 'conta' },
                        { label: 'Prontos', value: 'prontos' },
                    ]"
                    data-test="work-catalog-tabs"
                    @update:model-value="selectTab($event as CatalogTab)"
                />

                <!-- Aba Na conta -->
                <div v-if="activeTab === 'conta'" class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <UInput
                            v-model="searchInput"
                            class="max-w-sm flex-1"
                            icon="i-lucide-search"
                            placeholder="Buscar por título…"
                            data-test="work-catalog-search"
                        />
                        <div
                            class="flex items-center gap-1"
                            role="group"
                            aria-label="Modo de exibição"
                        >
                            <UButton
                                color="neutral"
                                :variant="
                                    viewMode === 'cards' ? 'solid' : 'ghost'
                                "
                                size="sm"
                                icon="i-lucide-layout-grid"
                                aria-label="Exibir como cartões"
                                data-test="work-catalog-view-cards"
                                @click="selectView('cards')"
                            />
                            <UButton
                                color="neutral"
                                :variant="
                                    viewMode === 'lista' ? 'solid' : 'ghost'
                                "
                                size="sm"
                                icon="i-lucide-list"
                                aria-label="Exibir como lista"
                                data-test="work-catalog-view-lista"
                                @click="selectView('lista')"
                            />
                        </div>
                        <UButton
                            :to="createProcess.url()"
                            icon="i-lucide-plus"
                            data-test="work-catalog-new"
                        >
                            Novo processo manual
                        </UButton>
                    </div>

                    <ul
                        v-if="processes.length > 0"
                        :class="
                            viewMode === 'cards'
                                ? 'grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3'
                                : 'flex flex-col gap-2'
                        "
                        data-test="work-catalog-list"
                    >
                        <li
                            v-for="process in processes"
                            :key="process.id"
                            :data-test="`work-catalog-row-${process.id}`"
                            class="border-default rounded-lg border p-3"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="min-w-0 flex-1 truncate font-medium"
                                >
                                    {{ process.title }}
                                </span>
                                <UBadge color="neutral" variant="subtle">
                                    {{ sourceLabel(process.source) }}
                                </UBadge>
                                <UBadge
                                    :color="
                                        process.status === 'active'
                                            ? 'success'
                                            : 'neutral'
                                    "
                                    variant="subtle"
                                >
                                    {{ processStatusLabel(process.status) }}
                                </UBadge>
                            </div>
                            <p
                                v-if="process.description"
                                class="text-muted mt-1 line-clamp-2 text-sm"
                            >
                                {{ process.description }}
                            </p>
                            <div
                                class="text-muted mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs"
                            >
                                <span>
                                    {{ process.clients_count }}
                                    {{
                                        process.clients_count === 1
                                            ? 'empresa'
                                            : 'empresas'
                                    }}
                                </span>
                                <span>
                                    {{ process.task_count }}
                                    {{
                                        process.task_count === 1
                                            ? 'tarefa'
                                            : 'tarefas'
                                    }}
                                </span>
                            </div>
                            <UButton
                                color="neutral"
                                variant="ghost"
                                size="sm"
                                class="mt-2"
                                :to="editorUrl(process.id)"
                                :data-test="`work-catalog-open-${process.id}`"
                            >
                                Abrir editor
                            </UButton>
                        </li>
                    </ul>

                    <UPageCard
                        v-else-if="search.trim() !== ''"
                        title="Nenhum resultado"
                        :description="`Nada encontrado para “${search.trim()}” nos processos da Account.`"
                        icon="i-lucide-search-x"
                        data-test="work-catalog-empty-search"
                    />
                    <UPageCard
                        v-else
                        title="Nenhum processo na Account"
                        description="Crie um processo manual para começar a organizar o trabalho."
                        icon="i-lucide-briefcase"
                        data-test="work-catalog-empty"
                    />
                </div>

                <!-- Aba Prontos -->
                <div v-else class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <UInput
                            v-model="prontosSearch"
                            class="max-w-sm flex-1"
                            icon="i-lucide-search"
                            placeholder="Buscar modelo pronto…"
                            data-test="work-prontos-search"
                        />
                        <USelect
                            v-model="prontosCategory"
                            :items="[
                                { label: 'Todas as categorias', value: 'all' },
                                ...categories.map((category) => ({
                                    label: category,
                                    value: category,
                                })),
                            ]"
                            class="w-56"
                            aria-label="Filtrar por categoria"
                            data-test="work-prontos-category"
                        />
                    </div>

                    <ul
                        v-if="
                            marketplaceState === 'up' &&
                            filteredListings.length > 0
                        "
                        class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3"
                        data-test="work-prontos-list"
                    >
                        <li
                            v-for="listing in filteredListings"
                            :key="listing.id"
                            :data-test="`work-prontos-row-${listing.id}`"
                            class="border-default rounded-lg border p-3"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="min-w-0 flex-1 truncate font-medium"
                                >
                                    {{ listing.title }}
                                </span>
                                <UBadge
                                    v-if="listing.category"
                                    color="neutral"
                                    variant="subtle"
                                >
                                    {{ listing.category }}
                                </UBadge>
                            </div>
                            <p
                                v-if="listing.description"
                                class="text-muted mt-1 line-clamp-2 text-sm"
                            >
                                {{ listing.description }}
                            </p>
                            <p class="text-muted mt-2 text-xs">
                                {{ listing.task_count }}
                                {{
                                    listing.task_count === 1
                                        ? 'tarefa'
                                        : 'tarefas'
                                }}
                            </p>
                            <UButton
                                v-if="
                                    listing.added &&
                                    listing.added_process_id !== null
                                "
                                color="neutral"
                                variant="outline"
                                size="sm"
                                class="mt-2"
                                :to="editorUrl(listing.added_process_id)"
                                :data-test="`work-prontos-open-${listing.id}`"
                            >
                                Já adicionado — abrir
                            </UButton>
                            <UButton
                                v-else
                                size="sm"
                                class="mt-2"
                                icon="i-lucide-plus"
                                :loading="installingId === listing.id"
                                :data-test="`work-prontos-install-${listing.id}`"
                                @click="install(listing)"
                            >
                                Adicionar
                            </UButton>
                        </li>
                    </ul>

                    <UPageCard
                        v-else-if="marketplaceState === 'checking'"
                        title="Carregando modelos prontos…"
                        description="Buscando os modelos publicados no marketplace."
                        icon="i-lucide-loader-circle"
                        data-test="work-prontos-loading"
                    />
                    <UPageCard
                        v-else-if="
                            marketplaceState === 'up' &&
                            listings.length > 0 &&
                            filteredListings.length === 0
                        "
                        title="Nenhum modelo com este filtro"
                        description="Ajuste a busca ou a categoria para ver outros modelos prontos."
                        icon="i-lucide-search-x"
                        data-test="work-prontos-empty-filter"
                    />
                    <UPageCard
                        v-else
                        title="Nenhum modelo pronto por aqui"
                        description="Ainda não há modelos publicados no marketplace para esta Account."
                        icon="i-lucide-package-open"
                        data-test="work-prontos-empty"
                    />
                    <p
                        v-if="marketplaceState === 'down'"
                        class="text-muted text-xs"
                        data-test="work-prontos-disabled-note"
                    >
                        O botão Adicionar fica indisponível enquanto o
                        marketplace não está publicado.
                    </p>
                </div>
            </div>
        </template>
    </UDashboardPanel>
</template>
