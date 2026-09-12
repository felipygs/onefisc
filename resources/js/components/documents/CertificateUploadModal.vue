<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { store as storeCertificate } from '@/routes/certificates';

const props = defineProps<{
    clientId: number;
}>();

const open = defineModel<boolean>('open', { required: true });

const pfxFile = ref<File | null>(null);
const password = ref('');
const serverError = ref<string | null>(null);
const sending = ref(false);

const toast = useToast();

const formState = computed(() => ({
    pfx: pfxFile.value,
    password: password.value,
}));

function onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    pfxFile.value = input.files?.[0] ?? null;
    serverError.value = null;
}

function reset(): void {
    pfxFile.value = null;
    password.value = '';
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
        return first[0] ?? 'Não foi possível salvar o certificado.';
    }

    return first ?? 'Não foi possível salvar o certificado.';
}

function onSubmit(): void {
    if (!pfxFile.value || password.value === '' || sending.value) {
        return;
    }

    const payload = new FormData();
    payload.append('pfx', pfxFile.value);
    payload.append('password', password.value);

    sending.value = true;
    serverError.value = null;

    router.post(storeCertificate.url({ client: props.clientId }), payload, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            reset();
            toast.add({
                title: 'Certificado instalado.',
                description: 'O certificado A1 está ativo no client.',
            });
        },
        onError: (errors) => {
            sending.value = false;
            serverError.value = firstError(errors);
        },
        onFinish: () => {
            sending.value = false;
        },
    });
}
</script>

<template>
    <UModal
        v-model:open="open"
        title="Enviar certificado A1"
        description="Suba o arquivo PFX do client com a senha de abertura."
        data-test="certificate-upload-modal"
    >
        <template #body>
            <UAlert
                v-if="serverError"
                color="error"
                variant="soft"
                title="Não foi possível instalar o certificado"
                :description="serverError"
                class="mb-4"
                data-test="certificate-upload-alert"
            />

            <UForm :state="formState" class="flex flex-col gap-4" @submit="onSubmit">
                <UFormField label="Arquivo PFX" name="pfx" required>
                    <input
                        type="file"
                        accept=".pfx,.p12"
                        class="text-sm"
                        data-test="certificate-pfx-file"
                        @change="onFileChange"
                    />
                </UFormField>

                <UFormField
                    label="Senha do PFX"
                    name="password"
                    required
                >
                    <UInput
                        v-model="password"
                        type="password"
                        placeholder="Senha de abertura do PFX"
                        autocomplete="new-password"
                        class="w-full"
                        data-test="certificate-pfx-password"
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
                    data-test="certificate-upload-cancel"
                    @click="open = false"
                />
                <UButton
                    label="Instalar"
                    :loading="sending"
                    :disabled="!pfxFile || password === '' || sending"
                    data-test="certificate-upload-submit"
                    @click="onSubmit"
                />
            </div>
        </template>
    </UModal>
</template>
