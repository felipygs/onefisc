<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PlanLimitWarning from '@/components/PlanLimitWarning.vue';
import { index as switcherIndex, select } from '@/routes/switcher';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Switcher',
                href: switcherIndex(),
            },
        ],
    },
});

interface AccountPlan {
    id: number;
    name: string;
}

interface AccountRow {
    id: number;
    name: string;
    profile: string;
    plan: AccountPlan | null;
}

interface AccountsPaginator {
    data: AccountRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface AccountOption {
    label: string;
    value: number;
}

const props = defineProps<{
    accounts: AccountsPaginator;
}>();

const options = computed<AccountOption[]>(() =>
    props.accounts.data.map((account) => ({
        label: `${account.name} (#${account.id})`,
        value: account.id,
    })),
);

const selectedAccountId = ref<number | undefined>(undefined);
const enteringId = ref<number | null>(null);

const canEnter = computed(() => selectedAccountId.value !== undefined);

function enter(accountId: number): void {
    enteringId.value = accountId;
    router.post(select.url({ account: accountId }), undefined, {
        onFinish: () => {
            enteringId.value = null;
        },
    });
}

function enterSelected(): void {
    if (selectedAccountId.value !== undefined) {
        enter(selectedAccountId.value);
    }
}

function goToPage(nextPage: number): void {
    router.get(
        switcherIndex.url(),
        { page: nextPage },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Account switcher" />

    <div class="flex flex-col gap-6 p-4">
        <PlanLimitWarning />

        <div>
            <h1 class="text-xl font-semibold">Account switcher</h1>
            <p class="text-muted text-sm">
                Entre em uma account para atuar em seu contexto. A sessão de
                impersonação fica visível no banner persistente.
            </p>
        </div>

        <UCard>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="w-full sm:max-w-sm">
                    <label
                        for="switcher-account"
                        class="mb-1 block text-sm font-medium"
                    >
                        Account
                    </label>
                    <USelectMenu
                        id="switcher-account"
                        v-model="selectedAccountId"
                        value-key="value"
                        :items="options"
                        :search-input="{
                            placeholder: 'Buscar account…',
                        }"
                        placeholder="Selecione uma account"
                        class="w-full"
                        data-test="switcher-select"
                    />
                </div>
                <UButton
                    :disabled="!canEnter || enteringId !== null"
                    :loading="enteringId !== null"
                    data-test="switcher-enter"
                    @click="enterSelected"
                >
                    Atuar como selecionada
                </UButton>
            </div>

            <div
                v-if="accounts.data.length === 0"
                class="text-muted py-8 text-center text-sm"
                data-test="switcher-empty"
            >
                Nenhuma account encontrada.
            </div>

            <div v-else class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr
                            class="border-default text-muted border-b text-xs uppercase"
                        >
                            <th class="px-2 py-2 font-medium">Account</th>
                            <th class="px-2 py-2 font-medium">Perfil</th>
                            <th class="px-2 py-2 font-medium">Plano</th>
                            <th class="px-2 py-2 text-right font-medium">
                                Ações
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="account in accounts.data"
                            :key="account.id"
                            class="border-default border-b last:border-0"
                            :data-test="`switcher-row-${account.id}`"
                        >
                            <td class="px-2 py-3">
                                {{ account.name }}
                                <span class="text-muted text-xs">
                                    (#{{ account.id }})
                                </span>
                            </td>
                            <td class="px-2 py-3">
                                <UBadge color="neutral" variant="soft">
                                    {{ account.profile }}
                                </UBadge>
                            </td>
                            <td class="px-2 py-3">
                                {{ account.plan?.name ?? '—' }}
                            </td>
                            <td class="px-2 py-3 text-right whitespace-nowrap">
                                <UButton
                                    variant="ghost"
                                    size="xs"
                                    :loading="enteringId === account.id"
                                    :disabled="enteringId !== null"
                                    :data-test="`switcher-enter-${account.id}`"
                                    @click="enter(account.id)"
                                >
                                    Atuar
                                </UButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="accounts.last_page > 1" class="mt-4 flex justify-center">
                <UPagination
                    :page="accounts.current_page"
                    :items-per-page="accounts.per_page"
                    :total="accounts.total"
                    data-test="switcher-pagination"
                    @update:page="goToPage"
                />
            </div>
        </UCard>
    </div>
</template>
