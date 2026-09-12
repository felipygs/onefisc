<script setup lang="ts">
import {
    CalendarDate,
    DateFormatter,
    getLocalTimeZone,
    today,
} from '@internationalized/date';
import { computed } from 'vue';
import type { HomeRange } from '@/types/home';

const df = new DateFormatter('pt-BR', {
    dateStyle: 'medium',
});

const selected = defineModel<HomeRange>({ required: true });

const ranges = [
    { label: 'Últimos 7 dias', days: 7 },
    { label: 'Últimos 14 dias', days: 14 },
    { label: 'Últimos 30 dias', days: 30 },
    { label: 'Últimos 3 meses', months: 3 },
    { label: 'Últimos 6 meses', months: 6 },
    { label: 'Último ano', years: 1 },
];

function toCalendarDate(date: Date): CalendarDate {
    return new CalendarDate(
        date.getFullYear(),
        date.getMonth() + 1,
        date.getDate(),
    );
}

const calendarRange = computed({
    get: () => ({
        start: selected.value.start
            ? toCalendarDate(selected.value.start)
            : undefined,
        end: selected.value.end
            ? toCalendarDate(selected.value.end)
            : undefined,
    }),
    set: (newValue: {
        start: CalendarDate | null;
        end: CalendarDate | null;
    }) => {
        selected.value = {
            start: newValue.start
                ? newValue.start.toDate(getLocalTimeZone())
                : new Date(),
            end: newValue.end
                ? newValue.end.toDate(getLocalTimeZone())
                : new Date(),
        };
    },
});

function isRangeSelected(range: {
    days?: number;
    months?: number;
    years?: number;
}): boolean {
    if (!selected.value.start || !selected.value.end) {
        return false;
    }

    const currentDate = today(getLocalTimeZone());
    let startDate = currentDate.copy();

    if (range.days) {
        startDate = startDate.subtract({ days: range.days });
    } else if (range.months) {
        startDate = startDate.subtract({ months: range.months });
    } else if (range.years) {
        startDate = startDate.subtract({ years: range.years });
    }

    const selectedStart = toCalendarDate(selected.value.start);
    const selectedEnd = toCalendarDate(selected.value.end);

    return (
        selectedStart.compare(startDate) === 0 &&
        selectedEnd.compare(currentDate) === 0
    );
}

function selectRange(range: {
    days?: number;
    months?: number;
    years?: number;
}): void {
    const endDate = today(getLocalTimeZone());
    let startDate = endDate.copy();

    if (range.days) {
        startDate = startDate.subtract({ days: range.days });
    } else if (range.months) {
        startDate = startDate.subtract({ months: range.months });
    } else if (range.years) {
        startDate = startDate.subtract({ years: range.years });
    }

    selected.value = {
        start: startDate.toDate(getLocalTimeZone()),
        end: endDate.toDate(getLocalTimeZone()),
    };
}
</script>

<template>
    <UPopover :content="{ align: 'start' }" :modal="true">
        <UButton
            color="neutral"
            variant="ghost"
            icon="i-lucide-calendar"
            class="group data-[state=open]:bg-elevated"
            data-test="home-range-button"
        >
            <span class="truncate">
                <template v-if="selected.start">
                    <template v-if="selected.end">
                        {{ df.format(selected.start) }} -
                        {{ df.format(selected.end) }}
                    </template>
                    <template v-else>
                        {{ df.format(selected.start) }}
                    </template>
                </template>
                <template v-else> Escolha uma data </template>
            </span>

            <template #trailing>
                <UIcon
                    name="i-lucide-chevron-down"
                    class="text-dimmed size-5 shrink-0 transition-transform duration-200 group-data-[state=open]:rotate-180"
                />
            </template>
        </UButton>

        <template #content>
            <div class="divide-default flex items-stretch sm:divide-x">
                <div class="hidden flex-col justify-center sm:flex">
                    <UButton
                        v-for="(range, index) in ranges"
                        :key="index"
                        :label="range.label"
                        color="neutral"
                        variant="ghost"
                        class="rounded-none px-4"
                        :class="[
                            isRangeSelected(range)
                                ? 'bg-elevated'
                                : 'hover:bg-elevated/50',
                        ]"
                        truncate
                        @click="selectRange(range)"
                    />
                </div>

                <UCalendar
                    v-model="calendarRange"
                    class="p-2"
                    :number-of-months="2"
                    range
                />
            </div>
        </template>
    </UPopover>
</template>
