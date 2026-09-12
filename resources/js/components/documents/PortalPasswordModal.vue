<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { portalPassword } from '@/routes/certificates';

const props = defineProps<{
    clientId: number;
}>();

const open = defineModel<boolean>('open', { required: true });

const portalSecret = ref('');
const serverError = ref<string | null>(null);
const sending = ref(false);

const toast = useToast();

const formState = computed(() => ({
    portal_password: portalSecret.value,
}));

function reset(): void {
    portalSecret.value = '';
    serverError.value = null;
    sending.value = false;
}

watch(open, (isOpen) => {
    if (isOpen) {
        reset();
    }
});

function firstError(errors: Record<string, string | string[]>): string {
    const first = Object.values(errors)[0];

    if (Array.isArray(first)) {
        return first[0] ?? 'Não foi possível salvar a senha.';
    }

    return first ?? 'Não foi possível salvar a senha.';
}

function onSubmit(): void {
    if (portalSecret.value === '' || sending.value) {
        return;
    }

    sending.value = true;
    serverError.value = null;

    router.put(
        portalPassword.url({ client: props.clientId }),
        { portal_password: portalSecret.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                reset();
                toast.add({
                    title: 'Senha do portal salva.',
                    description:
                        'A senha ficará disponível para a sincronização.',
                });
            },
            onError: (errors) => {
                sending.value = false;
                serverError.value = firstError(errors);
            },
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}
</script>

<template>
    <UModal
        v-model:open="open"
        title="Senha do portal NFS-e"
        description="Salva a senha do Emissor Nacional como alternativa ao certificado. O valor nunca é exibido de volta."
        data-test="certificate-portal-modal"
    >
        <template #body>
            <UAlert
                v-if="serverError"
                color="error"
                variant="soft"
                title="Não foi possível salvar a senha"
                :description="serverError"
                class="mb-4"
                data-test="certificate-portal-alert"
            />

            <UForm :state="formState" class="flex flex-col gap-4" @submit="onSubmit">
                <UFormField
                    label="Senha do portal"
                    name="portal_password"
                    required
                >
                    <UInput
                        v-model="portalSecret"
                        type="password"
                        placeholder="Senha do portal do Emissor Nacional"
                        autocomplete="new-password"
                        class="w-full"
                        data-test="certificate-portal-password"
                    />
                </UFormField>
            </UForm>
        </template>

        <template #footer>
            <div class="flex justify-end gap-2">
                <UButton
                    color="neutral"
                    variant="ghost"
                    label="Cancelar"
                    data-test="certificate-portal-cancel"
                    @click="open = false"
                />
                <UButton
                    label="Salvar"
                    :loading="sending"
                    :disabled="portalSecret === '' || sending"
                    data-test="certificate-portal-submit"
                    @click="onSubmit"
                />
            </div>
        </template>
    </UModal>
</template>
