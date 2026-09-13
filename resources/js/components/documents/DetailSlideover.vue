<script setup lang="ts">
import { computed } from 'vue';
import type { FiscalDocumentRow } from '@/types/documents';

const props = defineProps<{
    document: FiscalDocumentRow | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const toast = useToast();

type BadgeColor =
    | 'primary'
    | 'secondary'
    | 'success'
    | 'info'
    | 'warning'
    | 'error'
    | 'neutral';

const FAMILY_LABELS: Record<string, string> = {
    nfe: 'NF-e',
    cte: 'CT-e',
    nfse: 'NFS-e',
};

const STATUS_COLORS: Record<string, BadgeColor> = {
    authorized: 'success',
    cancelled: 'error',
    denied: 'error',
    pending: 'warning',
};

const ORIGIN_LABELS: Record<string, string> = {
    distribuicao: 'Distribuição SEFAZ',
    portal: 'Portal do Emissor Nacional',
};

const title = computed<string>(() => {
    const doc = props.document;

    if (!doc) {
        return 'Detalhe do documento';
    }

    const family = FAMILY_LABELS[doc.family] ?? doc.family;
    const number = doc.number
        ? doc.series
            ? `${doc.number}/${doc.series}`
            : doc.number
        : (doc.key?.slice(-8) ?? '');

    return number ? `${family} ${number}` : `${family} — detalhe`;
});

const availableSections = computed<string[]>(() => {
    const sections = ['Identificação', 'Emitente', 'Chave de acesso'];

    if (props.document?.has_xml) {
        sections.push('XML completo');
    }

    if (props.document?.has_danfe) {
        sections.push(
            props.document.family === 'nfse' ? 'DANFSe' : 'DANFE',
        );
    }

    return sections;
});

const pendingSections = computed<string[]>(() => {
    const sections: string[] = [];

    if (props.document && !props.document.has_xml) {
        sections.push('XML completo');
    }

    if (
        props.document &&
        !props.document.has_danfe &&
        props.document.family !== 'cte'
    ) {
        sections.push(
            props.document.family === 'nfse' ? 'DANFSe' : 'DANFE',
        );
    }

    return sections;
});

async function copyKey(): Promise<void> {
    const key = props.document?.key;

    if (!key) {
        return;
    }

    try {
        await navigator.clipboard.writeText(key);
        toast.add({
            title: 'Chave copiada.',
            description: 'A chave de acesso está na área de transferência.',
        });
    } catch {
        toast.add({
            title: 'Não foi possível copiar.',
            description: 'Copie a chave manualmente.',
        });
    }
}
</script>

<template>
    <USlideover
        v-model:open="open"
        :title="title"
        data-test="document-detail-slideover"
    >
        <template #body>
            <div v-if="document" class="flex flex-col gap-5">
                <div class="flex flex-wrap items-center gap-2">
                    <UBadge
                        :color="STATUS_COLORS[document.status ?? ''] ?? 'neutral'"
                        variant="subtle"
                        data-test="document-detail-status"
                    >
                        {{ document.status_label }}
                    </UBadge>
                    <UBadge
                        color="info"
                        variant="subtle"
                        data-test="document-detail-family"
                    >
                        {{ FAMILY_LABELS[document.family] ?? document.family }}
                    </UBadge>
                    <UBadge
                        v-if="document.derived_from_key"
                        color="neutral"
                        variant="subtle"
                        data-test="document-detail-derived"
                    >
                        Derivado
                    </UBadge>
                </div>

                <div data-test="document-detail-issuer">
                    <p class="text-muted text-xs font-medium uppercase">
                        Emitente / Prestador
                    </p>
                    <p class="mt-1 text-sm">
                        {{ document.issuer_name ?? '—' }}
                    </p>
                    <p
                        v-if="document.issuer_tax_id"
                        class="text-muted text-xs tabular-nums"
                    >
                        {{ document.issuer_tax_id }}
                    </p>
                </div>

                <div data-test="document-detail-recipient">
                    <p class="text-muted text-xs font-medium uppercase">
                        Destinatário / Tomador
                    </p>
                    <p class="mt-1 text-sm">
                        {{ document.recipient_name ?? '—' }}
                    </p>
                </div>

                <div v-if="document.key">
                    <p class="text-muted text-xs font-medium uppercase">
                        Chave de acesso
                    </p>
                    <div class="mt-1 flex items-start gap-2">
                        <code
                            class="bg-elevated min-w-0 flex-1 rounded-md px-2 py-1.5 font-mono text-xs break-all"
                            data-test="document-detail-key"
                        >
                            {{ document.key }}
                        </code>
                        <UButton
                            icon="i-lucide-copy"
                            color="neutral"
                            variant="ghost"
                            size="sm"
                            aria-label="Copiar chave de acesso"
                            data-test="document-detail-copy-key"
                            @click="copyKey"
                        />
                    </div>
                </div>

                <div data-test="document-detail-completeness">
                    <p class="text-muted text-xs font-medium uppercase">
                        Completude
                    </p>
                    <UBadge
                        :color="document.has_xml ? 'success' : 'warning'"
                        variant="subtle"
                        class="mt-1"
                        data-test="document-detail-xml-badge"
                    >
                        {{
                            document.has_xml
                                ? 'XML completo guardado'
                                : 'XML pendente'
                        }}
                    </UBadge>
                </div>

                <div data-test="document-detail-provenance">
                    <p class="text-muted text-xs font-medium uppercase">
                        Proveniência
                    </p>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <UBadge
                            color="neutral"
                            variant="subtle"
                            data-test="document-detail-origin-seal"
                        >
                            {{
                                document.derived_from_key
                                    ? 'XML derivado'
                                    : 'Selo ADN'
                            }}
                        </UBadge>
                        <span
                            v-if="document.origin"
                            class="text-muted text-xs"
                            data-test="document-detail-origin-channel"
                        >
                            {{
                                ORIGIN_LABELS[document.origin] ??
                                document.origin
                            }}
                        </span>
                    </div>
                </div>

                <div data-test="document-detail-available">
                    <p class="text-muted text-xs font-medium uppercase">
                        Seções disponíveis
                    </p>
                    <ul class="mt-1 flex flex-col gap-1">
                        <li
                            v-for="section in availableSections"
                            :key="section"
                            class="flex items-center gap-2 text-sm"
                        >
                            <UIcon
                                name="i-lucide-check"
                                class="text-success size-4"
                            />
                            {{ section }}
                        </li>
                    </ul>
                </div>

                <div
                    v-if="pendingSections.length > 0"
                    data-test="document-detail-pending"
                >
                    <p class="text-muted text-xs font-medium uppercase">
                        Seções pendentes
                    </p>
                    <ul class="mt-1 flex flex-col gap-1">
                        <li
                            v-for="section in pendingSections"
                            :key="section"
                            class="text-muted flex items-center gap-2 text-sm"
                        >
                            <UIcon
                                name="i-lucide-clock"
                                class="size-4"
                            />
                            {{ section }}
                        </li>
                    </ul>
                </div>
            </div>
        </template>
    </USlideover>
</template>
