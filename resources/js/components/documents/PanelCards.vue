<script setup lang="ts">
import { computed } from 'vue';
import {
    all as documentsAll,
    clients as documentsClients,
} from '@/routes/documents';
import type { DocumentsOverview } from '@/types/documents';

const props = defineProps<{
    overview: DocumentsOverview;
}>();

function metric(value: number): string {
    return value.toLocaleString('pt-BR');
}

const cards = computed(() => [
    {
        test: 'documents-card-clients',
        title: 'Clientes ativos',
        icon: 'i-lucide-users',
        value: metric(props.overview.totals.clients),
        description: `${metric(props.overview.totals.clients_with_documents)} com documentos no período`,
        color: 'text-highlighted',
        leading: 'bg-primary/10 ring-primary/25',
        to: documentsClients.url(),
    },
    {
        test: 'documents-card-documents',
        title: 'Documentos',
        icon: 'i-lucide-files',
        value: metric(props.overview.totals.documents),
        description: 'Indexados no período',
        color: 'text-highlighted',
        leading: 'bg-info/10 ring-info/25',
        to: documentsAll.url(),
    },
    {
        test: 'documents-card-pending-xml',
        title: 'XML pendente',
        icon: 'i-lucide-file-warning',
        value: metric(props.overview.totals.pending_xml),
        description: 'Resumos aguardando XML completo',
        color:
            props.overview.totals.pending_xml > 0
                ? 'text-warning'
                : 'text-highlighted',
        leading: 'bg-warning/10 ring-warning/25',
        to: documentsAll.url(),
    },
    {
        test: 'documents-card-attention',
        title: 'Atenção na sync',
        icon: 'i-lucide-triangle-alert',
        value: metric(props.overview.totals.sync_attention),
        description:
            props.overview.totals.certificates_expiring > 0
                ? `${metric(props.overview.totals.certificates_expiring)} certificado(s) vencendo`
                : 'Falha, cobertura ou sem certificado',
        color:
            props.overview.totals.sync_attention > 0
                ? 'text-error'
                : 'text-highlighted',
        leading: 'bg-error/10 ring-error/25',
        to: documentsClients.url(),
    },
]);
</script>

<template>
    <UPageGrid
        class="gap-2.5 sm:gap-3 lg:grid-cols-4 lg:gap-px"
        data-test="documents-cards"
    >
        <UPageCard
            v-for="card in cards"
            :key="card.title"
            :icon="card.icon"
            :title="card.title"
            :to="card.to"
            variant="subtle"
            :ui="{
                root: 'p-3 sm:p-3.5',
                container: 'gap-y-1',
                wrapper: 'items-start gap-x-2',
                leading: `p-1.5 rounded-full ring ring-inset flex-col ${card.leading}`,
                leadingIcon: 'size-4',
                title: 'font-normal text-muted text-xs uppercase leading-tight tracking-wide',
            }"
            class="first:rounded-l-lg last:rounded-r-lg hover:z-1 lg:rounded-none"
            :data-test="card.test"
        >
            <div class="flex min-w-0 flex-col gap-0.5">
                <span
                    class="text-xl leading-none font-semibold tabular-nums"
                    :class="card.color"
                >
                    {{ card.value }}
                </span>
                <span
                    class="text-muted truncate text-xs leading-snug"
                    :title="card.description"
                >
                    {{ card.description }}
                </span>
            </div>
        </UPageCard>
    </UPageGrid>
</template>
