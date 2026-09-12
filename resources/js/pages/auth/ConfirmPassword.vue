<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { store } from '@/routes/password/confirm';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';

defineOptions({
    layout: {
        title: 'Confirm password',
        description:
            'This is a secure area of the application. Please confirm your password before continuing.',
    },
});

const form = useForm({
    password: '',
});

function onSubmit(): void {
    form.post(store.url(), {
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Confirm password" />

    <PasskeyVerify
        :routes="{
            options: confirmOptions(),
            submit: confirmStore(),
        }"
        label="Confirm with passkey"
        loading-label="Confirming..."
        separator="Or confirm with password"
    />

    <UForm :state="form" @submit="onSubmit">
        <div class="space-y-6">
            <UFormField
                label="Password"
                name="password"
                :error="form.errors.password"
                required
            >
                <PasswordInput
                    v-model="form.password"
                    name="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    autofocus
                />
            </UFormField>

            <div class="flex items-center">
                <UButton
                    type="submit"
                    block
                    :loading="form.processing"
                    :disabled="form.processing"
                    data-test="confirm-password-button"
                >
                    Confirm password
                </UButton>
            </div>
        </div>
    </UForm>
</template>
