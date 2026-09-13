<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface TaskProcess {
    id: number;
    title: string;
}

interface TaskClient {
    id: number;
    name: string;
}

interface TaskAssignee {
    id: number;
    name: string;
}

interface WorkTask {
    id: number;
    title: string;
    status: string;
    position: number;
    priority: string;
    process: TaskProcess;
    client: TaskClient;
    assignee: TaskAssignee | null;
}

defineProps<{
    task: WorkTask;
}>();
</script>

<template>
    <Head :title="task.title" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">{{ task.title }}</h1>
            <p class="text-muted text-sm">
                {{ task.client.name }} · {{ task.process.title }}
            </p>
        </div>

        <p v-if="task.assignee" data-test="work-task-assignee">
            {{ task.assignee.name }}
        </p>
    </div>
</template>
