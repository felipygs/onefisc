<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Email verification',
        description:
            'Please verify your email address by clicking on the link we just emailed to you.',
    },
});

defineProps<{
    status?: string;
}>();

const form = useForm({});

function onSubmit(): void {
    form.post(send.url());
}
</script>

<template>
    <Head title="Email verification" />

    <UAlert
        v-if="status === 'verification-link-sent'"
        color="success"
        variant="soft"
        description="A new verification link has been sent to the email address you provided during registration."
        class="mb-4"
    />

    <UForm :state="form" class="space-y-6 text-center" @submit="onSubmit">
        <UButton
            type="submit"
            variant="outline"
            :loading="form.processing"
            :disabled="form.processing"
        >
            Resend verification email
        </UButton>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            Log out
        </TextLink>
    </UForm>
</template>
