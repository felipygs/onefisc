<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as plansIndex } from '@/routes/plans';
import type { PlanUsage } from '@/types/plans';

const props = defineProps<{
    usage?: PlanUsage | null;
}>();

const page = usePage();

const effectiveUsage = computed<PlanUsage | null>(
    () => props.usage ?? page.props.planUsage ?? null,
);

const canManagePlatform = computed<boolean>(
    () => page.props.permissions?.['manage-platform'] === true,
);

function isAtLimit(used: number, max: number | null): boolean {
    return max !== null && used >= max;
}

const exceeded = computed<string[]>(() => {
    const usage = effectiveUsage.value;

    if (!usage) {
        return [];
    }

    const hits: string[] = [];

    if (isAtLimit(usage.users.used, usage.users.max)) {
        hits.push(`usuários (${usage.users.used}/${usage.users.max})`);
    }

    if (isAtLimit(usage.clients.used, usage.clients.max)) {
        hits.push(`clients (${usage.clients.used}/${usage.clients.max})`);
    }

    if (isAtLimit(usage.volume.used, usage.volume.max)) {
        hits.push(
            `volume mensal (${usage.volume.used}/${usage.volume.max})`,
        );
    }

    return hits;
});

const shouldShow = computed(() => exceeded.value.length > 0);

const description = computed(() => {
    const details = `Limites atingidos: ${exceeded.value.join(', ')}.`;

    return canManagePlatform.value
        ? `${details} Considere fazer upgrade do plano.`
        : `${details} Fale com o administrador para solicitar upgrade do plano.`;
});
</script>

<template>
    <UAlert
        v-if="shouldShow"
        color="warning"
        variant="soft"
        title="Limite do plano atingido"
        :description="description"
        data-test="plan-limit-warning"
    >
        <template v-if="canManagePlatform" #actions>
            <UButton
                color="warning"
                variant="solid"
                size="xs"
                :to="plansIndex.url()"
                data-test="plan-limit-upgrade"
            >
                Ver planos
            </UButton>
        </template>
    </UAlert>
</template>
