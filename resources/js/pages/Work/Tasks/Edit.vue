<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface WorkTask {
    id: number;
    title: string;
    status: string;
    position: number;
    priority: string;
}

defineProps<{
    task: WorkTask;
}>();
</script>

<template>
    <Head :title="`Editar ${task.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Editar tarefa</h1>
            <p class="text-muted text-sm">
                {{ task.title }}
            </p>
        </div>
    </div>
</template>
