<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { update as updateNotifications } from '@/routes/notifications';

interface NotificationPreferences {
    invites: boolean;
    plan_limits: boolean;
    monitoring: boolean;
}

const props = defineProps<{
    preferences: NotificationPreferences;
}>();

const form = useForm<NotificationPreferences>({ ...props.preferences });

const ITEMS: {
    key: keyof NotificationPreferences;
    title: string;
    description: string;
}[] = [
    {
        key: 'invites',
        title: 'Convites',
        description: 'Avisar sobre convites enviados e aceites.',
    },
    {
        key: 'plan_limits',
        title: 'Limites do Plan',
        description: 'Avisar ao atingir limites de usuários e Clients.',
    },
    {
        key: 'monitoring',
        title: 'Monitoramento',
        description: 'Avisar sobre o estado do monitoramento fiscal.',
    },
];

function save(): void {
    form.put(updateNotifications.url(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Notifications" />

    <UPageCard
        title="Notificações"
        description="Escolha o que avisa você. Vale imediatamente."
        data-test="notifications-card"
    >
        <ul class="divide-default divide-y">
            <li
                v-for="item in ITEMS"
                :key="item.key"
                class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
            >
                <div>
                    <p class="text-highlighted text-sm font-medium">
                        {{ item.title }}
                    </p>
                    <p class="text-dimmed text-sm">{{ item.description }}</p>
                </div>
                <USwitch
                    v-model="form[item.key]"
                    :data-test="`notifications-${item.key}`"
                    @update:model-value="save"
                />
            </li>
        </ul>
    </UPageCard>
</template>
