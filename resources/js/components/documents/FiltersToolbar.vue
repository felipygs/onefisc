<script setup lang="ts">
import type { Table } from '@tanstack/table-core';
import { router } from '@inertiajs/vue3';
import { upperFirst } from 'scule';
import {
    computed,
    onMounted,
    onUnmounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { all as documentsAll } from '@/routes/documents';
import type {
    DocumentFilters,
    DocumentOrigin,
    FiscalDocumentRow,
    FiscalFamily,
} from '@/types/documents';

const props = withDefaults(
    defineProps<{
        filters: DocumentFilters;
        tableApi: Table<FiscalDocumentRow> | null;
        baseUrl?: string;
    }>(),
    { baseUrl: undefined },
);

const targetUrl = computed<string>(() => props.baseUrl ?? documentsAll.url());

type Option = { label: string; value: string };

const FAMILY_OPTIONS: Option[] = [
    { label: 'Todos os tipos', value: 'all' },
    { label: 'NF-e', value: 'nfe' },
    { label: 'CT-e', value: 'cte' },
    { label: 'NFS-e', value: 'nfse' },
];

const STATUS_OPTIONS: Option[] = [
    { label: 'Todos os status', value: 'all' },
    { label: 'Autorizada', value: 'authorized' },
    { label: 'Cancelada', value: 'cancelled' },
    { label: 'Denegada', value: 'denied' },
    { label: 'Pendente', value: 'pending' },
];

const ORIGIN_OPTIONS: Option[] = [
    { label: 'Todas as origens', value: 'all' },
    { label: 'Distribuição', value: 'distribuicao' },
    { label: 'Portal', value: 'portal' },
];

const COLUMN_LABELS: Record<string, string> = {
    documento: 'Documento',
    emissao: 'Emissão',
    emitente: 'Emitente / Prestador',
    destinatario: 'Destinatário / Tomador',
    cliente: 'Cliente',
    status: 'Status',
};

const search = ref(props.filters.q);
const family = ref<string>(props.filters.family ?? 'all');
const status = ref<string>(props.filters.status ?? 'all');
const origin = ref<string>(props.filters.origin ?? 'all');

// Server echoes the applied filters back; keep locals in sync after each
// Inertia visit without clobbering the field the user is typing in.
watch(
    () => props.filters,
    (next) => {
        if (
            document.activeElement?.getAttribute('data-test') !==
            'documents-search'
        ) {
            search.value = next.q;
        }

        family.value = next.family ?? 'all';
        status.value = next.status ?? 'all';
        origin.value = next.origin ?? 'all';
    },
);

const hasActiveFilters = computed<boolean>(
    () =>
        search.value.trim() !== '' ||
        family.value !== 'all' ||
        status.value !== 'all' ||
        origin.value !== 'all',
);

const activeFilterCount = computed<number>(
    () =>
        (family.value !== 'all' ? 1 : 0) +
        (status.value !== 'all' ? 1 : 0) +
        (origin.value !== 'all' ? 1 : 0),
);

function currentParams(): Record<string, string> {
    return {
        ...(props.filters.sort !== 'emissao'
            ? { sort: props.filters.sort }
            : {}),
        ...(props.filters.dir !== 'desc' ? { dir: props.filters.dir } : {}),
    };
}

function apply(): void {
    const term = search.value.trim();

    router.get(
        targetUrl.value,
        {
            ...currentParams(),
            ...(term !== '' ? { q: term } : {}),
            ...(family.value !== 'all'
                ? { family: family.value as FiscalFamily }
                : {}),
            ...(status.value !== 'all' ? { status: status.value } : {}),
            ...(origin.value !== 'all'
                ? { origin: origin.value as DocumentOrigin }
                : {}),
        },
        { preserveScroll: true, preserveState: true },
    );
}

function clear(): void {
    search.value = '';
    family.value = 'all';
    status.value = 'all';
    origin.value = 'all';
    apply();
}

const searchField = useTemplateRef('searchField');

function focusSearch(): void {
    const el = searchField.value as unknown as {
        $el?: HTMLElement;
    } | null;

    el?.$el?.querySelector('input')?.focus();
}

function onWindowKeydown(event: KeyboardEvent): void {
    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) {
        return;
    }

    const target = event.target as HTMLElement | null;

    if (
        target &&
        (target.tagName === 'INPUT' ||
            target.tagName === 'TEXTAREA' ||
            target.tagName === 'SELECT' ||
            target.isContentEditable)
    ) {
        return;
    }

    event.preventDefault();
    focusSearch();
}

onMounted(() => window.addEventListener('keydown', onWindowKeydown));
onUnmounted(() => window.removeEventListener('keydown', onWindowKeydown));

// Exibição menu through the table column API (house pattern from
// clients/Index): one checkbox per hideable column.
const displayItems = computed<object[]>(
    () =>
        props.tableApi
            ?.getAllColumns()
            .filter((column) => column.getCanHide())
            .map((column) => ({
                label: COLUMN_LABELS[column.id] ?? upperFirst(column.id),
                type: 'checkbox' as const,
                checked: column.getIsVisible(),
                onUpdateChecked(checked: boolean) {
                    column.toggleVisibility(!!checked);
                },
                onSelect(e?: Event) {
                    e?.preventDefault();
                },
            })) ?? [],
);
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-1.5">
        <UInput
            ref="searchField"
            v-model="search"
            class="max-w-sm"
            icon="i-lucide-search"
            placeholder="Buscar por chave, número ou emitente… ( / )"
            data-test="documents-search"
            @keydown.enter="apply"
        />

        <div class="flex flex-wrap items-center gap-1.5">
            <UPopover :content="{ align: 'end' }" :modal="true">
                <UButton
                    label="Filtros"
                    color="neutral"
                    variant="outline"
                    icon="i-lucide-list-filter"
                    data-test="documents-filters-button"
                >
                    <template v-if="activeFilterCount > 0" #trailing>
                        <UKbd data-test="documents-filters-count">
                            {{ activeFilterCount }}
                        </UKbd>
                    </template>
                </UButton>

                <template #content>
                    <div class="flex w-64 flex-col gap-3 p-4">
                        <UFormField label="Tipo" name="family">
                            <USelect
                                v-model="family"
                                :items="FAMILY_OPTIONS"
                                value-key="value"
                                class="w-full"
                                data-test="documents-filter-family"
                                @update:model-value="apply"
                            />
                        </UFormField>

                        <UFormField label="Status" name="status">
                            <USelect
                                v-model="status"
                                :items="STATUS_OPTIONS"
                                value-key="value"
                                class="w-full"
                                data-test="documents-filter-status"
                                @update:model-value="apply"
                            />
                        </UFormField>

                        <UFormField label="Origem" name="origin">
                            <USelect
                                v-model="origin"
                                :items="ORIGIN_OPTIONS"
                                value-key="value"
                                class="w-full"
                                data-test="documents-filter-origin"
                                @update:model-value="apply"
                            />
                        </UFormField>
                    </div>
                </template>
            </UPopover>

            <UDropdownMenu :items="displayItems" :content="{ align: 'end' }">
                <UButton
                    label="Exibição"
                    color="neutral"
                    variant="outline"
                    trailing-icon="i-lucide-settings-2"
                    data-test="documents-display-button"
                />
            </UDropdownMenu>

            <UButton
                v-if="hasActiveFilters"
                label="Limpar"
                color="neutral"
                variant="ghost"
                icon="i-lucide-x"
                data-test="documents-filters-clear"
                @click="clear"
            />
        </div>
    </div>
</template>
