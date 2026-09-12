<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui';
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import PlanLimitWarning from '@/components/PlanLimitWarning.vue';
import HomeChart from '@/components/home/HomeChart.vue';
import HomeDateRangePicker from '@/components/home/HomeDateRangePicker.vue';
import HomePeriodSelect from '@/components/home/HomePeriodSelect.vue';
import HomeSales from '@/components/home/HomeSales.vue';
import HomeStats from '@/components/home/HomeStats.vue';
import { useDashboard } from '@/composables/useDashboard';
import { dashboard } from '@/routes';
import { create as createClient } from '@/routes/clients';
import type { HomePeriod, HomeProps, HomeRange } from '@/types/home';

const props = defineProps<{
    home: HomeProps;
}>();

const { isNotificationsSlideoverOpen } = useDashboard();

function parseDay(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year ?? 1970, (month ?? 1) - 1, day ?? 1, 12);
}

function toDayString(date: Date): string {
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

const range = ref<HomeRange>({
    start: parseDay(props.home.range.start),
    end: parseDay(props.home.range.end),
});
const period = ref<HomePeriod>(props.home.period);

function reloadHome(): void {
    router.get(
        dashboard.url(),
        {
            start: toDayString(range.value.start),
            end: toDayString(range.value.end),
            period: period.value,
        },
        { preserveState: true, preserveScroll: true, only: ['home'] },
    );
}

watch([range, period], reloadHome);

watch(
    () => props.home,
    (home) => {
        const serverRange = {
            start: parseDay(home.range.start),
            end: parseDay(home.range.end),
        };

        if (
            toDayString(serverRange.start) !== toDayString(range.value.start) ||
            toDayString(serverRange.end) !== toDayString(range.value.end)
        ) {
            range.value = serverRange;
        }

        if (home.period !== period.value) {
            period.value = home.period;
        }
    },
);

const items = [
    [
        {
            label: 'Novo client',
            icon: 'i-lucide-user-plus',
            onSelect: () => router.visit(createClient.url()),
        },
    ],
] satisfies DropdownMenuItem[][];
</script>

<template>
    <Head title="Dashboard" />

    <UDashboardPanel id="home">
        <template #header>
            <UDashboardNavbar title="Home" :ui="{ right: 'gap-3' }">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>

                <template #right>
                    <UTooltip text="Notificações" :shortcuts="['N']">
                        <UButton
                            color="neutral"
                            variant="ghost"
                            square
                            data-test="home-notifications"
                            @click="isNotificationsSlideoverOpen = true"
                        >
                            <UChip color="error" inset>
                                <UIcon
                                    name="i-lucide-bell"
                                    class="size-5 shrink-0"
                                />
                            </UChip>
                        </UButton>
                    </UTooltip>

                    <UDropdownMenu :items="items">
                        <UButton
                            icon="i-lucide-plus"
                            size="md"
                            class="rounded-full"
                            data-test="home-new"
                        />
                    </UDropdownMenu>
                </template>
            </UDashboardNavbar>

            <UDashboardToolbar>
                <template #left>
                    <!-- NOTE: The `-ms-1` class is used to align with the `DashboardSidebarCollapse` button here. -->
                    <HomeDateRangePicker v-model="range" class="-ms-1" />

                    <HomePeriodSelect v-model="period" :range="range" />
                </template>
            </UDashboardToolbar>
        </template>

        <template #body>
            <PlanLimitWarning />
            <HomeStats :stats="home.stats" />
            <HomeChart :points="home.chart" :period="period" />
            <HomeSales :sales="home.sales" />
        </template>
    </UDashboardPanel>
</template>
