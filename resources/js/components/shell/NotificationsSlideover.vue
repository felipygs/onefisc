<script setup lang="ts">
import { formatTimeAgo } from '@vueuse/core';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useDashboard } from '@/composables/useDashboard';
import { index as auditIndex } from '@/routes/audit';
import type { ShellNotification } from '@/types/shell';

const { isNotificationsSlideoverOpen } = useDashboard();

const page = usePage();

const notifications = computed<ShellNotification[]>(
    () => page.props.notifications ?? [],
);

function timeAgo(date: string | null): string {
    if (!date) {
        return '';
    }

    return formatTimeAgo(new Date(date));
}
</script>

<template>
    <USlideover
        v-model:open="isNotificationsSlideoverOpen"
        title="Notificações"
    >
        <template #body>
            <p
                v-if="notifications.length === 0"
                class="text-muted px-3 py-6 text-center text-sm"
                data-test="notifications-empty"
            >
                Nenhuma notificação por aqui.
            </p>
            <Link
                v-for="notification in notifications"
                v-else
                :key="notification.id"
                :href="auditIndex.url()"
                class="hover:bg-elevated/50 relative -mx-3 flex items-center gap-3 rounded-md px-3 py-2.5 first:-mt-3 last:-mb-3"
                :data-test="`notification-${notification.id}`"
            >
                <UAvatar
                    :alt="notification.sender.name"
                    :text="notification.sender.name"
                    size="md"
                />

                <div class="flex-1 text-sm">
                    <p class="flex items-center justify-between">
                        <span class="text-highlighted font-medium">
                            {{ notification.sender.name }}
                        </span>

                        <time
                            :datetime="notification.date ?? ''"
                            class="text-muted text-xs"
                        >
                            {{ timeAgo(notification.date) }}
                        </time>
                    </p>

                    <p class="text-dimmed">
                        {{ notification.body }}
                    </p>
                </div>
            </Link>
        </template>
    </USlideover>
</template>
