<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PlanLimitWarning from '@/components/PlanLimitWarning.vue';
import { index as auditIndex } from '@/routes/audit';

interface AuditActor {
    id: number;
    name: string;
    email: string;
}

interface AuditAccount {
    id: number;
    name: string;
}

interface AuditRow {
    id: number;
    action: string;
    actor_user_id: number;
    origin_account_id: number;
    target_account_id: number | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
    actor: AuditActor | null;
    origin: AuditAccount | null;
    target: AuditAccount | null;
}

interface LogsPaginator {
    data: AuditRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface AuditFilters {
    account_id?: string | number | null;
    actor_id?: string | number | null;
    action?: string | null;
}

const props = defineProps<{
    logs: LogsPaginator;
    filters: AuditFilters;
}>();

// Exact action strings emitted by the backend (AuditObserver table events +
// manual records in AccountController/AccountSwitcherController/InvitationController).
const ACTION_OPTIONS = [
    { label: 'Todas as ações', value: '' },
    { label: 'plan.switch', value: 'plan.switch' },
    { label: 'invitation.accepted', value: 'invitation.accepted' },
    { label: 'switcher.enter', value: 'switcher.enter' },
    { label: 'switcher.exit', value: 'switcher.exit' },
    { label: 'accounts.created', value: 'accounts.created' },
    { label: 'accounts.updated', value: 'accounts.updated' },
    { label: 'accounts.deleted', value: 'accounts.deleted' },
    { label: 'plans.created', value: 'plans.created' },
    { label: 'plans.updated', value: 'plans.updated' },
    { label: 'plans.deleted', value: 'plans.deleted' },
    { label: 'clients.created', value: 'clients.created' },
    { label: 'clients.updated', value: 'clients.updated' },
    { label: 'clients.deleted', value: 'clients.deleted' },
    { label: 'invitations.created', value: 'invitations.created' },
    { label: 'invitations.updated', value: 'invitations.updated' },
    { label: 'invitations.deleted', value: 'invitations.deleted' },
    { label: 'users.created', value: 'users.created' },
    { label: 'users.updated', value: 'users.updated' },
    { label: 'users.deleted', value: 'users.deleted' },
    {
        label: 'monitoring_checks.created',
        value: 'monitoring_checks.created',
    },
    {
        label: 'monitoring_checks.updated',
        value: 'monitoring_checks.updated',
    },
    {
        label: 'monitoring_checks.deleted',
        value: 'monitoring_checks.deleted',
    },
];

type BadgeColor =
    | 'primary'
    | 'secondary'
    | 'success'
    | 'info'
    | 'warning'
    | 'error'
    | 'neutral';

function actionColor(action: string): BadgeColor {
    if (action === 'switcher.enter' || action === 'switcher.exit') {
        return 'warning';
    }

    if (action === 'plan.switch') {
        return 'info';
    }

    if (action.endsWith('.created') || action === 'invitation.accepted') {
        return 'success';
    }

    if (action.endsWith('.updated')) {
        return 'info';
    }

    if (action.endsWith('.deleted')) {
        return 'error';
    }

    return 'neutral';
}

const accountFilter = ref(props.filters.account_id?.toString() ?? '');
const actorFilter = ref(props.filters.actor_id?.toString() ?? '');
const actionFilter = ref(props.filters.action ?? '');

// The controller provides no account catalog, so the Account select is built
// honestly from accounts referenced by the loaded page (+ the active filter).
const accountOptions = computed(() => {
    const seen = new Map<number, string>();

    for (const row of props.logs.data) {
        if (row.origin !== null && !seen.has(row.origin.id)) {
            seen.set(row.origin.id, row.origin.name);
        }

        if (row.target !== null && !seen.has(row.target.id)) {
            seen.set(row.target.id, row.target.name);
        }
    }

    const active = accountFilter.value.trim();

    if (active !== '' && !seen.has(Number(active))) {
        seen.set(Number(active), `Account #${active}`);
    }

    return [
        { label: 'Todas as accounts', value: '' },
        ...[...seen.entries()].map(([id, name]) => ({
            label: `${name} (#${id})`,
            value: String(id),
        })),
    ];
});

const dirty = computed(
    () =>
        accountFilter.value.trim() !==
            (props.filters.account_id?.toString() ?? '') ||
        actorFilter.value.trim() !==
            (props.filters.actor_id?.toString() ?? '') ||
        actionFilter.value !== (props.filters.action ?? ''),
);

function applyFilters(): void {
    router.get(
        auditIndex.url(),
        {
            account_id: accountFilter.value.trim() || undefined,
            actor_id: actorFilter.value.trim() || undefined,
            action: actionFilter.value || undefined,
        },
        { preserveScroll: true },
    );
}

function clearFilters(): void {
    accountFilter.value = '';
    actorFilter.value = '';
    actionFilter.value = '';
    router.get(auditIndex.url(), {}, { preserveScroll: true });
}

function goToPage(nextPage: number): void {
    router.get(
        auditIndex.url(),
        {
            account_id: accountFilter.value.trim() || undefined,
            actor_id: actorFilter.value.trim() || undefined,
            action: actionFilter.value || undefined,
            page: nextPage,
        },
        { preserveScroll: true },
    );
}

function formatMoment(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString('pt-BR');
}

const detailsOpen = ref(false);
const selected = ref<AuditRow | null>(null);

function openDetails(row: AuditRow): void {
    selected.value = row;
    detailsOpen.value = true;
}

const selectedMetadata = computed(() =>
    JSON.stringify(selected.value?.metadata ?? {}, null, 2),
);
</script>

<template>
    <Head title="Auditoria" />

