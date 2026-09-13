<script setup lang="ts">
import {
    overview as workOverview,
    processos as workProcessos,
} from '@/routes/work';

withDefaults(
    defineProps<{
        active?: string;
    }>(),
    { active: 'processo' },
);

const items = [
    { key: 'overview', label: 'Visão geral', href: workOverview.url() },
    { key: 'processo', label: 'Processos', href: workProcessos.url() },
    {
        key: 'tarefas',
        label: 'Tarefas',
        href: workProcessos.url({ view: 'tarefas' }),
    },
    {
        key: 'calendario',
        label: 'Calendário',
        href: workProcessos.url({ view: 'calendario' }),
    },
    {
        key: 'cliente',
        label: 'Clientes',
        href: workProcessos.url({ view: 'cliente' }),
    },
];
</script>

<template>
    <nav aria-label="Navegação do Work" class="flex flex-wrap gap-1.5">
        <UButton
            v-for="item in items"
            :key="item.key"
            :to="item.href"
            :variant="active === item.key ? 'solid' : 'ghost'"
            color="neutral"
            size="sm"
            :aria-current="active === item.key ? 'page' : undefined"
            :data-test="`work-nav-${item.key}`"
        >
            {{ item.label }}
        </UButton>
    </nav>
</template>
