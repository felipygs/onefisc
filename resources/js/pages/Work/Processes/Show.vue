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
    <Head :title="process.title" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">{{ process.title }}</h1>
            <p v-if="process.description" class="text-muted text-sm">
                {{ process.description }}
            </p>
        </div>

        <ul data-test="work-process-checklist">
            <li v-for="item in process.definitions" :key="item.id">
                {{ item.title }}
            </li>
        </ul>
    </div>
</template>
