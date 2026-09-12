<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { CertificateState } from '@/types/documents';

const props = defineProps<{
    certificate: CertificateState;
}>();

const emit = defineEmits<{
    'open-upload': [];
    'open-portal': [];
}>();

type BadgeColor =
    | 'primary'
    | 'secondary'
    | 'success'
    | 'info'
    | 'warning'
    | 'error'
    | 'neutral';

const STATUS_LABELS: Record<CertificateState['status'], string> = {
    valid: 'Válido',
    expiring: 'Vence em breve',
    expired: 'Expirado',
    missing: 'Ausente',
};

const STATUS_COLORS: Record<CertificateState['status'], BadgeColor> = {
    valid: 'success',
    expiring: 'warning',
    expired: 'error',
    missing: 'neutral',
};

const page = usePage();

// Same admin gate as the backend (manage-certificates); the shared
// permissions bag only exposes manage-users, which is equivalent.
const canManage = computed<boolean>(
    () => page.props.permissions?.['manage-users'] === true,
);

const expiresLabel = computed<string | null>(() => {
    if (
        props.certificate.status !== 'valid' &&
        props.certificate.status !== 'expiring'
    ) {
        return null;
    }

    const parsed = new Date(props.certificate.expires_at);

    if (Number.isNaN(parsed.getTime())) {
        return null;
    }

    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(
        parsed,
    );
});

const hint = computed<string>(() => {
    switch (props.certificate.status) {
        case 'valid':
            return 'Certificado ativo para as consultas SEFAZ.';
        case 'expiring':
            return 'O certificado vence em breve. Suba um novo PFX.';
        case 'expired':
            return 'Certificado expirado. A sincronização está suspensa até a troca.';
        case 'missing':
            return 'Nenhum certificado instalado. Suba o PFX do client.';
    }
});
</script>

<template>
    <UCard data-test="certificate-rail">
        <template #header>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Certificado A1</h2>
                <UBadge
                    :color="STATUS_COLORS[certificate.status]"
                    variant="soft"
                    data-test="certificate-badge"
                >
                    {{ STATUS_LABELS[certificate.status] }}
                </UBadge>
            </div>
        </template>

        <div class="flex flex-col gap-2">
            <p v-if="expiresLabel" class="text-sm tabular-nums">
                Válido até {{ expiresLabel }}
            </p>
            <p class="text-muted text-sm" data-test="certificate-hint">
                {{ hint }}
            </p>
        </div>

        <template v-if="canManage" #footer>
            <div class="flex flex-wrap items-center gap-2">
                <UButton
                    size="sm"
                    icon="i-lucide-upload"
                    data-test="certificate-upload-open"
                    @click="emit('open-upload')"
                >
                    Enviar PFX
                </UButton>
                <UButton
                    size="sm"
                    variant="outline"
                    icon="i-lucide-key-round"
                    data-test="certificate-portal-open"
                    @click="emit('open-portal')"
                >
                    Senha do portal
                </UButton>
            </div>
        </template>
    </UCard>
</template>
