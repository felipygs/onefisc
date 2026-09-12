<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { store } from '@/routes/invitations/accept';

defineOptions({
    layout: {
        title: 'Aceitar convite',
        description: 'Defina sua senha para ativar a conta',
    },
});

const props = defineProps<{
    token: string;
    name?: string | null;
    email?: string | null;
    expired: boolean;
}>();

const form = useForm<{
    password: string;
    password_confirmation: string;
    token?: string;
    email?: string;
}>({
    password: '',
    password_confirmation: '',
});

function onSubmit() {
    form.post(store.url({ token: props.token }), {
        onSuccess: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Aceitar convite" />

    <UCard>
        <template #header>
            <h2 class="text-lg font-semibold">Aceitar convite</h2>
            <p v-if="email" class="text-muted text-sm">
                Convite para {{ name }} ({{ email }})
            </p>
        </template>

        <UAlert
            v-if="expired"
            color="error"
            variant="soft"
            title="Convite inválido ou expirado"
            description="Solicite um novo convite ao administrador da sua Account."
            data-test="invitation-expired"
        />

        <template v-else>
            <UAlert
                v-if="form.errors.token || form.errors.email"
                color="error"
                variant="soft"
                :title="form.errors.token ?? form.errors.email"
                class="mb-4"
            />

            <UForm :state="form" class="flex flex-col gap-4" @submit="onSubmit">
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
                    data-test="invitation-accept-submit"
                >
                    Ativar conta
                </UButton>
            </UForm>
        </template>
    </UCard>
</template>
