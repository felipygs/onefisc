<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PlanLimitWarning from '@/components/PlanLimitWarning.vue';
import { edit, index as plansIndex } from '@/routes/plans';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Plans',
                href: plansIndex(),
            },
        ],
    },
});

interface Plan {
    id: number;
    name: string;
    price_cents: number;
    max_users: number;
    max_clients: number;
    modules: string[];
    monthly_query_volume: number;
    is_default: boolean;
}

defineProps<{
    plans: Plan[];
}>();

function formatPrice(cents: number): string {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(cents / 100);
}
</script>

<template>
    <Head title="Plans" />

    <div class="flex flex-col gap-6 p-4">
        <PlanLimitWarning />
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">Catálogo de Plans</h1>
                <p class="text-muted text-sm">
                    Plans disponíveis para atribuição às Accounts.
                </p>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <UCard
                v-for="plan in plans"
                :key="plan.id"
                :data-test="`plan-card-${plan.id}`"
                :class="plan.is_default ? 'ring-primary ring-2' : ''"
            >
                <template #header>
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold">{{ plan.name }}</h2>
                        <UBadge
                            v-if="plan.is_default"
                            color="primary"
                            variant="soft"
                            data-test="plan-default-badge"
                        >
                            Padrão
                        </UBadge>
                    </div>
                    <p class="text-muted mt-1 text-2xl font-bold">
                        {{ formatPrice(plan.price_cents) }}
                        <span class="text-sm font-normal">/mês</span>
                    </p>
                </template>

                <ul class="flex flex-col gap-2 text-sm">
                    <li>Até {{ plan.max_users }} usuários</li>
                    <li>Até {{ plan.max_clients }} clients</li>
                    <li>Módulos: {{ plan.modules.join(', ') || '—' }}</li>
                    <li>{{ plan.monthly_query_volume }} consultas/mês</li>
                </ul>

                <template #footer>
                    <UButton
                        block
                        variant="outline"
                        :to="edit.url({ plan: plan.id })"
                        :data-test="`plan-edit-${plan.id}`"
                    >
                        Editar
                    </UButton>
                </template>
            </UCard>
        </div>
    </div>
</template>
