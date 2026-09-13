<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface ChecklistDefinition {
    id: number;
    title: string;
    position: number;
}

interface WorkProcess {
    id: number;
    title: string;
    description: string | null;
    status: string;
    definitions: ChecklistDefinition[];
}

defineProps<{
    process: WorkProcess;
}>();
</script>

<template>
    <Head :title="`Editar ${process.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Editar processo</h1>
            <p class="text-muted text-sm">
                Ajuste os dados e o checklist de {{ process.title }}.
            </p>
        </div>
    </div>
</template>
