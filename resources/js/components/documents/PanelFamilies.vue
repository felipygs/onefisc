<script setup lang="ts">
import { computed } from 'vue';
import type { FiscalFamily } from '@/types/documents';

const props = defineProps<{
    families: { family: FiscalFamily; count: number }[];
    totalDocuments: number;
}>();

const FAMILY_LABELS: Record<FiscalFamily, string> = {
    nfe: 'NF-e',
    cte: 'CT-e',
    nfse: 'NFS-e',
};

const rows = computed(() =>
    props.families.map((entry) => ({
        family: entry.family,
        label: FAMILY_LABELS[entry.family],
        count: entry.count,
        share:
            props.totalDocuments > 0
                ? Math.round((entry.count / props.totalDocuments) * 100)
                : 0,
    })),
);
</script>

<template>
    <UCard data-test="documents-families">
        <template #header>
            <div>
                <h2 class="text-highlighted text-sm font-semibold">Famílias</h2>
                <p class="text-muted text-xs">
                    Documentos por modelo no período.
                </p>
            </div>
        </template>

        <ul class="flex flex-col gap-4">
            <li
                v-for="row in rows"
                :key="row.family"
                :data-test="`documents-family-${row.family}`"
            >
                <div class="mb-1.5 flex items-center justify-between gap-2">
                    <span class="text-sm font-medium">{{ row.label }}</span>
                    <span class="text-muted text-sm tabular-nums">
                        {{ row.count.toLocaleString('pt-BR') }} ·
                        {{ row.share }}%
                    </span>
                </div>
                <UProgress :model-value="row.share" size="sm" />
            </li>
        </ul>
    </UCard>
</template>
