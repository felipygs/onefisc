<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import PanelAttention from '@/components/documents/PanelAttention.vue';
import type { AttentionRow } from '@/types/documents';

const props = defineProps<{
    attention: AttentionRow[];
}>();

const pendingCount = computed(() => props.attention.length);
</script>

<template>
    <Head title="Atenção da carteira" />

    <UDashboardPanel id="documents-clients">
        <template #header>
            <UDashboardNavbar title="Atenção da carteira">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <UAlert
                v-if="pendingCount > 0"
                color="warning"
                variant="subtle"
                icon="i-lucide-triangle-alert"
                :title="`${pendingCount.toLocaleString('pt-BR')} pendência(s) na carteira`"
                description="Resolva certificados, sincronização e XML pendente para manter a cobertura em dia."
                data-test="documents-clients-alert"
            />

            <PanelAttention :rows="attention" />
        </template>
    </UDashboardPanel>
</template>
