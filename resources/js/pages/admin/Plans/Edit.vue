<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as plansIndex, update } from '@/routes/plans';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Plans',
                href: plansIndex(),
            },
            {
                title: 'Editar',
                href: plansIndex(),
            },
        ],
    },
});

interface Plan {
    id: number;
    name: string;
    price_cents: number;
    max_users: number;
    max_clients: number;
    modules: string[];
    monthly_query_volume: number;
    is_default: boolean;
}

const props = defineProps<{
    plan: Plan;
}>();

const form = useForm({
    name: props.plan.name,
    price_cents: props.plan.price_cents,
    max_users: props.plan.max_users,
    max_clients: props.plan.max_clients,
    modules: [...(props.plan.modules ?? [])],
    monthly_query_volume: props.plan.monthly_query_volume,
    is_default: props.plan.is_default,
});

const modulesText = computed({
    get: () => form.modules.join(', '),
    set: (value: string) => {
        form.modules = parseModules(value);
    },
});

function parseModules(value: string): string[] {
    return value
        .split(',')
        .map((part) => part.trim())
        .filter((part) => part.length > 0);
}

function onSubmit() {
    form.transform((data) => ({
        ...data,
        price_cents: Number(data.price_cents),
        max_users: Number(data.max_users),
        max_clients: Number(data.max_clients),
        monthly_query_volume: Number(data.monthly_query_volume),
    })).put(update.url({ plan: props.plan.id }));
}
</script>

<template>
    <Head title="Editar Plan" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Editar Plan</h1>
            <p class="text-muted text-sm">
                Atualize nome, preço, limites, módulos e o plano padrão.
            </p>
        </div>

        <UCard>
            <UAlert
                v-if="form.errors.name || form.errors.modules"
                color="error"
                variant="soft"
                title="Verifique os dados informados"
                class="mb-4"
            />

            <UForm :state="form" class="flex flex-col gap-4" @submit="onSubmit">
                <UFormField
                    label="Nome"
                    name="name"
                    :error="form.errors.name"
                    required
                >
                    <UInput
                        v-model="form.name"
                        name="name"
                        placeholder="Básico"
                        class="w-full"
                    />
                </UFormField>

                <UFormField
                    label="Preço (centavos)"
                    name="price_cents"
                    :error="form.errors.price_cents"
                    required
                >
                    <UInput
                        v-model="form.price_cents"
                        name="price_cents"
                        type="number"
                        min="0"
                        class="w-full"
                    />
                </UFormField>

                <UFormField
                    label="Máximo de usuários"
                    name="max_users"
                    :error="form.errors.max_users"
                    required
                >
                    <UInput
                        v-model="form.max_users"
                        name="max_users"
                        type="number"
                        min="1"
                        class="w-full"
                    />
                </UFormField>

                <UFormField
                    label="Máximo de clients"
                    name="max_clients"
                    :error="form.errors.max_clients"
                    required
                >
                    <UInput
                        v-model="form.max_clients"
                        name="max_clients"
                        type="number"
                        min="1"
                        class="w-full"
                    />
                </UFormField>

                <UFormField
                    label="Módulos (separados por vírgula)"
                    name="modules"
                    :error="form.errors.modules"
                    required
                >
                    <UInput
                        v-model="modulesText"
                        name="modules"
                        placeholder="clients, monitoring"
                        class="w-full"
                    />
                </UFormField>

                <UFormField
                    label="Volume mensal de consultas"
                    name="monthly_query_volume"
                    :error="form.errors.monthly_query_volume"
                    required
                >
                    <UInput
                        v-model="form.monthly_query_volume"
                        name="monthly_query_volume"
                        type="number"
                        min="0"
                        class="w-full"
                    />
                </UFormField>

                <UFormField
                    label="Plano padrão"
                    name="is_default"
                    :error="form.errors.is_default"
                >
                    <USwitch v-model="form.is_default" name="is_default" />
                </UFormField>

                <div class="flex items-center gap-2">
                    <UButton
                        type="submit"
                        :loading="form.processing"
                        :disabled="form.processing"
                        data-test="plan-save"
                    >
                        Salvar
                    </UButton>
                    <UButton
                        variant="outline"
                        :to="plansIndex.url()"
                        data-test="plan-cancel"
                    >
                        Voltar
                    </UButton>
                </div>
            </UForm>
        </UCard>
    </div>
</template>
