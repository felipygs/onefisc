<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Forgot password',
        description: 'Enter your email to receive a password reset link',
    },
});

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

function onSubmit(): void {
    form.post(email.url());
}
</script>

<template>
    <Head title="Forgot password" />

    <UAlert
        v-if="status"
        color="success"
        variant="soft"
        :description="status"
        class="mb-4"
    />

    <div class="space-y-6">
        <UForm :state="form" @submit="onSubmit">
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
                    autocomplete="off"
                    autofocus
                    placeholder="email@example.com"
                    class="w-full"
                />
            </UFormField>

            <div class="my-6 flex items-center justify-start">
                <UButton
                    type="submit"
                    block
                    :loading="form.processing"
                    :disabled="form.processing"
                    data-test="email-password-reset-link-button"
                >
                    Email password reset link
                </UButton>
            </div>
        </UForm>

        <div class="text-muted space-x-1 text-center text-sm">
            <span>Or, return to</span>
            <TextLink :href="login()">log in</TextLink>
        </div>
    </div>
</template>
