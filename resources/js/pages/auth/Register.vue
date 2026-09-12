<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { login, register } from '@/routes';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Create an account',
        description: 'Enter your details below to create your account',
    },
});

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function onSubmit(): void {
    form.post(register.url(), {
        onSuccess: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Register" />

    <UForm :state="form" class="flex flex-col gap-6" @submit="onSubmit">
        <div class="grid gap-6">
            <UFormField
                label="Name"
                name="name"
                :error="form.errors.name"
                required
            >
                <UInput
                    v-model="form.name"
                    type="text"
                    name="name"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="name"
                    placeholder="Full name"
                    class="w-full"
                />
            </UFormField>

            <UFormField
                label="Email address"
                name="email"
                :error="form.errors.email"
                required
            >
                <UInput
                    v-model="form.email"
                    type="email"
                    name="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    placeholder="email@example.com"
                    class="w-full"
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
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    placeholder="Password"
                    :passwordrules="passwordRules"
                    class="w-full"
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
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    placeholder="Confirm password"
                    :passwordrules="passwordRules"
                    class="w-full"
                />
            </UFormField>

            <UButton
                type="submit"
                block
                class="mt-2"
                :tabindex="5"
                :loading="form.processing"
                :disabled="form.processing"
                data-test="register-user-button"
            >
                Create account
            </UButton>
        </div>

        <div class="text-muted text-center text-sm">
            Already have an account?
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="6"
                >Log in</TextLink
            >
        </div>
    </UForm>
</template>
