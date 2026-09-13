<script setup lang="ts">
import { computed } from 'vue';
import { index as plansIndex } from '@/routes/plans';
import type { FiscalFamily, SyncState } from '@/types/documents';

const props = defineProps<{
    sync: SyncState;
    families: string[];
}>();

type BadgeColor =
    | 'primary'
    | 'secondary'
    | 'success'
    | 'info'
    | 'warning'
    | 'error'
    | 'neutral';

const FAMILIES: { value: FiscalFamily; label: string }[] = [
    { value: 'nfe', label: 'NF-e' },
    { value: 'cte', label: 'CT-e' },
    { value: 'nfse', label: 'NFS-e' },
];

const subscribed = computed<Set<string>>(() => new Set(props.families));

function familyColor(family: FiscalFamily): BadgeColor {
    return subscribed.value.has(family) ? 'success' : 'neutral';
}

function familyLabel(family: FiscalFamily): string {
    return subscribed.value.has(family) ? 'Ativa' : 'Não assinada';
}

function formatDate(value: string | null): string {
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
</script>

<template>
    <UCard data-test="sync-state-card">
        <template #header>
            <h2 class="text-sm font-semibold">Sincronização SEFAZ</h2>
        </template>

        <div class="flex flex-col gap-4">
            <div>
                <p class="text-muted text-xs uppercase">
                    Capacidade por família
                </p>
                <ul class="mt-2 flex flex-col gap-2">
                    <li
                        v-for="family in FAMILIES"
                        :key="family.value"
                        class="flex items-center justify-between gap-2"
                    >
                        <span class="text-sm">{{ family.label }}</span>
                        <UBadge
                            :color="familyColor(family.value)"
                            variant="soft"
                            :data-test="`sync-family-${family.value}`"
                        >
                            {{ familyLabel(family.value) }}
                        </UBadge>
                    </li>
                </ul>
            </div>

            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-muted text-xs uppercase">
                        Última execução
                    </dt>
                    <dd
                        class="mt-1 text-sm tabular-nums"
                        data-test="sync-last-run"
                    >
                        {{ formatDate(sync.last_run_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted text-xs uppercase">
                        Documentos novos
                    </dt>
                    <dd
                        class="mt-1 text-sm tabular-nums"
                        data-test="sync-new-documents"
                    >
                        {{ sync.new_documents.toLocaleString('pt-BR') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted text-xs uppercase">Próximo ciclo</dt>
                    <dd
                        class="mt-1 text-sm tabular-nums"
                        data-test="sync-next-run"
                    >
                        {{ formatDate(sync.next_run_at) }}
                    </dd>
                </div>
            </dl>

            <UAlert
                v-if="sync.blocked_until"
                color="warning"
                variant="soft"
                icon="i-lucide-pause-circle"
                title="Sincronização em pausa pela SEFAZ"
                :description="`Novas tentativas retomam após ${formatDate(sync.blocked_until)}.`"
                data-test="sync-blocked"
            />

            <UAlert
                v-if="sync.volume_exhausted"
                color="error"
                variant="soft"
                icon="i-lucide-triangle-alert"
                title="Volume mensal esgotado"
                description="Novos documentos ficam pendentes até o upgrade ou a renovação. O cursor está preservado."
                data-test="sync-volume"
            >
                <template #actions>
                    <UButton
                        size="sm"
                        color="error"
                        variant="outline"
                        :to="plansIndex.url()"
                        data-test="sync-upgrade-cta"
                    >
                        Ver planos
                    </UButton>
                </template>
            </UAlert>
        </div>
    </UCard>
</template>
