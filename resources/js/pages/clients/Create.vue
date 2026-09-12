<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { index as clientsIndex, store } from '@/routes/clients';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Clients',
                href: clientsIndex(),
            },
            {
                title: 'Novo',
                href: clientsIndex(),
            },
        ],
    },
});

const REGIME_OPTIONS = [
    { label: 'Simples Nacional', value: 'simples' },
    { label: 'Lucro Presumido', value: 'presumido' },
    { label: 'Lucro Real', value: 'real' },
    { label: 'MEI', value: 'mei' },
];

const form = useForm({
    cnpj: '',
    razao_social: '',
    regime: '',
    contador_responsavel: '',
});

function formatCnpj(value: string): string {
    const digits = value.replace(/\D/g, '').slice(0, 14);

    if (digits.length <= 2) {
        return digits;
    }

    if (digits.length <= 5) {
        return `${digits.slice(0, 2)}.${digits.slice(2)}`;
    }

    if (digits.length <= 8) {
        return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5)}`;
    }

    if (digits.length <= 12) {
        return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8)}`;
    }

    return `${digits.slice(0, 2)}.${digits.slice(2, 5)}.${digits.slice(5, 8)}/${digits.slice(8, 12)}-${digits.slice(12)}`;
}

// Masked display; the form always holds raw digits (validation: size:14).
const cnpjDisplay = computed({
    get: () => formatCnpj(form.cnpj),
    set: (value: string) => {
        form.cnpj = value.replace(/\D/g, '').slice(0, 14);
    },
});

// `limit` errors come from plan-capacity redirects; not a form field,
// so read it through a record cast to stay type-safe.
const limitError = computed(
    () => (form.errors as Record<string, string | undefined>).limit,
);

const hasAlert = computed(
    () => limitError.value !== undefined || form.errors.cnpj !== undefined,
);

function onSubmit() {
    form.post(store.url());
}
</script>

<template>
    <Head title="Novo client" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Novo client</h1>
            <p class="text-muted text-sm">
                Cadastre uma empresa atendida pelo escritório.
            </p>
        </div>

        <UCard>
            <UAlert
                v-if="hasAlert"
                color="error"
                variant="soft"
                :title="limitError ?? 'Verifique os dados informados'"
                :description="limitError ? undefined : 'Há campos inválidos no formulário.'"
                class="mb-4"
                data-test="client-form-alert"
            />

            <UForm :state="form" class="flex flex-col gap-4" @submit="onSubmit">
                <UFormField
                    label="CNPJ"
                    name="cnpj"
                    :error="form.errors.cnpj"
                    required
                >
                    <UInput
                        v-model="cnpjDisplay"
                        name="cnpj"
                        placeholder="00.000.000/0000-00"
                        inputmode="numeric"
                        maxlength="18"
                        class="w-full"
                        data-test="client-cnpj"
                    />
                </UFormField>

                <UFormField
                    label="Razão social"
                    name="razao_social"
                    :error="form.errors.razao_social"
                    required
                >
                    <UInput
                        v-model="form.razao_social"
                        name="razao_social"
                        placeholder="Acme Ltda"
                        class="w-full"
                        data-test="client-razao"
                    />
                </UFormField>

                <UFormField
                    label="Regime tributário"
                    name="regime"
                    :error="form.errors.regime"
                    required
                >
                    <USelect
                        v-model="form.regime"
                        :items="REGIME_OPTIONS"
                        placeholder="Selecione o regime"
                        class="w-full"
                        data-test="client-regime"
                    />
                </UFormField>

                <UFormField
                    label="Contador responsável"
                    name="contador_responsavel"
                    :error="form.errors.contador_responsavel"
                    required
                >
                    <UInput
                        v-model="form.contador_responsavel"
                        name="contador_responsavel"
                        placeholder="Nome do contador"
                        class="w-full"
                        data-test="client-contador"
                    />
                </UFormField>

                <div class="flex items-center gap-2">
                    <UButton
                        type="submit"
                        :loading="form.processing"
                        :disabled="form.processing"
                        data-test="client-save"
                    >
                        Salvar
                    </UButton>
                    <UButton
                        variant="outline"
                        :to="clientsIndex.url()"
                        data-test="client-cancel"
                    >
                        Voltar
                    </UButton>
                </div>
            </UForm>
        </UCard>
    </div>
</template>
