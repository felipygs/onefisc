<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { store } from '@/routes/onboarding';

defineOptions({
    layout: {
        title: 'Criar conta principal',
        description: 'Cadastre seu escritório para começar',
    },
});

const form = useForm({
    account_name: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function onSubmit() {
    form.post(store.url(), {
        onSuccess: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Onboarding" />

    <UCard>
        <template #header>
            <h2 class="text-lg font-semibold">Criar conta principal</h2>
            <p class="text-muted text-sm">
                Primeiro cadastro cria a Account A com seu usuário super_admin.
            </p>
        </template>

        <UAlert
            v-if="form.errors.account_name || form.errors.email"
            color="error"
            variant="soft"
            title="Verifique os dados informados"
            class="mb-4"
        />

        <UForm :state="form" class="flex flex-col gap-4" @submit="onSubmit">
            <UFormField
                label="Nome do escritório"
                name="account_name"
                :error="form.errors.account_name"
                required
            >
                <UInput
                    v-model="form.account_name"
                    name="account_name"
                    placeholder="Matriz"
                    autocomplete="organization"
                    class="w-full"
                />
            </UFormField>

            <UFormField
                label="Seu nome"
                name="name"
                :error="form.errors.name"
                required
            >
                <UInput
                    v-model="form.name"
                    name="name"
                    placeholder="Nome completo"
                    autocomplete="name"
                    class="w-full"
                />
            </UFormField>

            <UFormField
                label="E-mail"
                name="email"
                :error="form.errors.email"
                required
            >
                <UInput
                    v-model="form.email"
                    name="email"
                    type="email"
                    placeholder="voce@escritorio.com"
                    autocomplete="email"
                    class="w-full"
                />
            </UFormField>

            <UFormField
                label="Senha"
                name="password"
                :error="form.errors.password"
                required
            >
                <UInput
                    v-model="form.password"
                    name="password"
                    type="password"
                    placeholder="Senha"
                    autocomplete="new-password"
                    class="w-full"
                />
            </UFormField>

            <UFormField
                label="Confirmar senha"
                name="password_confirmation"
                :error="form.errors.password_confirmation"
                required
            >
                <UInput
                    v-model="form.password_confirmation"
                    name="password_confirmation"
                    type="password"
                    placeholder="Confirmar senha"
                    autocomplete="new-password"
                    class="w-full"
                />
            </UFormField>

            <UButton
                type="submit"
                block
                :loading="form.processing"
                :disabled="form.processing"
                data-test="onboarding-submit"
            >
                Criar Account A
            </UButton>
        </UForm>
    </UCard>
</template>
