<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import WorkChildNav from '@/components/work/WorkChildNav.vue';
</script>

<template>
    <Head title="Visão geral do trabalho" />

    <UDashboardPanel id="work-overview">
        <template #header>
            <UDashboardNavbar title="Work">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <div class="flex flex-col gap-4">
                <WorkChildNav active="overview" />

                <UPageCard
                    title="Visão geral chega na Onda 2"
                    description="Situação, distribuição, carga, envelhecimento e equipe serão calculados aqui na Task 3.4 — sem números provisórios até lá."
                    icon="i-lucide-chart-pie"
                    data-test="work-overview-empty"
                />
            </div>
        </template>
    </UDashboardPanel>
</template>
