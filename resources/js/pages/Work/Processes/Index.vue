<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface WorkProcessRow {
    id: number;
    title: string;
    status: string;
}

interface ProcessesPaginator {
    data: WorkProcessRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

defineProps<{
    processes: ProcessesPaginator;
}>();
</script>

<template>
    <Head title="Processos" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Processos operacionais</h1>
            <p class="text-muted text-sm">
                Lista de processos operacionais da Account.
            </p>
        </div>

        <ul data-test="work-process-list">
            <li v-for="process in processes.data" :key="process.id">
                {{ process.title }}
            </li>
        </ul>
    </div>
</template>
