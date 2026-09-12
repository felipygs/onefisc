<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { store as inviteStore } from '@/routes/invitations';

interface MemberRow {
    id: number;
    name: string;
    email: string;
    role: string;
}

interface InvitationRow {
    id: number;
    name: string;
    email: string;
    role: string;
    expires_at: string;
}

defineProps<{
    members: MemberRow[];
    invitations: InvitationRow[];
}>();

const ROLE_LABELS: Record<string, string> = {
    super_admin: 'Super admin',
    admin: 'Admin',
    operador: 'Operador',
    user: 'User',
};

const ROLE_OPTIONS = [
    { label: 'Admin', value: 'admin' },
    { label: 'Operador', value: 'operador' },
    { label: 'User', value: 'user' },
];

const page = usePage();

const canInvite = computed<boolean>(
    () => page.props.permissions?.['manage-users'] === true,
);

const form = useForm({
    name: '',
    email: '',
    role: 'operador',
});

function invite(): void {
    form.post(inviteStore.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function expiryLabel(value: string): string {
    return new Date(value).toLocaleDateString('pt-BR', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <Head title="Members" />

    <UPageCard
        title="Membros"
        description="Usuários desta Account e seus papéis."
        data-test="members-card"
    >
        <ul class="divide-default divide-y">
            <li
                v-for="member in members"
                :key="member.id"
                class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                :data-test="`member-${member.id}`"
            >
                <div class="flex items-center gap-3">
                    <UAvatar :alt="member.name" :text="member.name" size="md" />
                    <div>
                        <p class="text-highlighted text-sm font-medium">
                            {{ member.name }}
                        </p>
                        <p class="text-dimmed text-sm">{{ member.email }}</p>
                    </div>
                </div>
                <UBadge color="neutral" variant="subtle">
                    {{ ROLE_LABELS[member.role] ?? member.role }}
                </UBadge>
            </li>
        </ul>
    </UPageCard>

    <UPageCard
        v-if="canInvite"
        title="Convidar"
        description="O convite expira em 7 dias e o convidado define a senha no aceite."
    >
        <form class="flex flex-col gap-4" @submit.prevent="invite">
            <UFormField label="Nome" :error="form.errors.name">
                <UInput
                    v-model="form.name"
                    class="w-full"
                    data-test="invite-name"
                />
            </UFormField>
            <UFormField label="E-mail" :error="form.errors.email">
                <UInput
                    v-model="form.email"
                    type="email"
                    class="w-full"
                    data-test="invite-email"
                />
            </UFormField>
            <UFormField label="Papel" :error="form.errors.role">
                <USelect
                    v-model="form.role"
                    :items="ROLE_OPTIONS"
                    class="w-full"
                    data-test="invite-role"
                />
            </UFormField>
            <div>
                <UButton
                    type="submit"
                    label="Enviar convite"
                    :loading="form.processing"
                    data-test="invite-submit"
                />
            </div>
        </form>
    </UPageCard>

    <UPageCard
        v-if="invitations.length > 0"
        title="Convites pendentes"
        description="Aguardando aceite."
    >
        <ul class="divide-default divide-y">
            <li
                v-for="invitation in invitations"
                :key="invitation.id"
                class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                :data-test="`invitation-${invitation.id}`"
            >
                <div>
                    <p class="text-highlighted text-sm font-medium">
                        {{ invitation.name }}
                    </p>
                    <p class="text-dimmed text-sm">
                        {{ invitation.email }} · expira em
                        {{ expiryLabel(invitation.expires_at) }}
                    </p>
                </div>
                <UBadge color="warning" variant="subtle">
                    {{ ROLE_LABELS[invitation.role] ?? invitation.role }}
                </UBadge>
            </li>
        </ul>
    </UPageCard>
</template>
