<script setup lang="ts">
import { ref, useTemplateRef } from 'vue';

defineOptions({ inheritAttrs: false });

const showPassword = ref(false);
const inputRef = useTemplateRef('inputRef');

function focusInput(): void {
    inputRef.value?.inputRef?.focus();
}

defineExpose({
    focus: focusInput,
});
</script>

<template>
    <UInput
        ref="inputRef"
        :type="showPassword ? 'text' : 'password'"
        :ui="{ trailing: 'pe-1' }"
        v-bind="$attrs"
    >
        <template #trailing>
            <UButton
                type="button"
                variant="link"
                color="neutral"
                :icon="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                :tabindex="-1"
                @click="showPassword = !showPassword"
            />
        </template>
    </UInput>
</template>
