<script setup lang="ts">
import { onMounted } from 'vue';

const COOKIE_NAME = 'cookie-consent';

function readConsent(): string | null {
    const match = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith(`${COOKIE_NAME}=`));

    return match?.split('=').at(1) ?? null;
}

function writeConsent(value: string): void {
    document.cookie = `${COOKIE_NAME}=${value};path=/;max-age=31536000;SameSite=Lax`;
}

onMounted(() => {
    if (readConsent() !== null) {
        return;
    }

    useToast().add({
        title: 'Usamos cookies próprios para melhorar sua experiência no produto.',
        duration: 0,
        close: false,
        actions: [
            {
                label: 'Aceitar',
                color: 'neutral',
                variant: 'outline',
                onClick: () => {
                    writeConsent('accepted');
                },
            },
            {
                label: 'Dispensar',
                color: 'neutral',
                variant: 'ghost',
                onClick: () => {
                    writeConsent('dismissed');
                },
            },
        ],
    });
});
</script>

<template>
    <!-- Aviso exibido via toast; sem marcação própria. -->
    <span class="hidden" aria-hidden="true" />
</template>
