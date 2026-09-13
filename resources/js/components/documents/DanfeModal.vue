<script setup lang="ts">
const props = defineProps<{
    title: string;
    pdfUrl: string | null;
    downloadUrl: string | null;
    error: string | null;
    retryable: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    retry: [];
    download: [];
}>();
</script>

<template>
    <UModal
        v-model:open="open"
        :title="props.title"
        description="Pré-visualização do documento auxiliar."
        :ui="{ content: 'sm:max-w-4xl' }"
        data-test="danfe-modal"
    >
        <template #body>
            <UAlert
                v-if="props.error"
                color="error"
                variant="soft"
                title="Não foi possível carregar o preview"
                :description="props.error"
                data-test="danfe-error"
            />

            <iframe
                v-else-if="props.pdfUrl"
                :src="props.pdfUrl"
                :title="`Pré-visualização de ${props.title}`"
                class="border-default h-[70vh] w-full rounded-md border"
                data-test="danfe-preview"
            />

            <USkeleton
                v-else
                class="h-[70vh] w-full"
                data-test="danfe-loading"
            />
        </template>

        <template #footer>
            <div class="flex w-full items-center justify-between gap-2">
                <UButton
                    color="neutral"
                    variant="ghost"
                    label="Fechar"
                    data-test="danfe-close"
                    @click="open = false"
                />

                <div class="flex items-center gap-2">
                    <UButton
                        v-if="props.error && props.retryable"
                        color="neutral"
                        variant="outline"
                        icon="i-lucide-rotate-cw"
                        label="Tentar novamente"
                        data-test="danfe-retry"
                        @click="emit('retry')"
                    />
                    <UButton
                        icon="i-lucide-download"
                        label="Baixar XML"
                        :disabled="!props.downloadUrl"
                        data-test="danfe-download-xml"
                        @click="emit('download')"
                    />
                </div>
            </div>
        </template>
    </UModal>
</template>
