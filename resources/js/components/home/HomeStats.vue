<script setup lang="ts">
import { index as clientsIndex } from '@/routes/clients';
import type { HomeStat } from '@/types/home';

defineProps<{
    stats: HomeStat[];
}>();
</script>

<template>
    <UPageGrid class="gap-4 sm:gap-6 lg:grid-cols-4 lg:gap-px">
        <UPageCard
            v-for="(stat, index) in stats"
            :key="index"
            :icon="stat.icon"
            :title="stat.title"
            :to="stat.title === 'Clients' ? clientsIndex.url() : undefined"
            variant="subtle"
            :ui="{
                container: 'gap-y-1.5',
                wrapper: 'items-start',
                leading:
                    'p-2.5 rounded-full bg-primary/10 ring ring-inset ring-primary/25 flex-col',
                title: 'font-normal text-muted text-xs uppercase',
            }"
            class="first:rounded-l-lg last:rounded-r-lg hover:z-1 lg:rounded-none"
            :data-test="`home-stat-${index}`"
        >
            <div class="flex items-center gap-2">
                <span class="text-highlighted text-2xl font-semibold">
                    {{ stat.value }}
                </span>

                <UBadge
                    :color="stat.variation > 0 ? 'success' : 'error'"
                    variant="subtle"
                    class="text-xs"
                >
                    {{ stat.variation > 0 ? '+' : '' }}{{ stat.variation }}%
                </UBadge>
            </div>
        </UPageCard>
    </UPageGrid>
</template>
