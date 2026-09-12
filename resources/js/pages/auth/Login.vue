<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Log in to your account',
        description: 'Enter your email and password below to log in',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function onSubmit(): void {
    form.post(store.url(), {
        onSuccess: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Log in" />

    <UAlert
        v-if="status"
        color="success"
        variant="soft"
        :description="status"
        class="mb-4"
    />

    <PasskeyVerify />

    <UForm :state="form" class="flex flex-col gap-6" @submit="onSubmit">
        <div class="grid gap-6">
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
                    autofocus
                    :tabindex="1"
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
                <template #hint>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        Forgot your password?
                    </TextLink>
                </template>
                <PasswordInput
                    v-model="form.password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Password"
                    class="w-full"
                />
            </UFormField>

            <div class="flex items-center justify-between">
                <UCheckbox
                    v-model="form.remember"
                    name="remember"
                    label="Remember me"
                    :tabindex="3"
                />
            </div>

            <UButton
                type="submit"
                block
                class="mt-4"
                :tabindex="4"
                :loading="form.processing"
                :disabled="form.processing"
                data-test="login-button"
            >
                Log in
            </UButton>
        </div>

        <div class="text-muted text-center text-sm">
            Don't have an account?
            <TextLink :href="register()" :tabindex="5">Sign up</TextLink>
        </div>
    </UForm>
</template>
