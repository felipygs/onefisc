<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PlanLimitWarning from '@/components/PlanLimitWarning.vue';
import {
    destroy,
    edit,
    index as clientsIndex,
} from '@/routes/clients';

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
];

const activeTab = ref('dados');

const page = usePage();

const canOperate = computed<boolean>(
    () => page.props.permissions?.['operate'] === true,
);

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

        <UTabs
            v-model="activeTab"
            :items="TABS"
            data-test="client-tabs"
        />

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

        <UCard v-if="activeTab === 'historico'" data-test="client-tab-historico">
            <UTimeline :items="timelineItems" />
        </UCard>
    </div>
</template>
