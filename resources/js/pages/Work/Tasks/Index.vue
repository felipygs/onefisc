<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface WorkTaskRow {
    id: number;
    title: string;
    status: string;
    position: number;
}

interface TasksPaginator {
    data: WorkTaskRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

defineProps<{
    tasks: TasksPaginator;
    filters: Record<string, string | number | undefined>;
}>();
</script>

<template>
    <Head title="Tarefas" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Tarefas operacionais</h1>
            <p class="text-muted text-sm">
                Lista de tarefas operacionais da Account.
            </p>
        </div>

        <ul data-test="work-task-list">
            <li v-for="task in tasks.data" :key="task.id">
                {{ task.title }}
            </li>
        </ul>
    </div>
</template>
