<script setup lang="ts">
import { eachDayOfInterval } from 'date-fns';
import { computed, watch } from 'vue';
import type { HomePeriod, HomeRange } from '@/types/home';

const model = defineModel<HomePeriod>({ required: true });

const props = defineProps<{
    range: HomeRange;
}>();

const PERIOD_LABELS: Record<HomePeriod, string> = {
    daily: 'Diário',
    weekly: 'Semanal',
    monthly: 'Mensal',
};

const days = computed(() => eachDayOfInterval(props.range));

const periods = computed<HomePeriod[]>(() => {
    if (days.value.length <= 8) {
        return ['daily'];
    }

    if (days.value.length <= 31) {
        return ['daily', 'weekly'];
    }

    return ['weekly', 'monthly'];
});

const items = computed(() =>
    periods.value.map((period) => ({
        label: PERIOD_LABELS[period],
        value: period,
    })),
);

// Ensure the model value is always a valid period.
watch(periods, () => {
    if (!periods.value.includes(model.value)) {
        model.value = periods.value[0] ?? 'daily';
    }
});
</script>

<template>
    <USelect
        v-model="model"
        :items="items"
        value-key="value"
        variant="ghost"
        class="data-[state=open]:bg-elevated"
        :ui="{
            value: 'capitalize',
            itemLabel: 'capitalize',
            trailingIcon:
                'group-data-[state=open]:rotate-180 transition-transform duration-200',
        }"
        data-test="home-period-select"
    />
</template>
