<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import PasswordInput from '@/components/PasswordInput.vue';
import { update } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Reset password',
        description: 'Please enter your new password below',
    },
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
}>();

const form = useForm({
    email: props.email,
    password: '',
    password_confirmation: '',
});

function onSubmit(): void {
    form.transform((data) => ({ ...data, token: props.token })).post(
        update.url(),
        {
            onSuccess: () => form.reset('password', 'password_confirmation'),
        },
    );
}
</script>

<template>
    <Head title="Reset password" />

    <UForm :state="form" @submit="onSubmit">
        <div class="grid gap-6">
            <UFormField label="Email" name="email" :error="form.errors.email">
                <UInput
                    v-model="form.email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    readonly
                    class="mt-1 block w-full"
                />
            </UFormField>

            <UFormField
                label="Password"
                name="password"
                :error="form.errors.password"
                required
            >
                <PasswordInput
                    v-model="form.password"
                    name="password"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    autofocus
                    placeholder="Password"
                    :passwordrules="passwordRules"
                />
            </UFormField>

            <UFormField
                label="Confirm password"
                name="password_confirmation"
                :error="form.errors.password_confirmation"
                required
            >
                <PasswordInput
                    v-model="form.password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="Confirm password"
                    :passwordrules="passwordRules"
                />
            </UFormField>

            <UButton
                type="submit"
                block
                class="mt-4"
                :loading="form.processing"
                :disabled="form.processing"
                data-test="reset-password-button"
            >
                Reset password
            </UButton>
        </div>
    </UForm>
</template>