    <div class="flex flex-col gap-6 p-4">
        <PlanLimitWarning />

        <div>
            <h1 class="text-xl font-semibold">Auditoria</h1>
            <p class="text-muted text-sm">
                Eventos registrados por account, ator e ação. Linhas
                <span class="font-mono">plans.*</span> não possuem account alvo
                e exibem “—” em Destino.
            </p>
        </div>

        <UCard>
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end">
                <div class="w-full lg:max-w-64">
                    <label
                        for="audit-account"
                        class="mb-1 block text-sm font-medium"
                    >
                        Account
                    </label>
                    <USelect
                        id="audit-account"
                        v-model="accountFilter"
                        :items="accountOptions"
                        class="w-full"
                        data-test="audit-account-filter"
                    />
                </div>
                <div class="w-full lg:max-w-48">
                    <label
                        for="audit-actor"
                        class="mb-1 block text-sm font-medium"
                    >
                        Ator (ID)
                    </label>
                    <UInput
                        id="audit-actor"
                        v-model="actorFilter"
                        placeholder="ID do usuário ator…"
                        inputmode="numeric"
                        class="w-full"
                        data-test="audit-actor-filter"
                        @keyup.enter="applyFilters"
                    />
                </div>
                <div class="w-full lg:max-w-64">
                    <label
                        for="audit-action"
                        class="mb-1 block text-sm font-medium"
                    >
                        Ação
                    </label>
                    <USelect
                        id="audit-action"
                        v-model="actionFilter"
                        :items="ACTION_OPTIONS"
                        class="w-full"
                        data-test="audit-action-filter"
                    />
                </div>
                <div class="flex gap-2">
                    <UButton
                        :disabled="!dirty"
                        data-test="audit-apply"
                        @click="applyFilters"
                    >
                        Filtrar
                    </UButton>
                    <UButton
                        variant="outline"
                        data-test="audit-clear"
                        @click="clearFilters"
                    >
                        Limpar
                    </UButton>
                </div>
            </div>

            <div
                v-if="logs.data.length === 0"
                class="text-muted py-8 text-center text-sm"
                data-test="audit-empty"
            >
                Nenhum evento encontrado para os filtros informados.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr
                            class="border-default text-muted border-b text-xs uppercase"
                        >
                            <th class="px-2 py-2 font-medium">Momento</th>
                            <th class="px-2 py-2 font-medium">Ator</th>
                            <th class="px-2 py-2 font-medium">Origem</th>
                            <th class="px-2 py-2 font-medium">Destino</th>
                            <th class="px-2 py-2 font-medium">Ação</th>
                            <th class="px-2 py-2 text-right font-medium">
                                Detalhes
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in logs.data"
                            :key="row.id"
                            class="border-default border-b last:border-0"
                            :data-test="`audit-row-${row.id}`"
                        >
                            <td
                                class="px-2 py-3 whitespace-nowrap tabular-nums"
                            >
                                {{ formatMoment(row.created_at) }}
                            </td>
                            <td class="px-2 py-3">
                                {{
                                    row.actor?.name ??
                                    `Usuário #${row.actor_user_id}`
                                }}
                            </td>
                            <td class="px-2 py-3">
                                {{
                                    row.origin?.name ??
                                    `#${row.origin_account_id}`
                                }}
                            </td>
                            <td
                                class="px-2 py-3"
                                :data-test="`audit-target-${row.id}`"
                            >
                                {{ row.target?.name ?? '—' }}
                            </td>
                            <td class="px-2 py-3">
                                <UBadge
                                    :color="actionColor(row.action)"
                                    variant="soft"
                                >
                                    {{ row.action }}
                                </UBadge>
                            </td>
                            <td class="px-2 py-3 text-right whitespace-nowrap">
                                <UButton
                                    variant="ghost"
                                    size="xs"
                                    :data-test="`audit-details-${row.id}`"
                                    @click="openDetails(row)"
                                >
                                    Ver
                                </UButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="logs.last_page > 1" class="mt-4 flex justify-center">
                <UPagination
                    :page="logs.current_page"
                    :items-per-page="logs.per_page"
                    :total="logs.total"
                    data-test="audit-pagination"
                    @update:page="goToPage"
                />
            </div>
        </UCard>

        <USlideover
            v-model:open="detailsOpen"
            title="Detalhes do evento"
            :description="
                selected !== null
                    ? `#${selected.id} · ${selected.action}`
                    : undefined
            "
            data-test="audit-slideover"
        >
            <template #body>
                <dl
                    v-if="selected !== null"
                    class="flex flex-col gap-3 text-sm"
                >
                    <div>
                        <dt class="text-muted text-xs uppercase">Momento</dt>
                        <dd>{{ formatMoment(selected.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted text-xs uppercase">Ator</dt>
                        <dd>
                            {{
                                selected.actor?.name ??
                                `Usuário #${selected.actor_user_id}`
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted text-xs uppercase">Origem</dt>
                        <dd>
                            {{
                                selected.origin?.name ??
                                `#${selected.origin_account_id}`
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted text-xs uppercase">Destino</dt>
                        <dd>{{ selected.target?.name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted text-xs uppercase">Ação</dt>
                        <dd>
                            <UBadge
                                :color="actionColor(selected.action)"
                                variant="soft"
                            >
                                {{ selected.action }}
                            </UBadge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted text-xs uppercase">Metadata</dt>
                        <dd>
                            <pre
                                class="bg-muted mt-1 overflow-x-auto rounded p-3 font-mono text-xs"
                                data-test="audit-metadata"
                                >{{ selectedMetadata }}</pre>
                        </dd>
                    </div>
                </dl>
            </template>
        </USlideover>
    </div>
</template>
