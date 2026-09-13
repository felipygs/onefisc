<script setup lang="ts">
import {
    VisArea,
    VisAxis,
    VisCrosshair,
    VisLine,
    VisTooltip,
    VisXYContainer,
} from '@unovis/vue';
import { useElementSize } from '@vueuse/core';
import { format } from 'date-fns';
import { computed, useTemplateRef } from 'vue';

export interface DocumentChartPoint {
    date: string;
    amount: number;
}

const cardRef = useTemplateRef<HTMLElement | null>('cardRef');

const props = withDefaults(
    defineProps<{
        points: DocumentChartPoint[];
        loading?: boolean;
    }>(),
    { loading: false },
);

const { width } = useElementSize(cardRef);

const data = computed(() =>
    props.points.map((point) => ({
        date: new Date(`${point.date}T12:00:00`),
        amount: point.amount,
    })),
);

type DataRecord = {
    date: Date;
    amount: number;
};

const x = (_: DataRecord, i: number): number => i;
const y = (d: DataRecord): number => d.amount;

const total = computed(() =>
    data.value.reduce((acc: number, { amount }) => acc + amount, 0),
);

const isEmpty = computed(
    () => !props.loading && data.value.every((point) => point.amount === 0),
);

function formatDate(date: Date): string {
    return format(date, 'd MMM');
}

function xTicks(i: number): string {
    if (i === 0 || i === data.value.length - 1 || !data.value[i]) {
        return '';
    }

    const entry = data.value[i];

    return entry ? formatDate(entry.date) : '';
}

function template(d: DataRecord): string {
    return `${formatDate(d.date)}: ${d.amount.toLocaleString('pt-BR')} doc(s)`;
}
</script>

<template>
    <UCard
        ref="cardRef"
        :ui="{ root: 'overflow-visible', body: 'px-0! pt-0! pb-3!' }"
        data-test="documents-chart"
    >
        <template #header>
            <div>
                <p class="text-muted mb-1.5 text-xs uppercase">
                    Documentos por dia
                </p>
                <p class="text-highlighted text-3xl font-semibold tabular-nums">
                    {{ total.toLocaleString('pt-BR') }}
                </p>
            </div>
        </template>

        <USkeleton
            v-if="loading"
            class="mx-4 h-96"
            data-test="documents-chart-loading"
        />

        <UEmpty
            v-else-if="isEmpty"
            icon="i-lucide-chart-line"
            title="Sem documentos no período"
            description="Não há documentos emitidos neste recorte."
        />

        <VisXYContainer
            v-else
            :data="data"
            :padding="{ top: 40 }"
            :margin="{ left: -5, right: -5 }"
            class="h-96"
            :width="width"
        >
            <VisLine :x="x" :y="y" color="var(--ui-primary)" />
            <VisArea :x="x" :y="y" color="var(--ui-primary)" :opacity="0.1" />

            <VisAxis type="x" :x="x" :tick-format="xTicks" />

            <VisCrosshair
                color="var(--ui-primary)"
                :x="x"
                :y="[y]"
                :template="template"
            />

            <VisTooltip />
        </VisXYContainer>
    </UCard>
</template>

<style scoped>
.unovis-xy-container {
    --vis-crosshair-line-stroke-color: var(--ui-primary);
    --vis-crosshair-circle-stroke-color: var(--ui-bg);

    --vis-axis-grid-color: var(--ui-border);
    --vis-axis-tick-color: var(--ui-border);
    --vis-axis-tick-label-color: var(--ui-text-dimmed);

    --vis-tooltip-background-color: var(--ui-bg);
    --vis-tooltip-border-color: var(--ui-border);
    --vis-tooltip-text-color: var(--ui-text-highlighted);
}
</style>
