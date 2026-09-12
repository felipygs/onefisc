<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PlanLimitWarning from '@/components/PlanLimitWarning.vue';
import { create, edit, index as clientsIndex, show } from '@/routes/clients';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Clients',
                href: clientsIndex(),
            },
        ],
    },
});

interface ClientRow {
    id: number;
    cnpj: string;
    razao_social: string;
    regime: string;
    contador_responsavel: string;
}

interface ClientsPaginator {
    data: ClientRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const props = defineProps<{
    clients: ClientsPaginator;
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

const REGIME_OPTIONS = [
    { label: 'Todos os regimes', value: 'all' },
    { label: 'Simples Nacional', value: 'simples' },
    { label: 'Lucro Presumido', value: 'presumido' },
    { label: 'Lucro Real', value: 'real' },
    { label: 'MEI', value: 'mei' },
];

const search = ref('');
const regimeFilter = ref('all');

const page = usePage();

const canOperate = computed<boolean>(
    () => page.props.permissions?.['operate'] === true,
);

function formatCnpj(value: string): string {
    const digits = value.replace(/\D/g, '').slice(0, 14);

    if (digits.length <= 2) {
        return digits;
    }

    if (digits.length <= 5) {
        return `${digits.slice(0, 2)}.${digits.slice(2)}`;
    }

    if (digits.length <= 8) {
        return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5)}`;
    }

    if (digits.length <= 12) {
        return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8)}`;
    }

    return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8, 12)}-${digits.slice(12)}`;
}

function regimeLabel(regime: string): string {
    return REGIME_LABELS[regime] ?? regime;
}

function regimeColor(regime: string): BadgeColor {
    return REGIME_COLORS[regime] ?? 'neutral';
}

// Local-only filter over the current server page (no backend change).
const filtered = computed<ClientRow[]>(() => {
    const term = search.value.trim().toLowerCase();
    const digits = search.value.replace(/\D/g, '');

    return props.clients.data.filter((client) => {
        if (
            regimeFilter.value !== 'all' &&
            client.regime !== regimeFilter.value
        ) {
            return false;
        }

        if (term === '') {
            return true;
        }

        if (digits !== '' && client.cnpj.includes(digits)) {
            return true;
        }

        return (
            client.razao_social.toLowerCase().includes(term) ||
            client.contador_responsavel.toLowerCase().includes(term)
        );
    });
});

function goToPage(nextPage: number): void {
    router.get(
        clientsIndex.url(),
        { page: nextPage },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Clients" />

    <div class="flex flex-col gap-6 p-4">
        <PlanLimitWarning />

        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Clients</h1>
                <p class="text-muted text-sm">
                    Empresas atendidas pelo escritório.
                </p>
            </div>
            <UButton
                v-if="canOperate"
                :to="create.url()"
                data-test="client-new"
            >
                Novo client
            </UButton>
        </div>

        <UCard>
            <div class="mb-4 flex flex-col gap-3 sm:flex-row">
                <UInput
                    v-model="search"
                    placeholder="Buscar por CNPJ, razão ou contador…"
                    class="w-full sm:max-w-sm"
                    data-test="client-search"
                />
                <USelect
                    v-model="regimeFilter"
                    :items="REGIME_OPTIONS"
                    class="w-full sm:w-56"
                    data-test="client-regime-filter"
                />
            </div>

            <div
                v-if="filtered.length === 0"
                class="text-muted py-8 text-center text-sm"
                data-test="client-empty"
            >
                Nenhum client encontrado.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr
                            class="border-default text-muted border-b text-xs uppercase"
                        >
                            <th class="px-2 py-2 font-medium">CNPJ</th>
                            <th class="px-2 py-2 font-medium">Razão social</th>
                            <th class="px-2 py-2 font-medium">Regime</th>
                            <th class="px-2 py-2 font-medium">Contador</th>
                            <th class="px-2 py-2 font-medium">Monitoramento</th>
                            <th class="px-2 py-2 text-right font-medium">
                                Ações
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="client in filtered"
                            :key="client.id"
                            class="border-default border-b last:border-0"
                            :data-test="`client-row-${client.id}`"
                        >
                            <td
                                class="px-2 py-3 whitespace-nowrap tabular-nums"
                            >
                                {{ formatCnpj(client.cnpj) }}
                            </td>
                            <td class="px-2 py-3">
                                <Link
                                    :href="show.url({ client: client.id })"
                                    class="text-primary hover:underline"
                                    :data-test="`client-open-${client.id}`"
                                >
                                    {{ client.razao_social }}
                                </Link>
                            </td>
                            <td class="px-2 py-3">
                                <UBadge
                                    :color="regimeColor(client.regime)"
                                    variant="soft"
                                >
                                    {{ regimeLabel(client.regime) }}
                                </UBadge>
                            </td>
                            <td class="px-2 py-3">
                                {{ client.contador_responsavel }}
                            </td>
                            <td
                                class="text-muted px-2 py-3"
                                data-test="client-monitoring-none"
                            >
                                —
                            </td>
                            <td class="px-2 py-3 text-right whitespace-nowrap">
                                <UButton
                                    variant="ghost"
                                    size="xs"
                                    :to="show.url({ client: client.id })"
                                    :data-test="`client-show-${client.id}`"
                                >
                                    Ver
                                </UButton>
                                <UButton
                                    v-if="canOperate"
                                    variant="ghost"
                                    size="xs"
                                    :to="edit.url({ client: client.id })"
                                    :data-test="`client-edit-${client.id}`"
                                >
                                    Editar
                                </UButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="clients.last_page > 1" class="mt-4 flex justify-center">
                <UPagination
                    :page="clients.current_page"
                    :items-per-page="clients.per_page"
                    :total="clients.total"
                    data-test="client-pagination"
                    @update:page="goToPage"
                />
            </div>
        </UCard>
    </div>
</template>
