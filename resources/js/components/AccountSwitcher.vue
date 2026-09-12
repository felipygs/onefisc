<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { destroy } from '@/routes/switcher';

const page = usePage();

const currentAccount = computed(() => page.props.currentAccount);
const isSwitching = computed(() => page.props.isSwitching === true);

const exiting = ref(false);

function exitSwitch(): void {
    exiting.value = true;
    router.delete(destroy.url(), {
        onFinish: () => {
            exiting.value = false;
        },
    });
}
</script>

<template>
    <UAlert
        v-if="isSwitching"
        color="warning"
        variant="soft"
        :title="`Atuando como ${currentAccount?.name ?? '—'}`"
        description="Sessão de impersonação ativa. Saia para voltar à account de origem."
        data-test="switcher-banner"
        class="rounded-none"
    >
        <template #actions>
            <UButton
                color="warning"
                size="xs"
                :loading="exiting"
                :disabled="exiting"
                data-test="switcher-exit"
                @click="exitSwitch"
            >
                Sair da impersonação
            </UButton>
        </template>
    </UAlert>
</template>
