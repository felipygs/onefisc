<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui';
import { router, usePage } from '@inertiajs/vue3';
import colors from 'tailwindcss/colors';
import { computed, onMounted, ref } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { getInitials } from '@/composables/useInitials';
import { logout } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { index as plansIndex } from '@/routes/plans';
import { edit as editProfile } from '@/routes/profile';

defineProps<{
    collapsed?: boolean;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const { appearance, updateAppearance } = useAppearance();

const PRIMARY_OPTIONS = [
    'red',
    'orange',
    'amber',
    'yellow',
    'lime',
    'green',
    'emerald',
    'teal',
    'cyan',
    'sky',
    'blue',
    'indigo',
    'violet',
    'purple',
    'fuchsia',
    'pink',
    'rose',
];

const NEUTRAL_OPTIONS = [
    'slate',
    'gray',
    'zinc',
    'neutral',
    'stone',
    'taupe',
    'mauve',
    'mist',
    'olive',
];

const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

const activePrimary = ref('green');
const activeNeutral = ref('zinc');

const APPEARANCE_OPTIONS = [
    { value: 'light', label: 'Clara', icon: 'i-lucide-sun' },
    { value: 'dark', label: 'Escura', icon: 'i-lucide-moon' },
    { value: 'system', label: 'Sistema', icon: 'i-lucide-monitor' },
] as const;

type AppearanceOption = (typeof APPEARANCE_OPTIONS)[number];

function chipColor(item: unknown): string {
    const chip = (item as { chip?: string }).chip ?? 'green';

    // `neutral` é apelidado para `old-neutral` (estático, sempre emitido),
    // igual ao template — evita referência circular com o neutro ativo.
    return chip === 'neutral' ? 'old-neutral' : chip;
}

function chipVars(item: unknown): Record<string, string> {
    const chip = chipColor(item);

    return {
        '--chip-light': `var(--color-${chip}-500, ${getFallback(chip, 500)})`,
        '--chip-dark': `var(--color-${chip}-400, ${getFallback(chip, 400)})`,
    };
}

/**
 * Valor estático de fallback para um tom da paleta.
 *
 * O Tailwind v4 tree-shake os tons não utilizados pelos utilitários, então
 * `var(--color-<cor>-<tom>)` pode não existir no CSS final. Sem fallback, a
 * troca de tema zerava as variáveis `--ui-color-*` (bordas/superfícies
 * quebravam). Espelha o plugin de cores do próprio Nuxt UI.
 */
function getFallback(color: string, shade: number): string {
    const key = color === 'old-neutral' ? 'neutral' : color;
    const palette = (
        colors as unknown as Record<string, Record<number, string> | undefined>
    )[key];
    const value = palette?.[shade];

    return typeof value === 'string' ? value : '';
}

function themeVar(color: string, shade: number): string {
    return `var(--color-${color}-${shade}, ${getFallback(color, shade)})`;
}

function applyThemeColors(primary: string, neutral: string): void {
    const root = document.documentElement;

    for (const shade of SHADES) {
        root.style.setProperty(
            `--ui-color-primary-${shade}`,
            themeVar(primary, shade),
        );
        root.style.setProperty(
            `--ui-color-neutral-${shade}`,
            themeVar(neutral, shade),
        );
    }

    activePrimary.value = primary;
    activeNeutral.value = neutral;

    try {
        localStorage.setItem('theme-primary', primary);
        localStorage.setItem('theme-neutral', neutral);
    } catch {
        // Storage indisponível: mantém o tema da sessão.
    }
}

onMounted(() => {
    let primary = 'green';
    let neutral = 'zinc';

    try {
        primary = localStorage.getItem('theme-primary') ?? primary;
        neutral = localStorage.getItem('theme-neutral') ?? neutral;
    } catch {
        // Storage indisponível: mantém o padrão.
    }

    if (primary !== 'green' || neutral !== 'zinc') {
        applyThemeColors(primary, neutral);
    } else {
        activePrimary.value = primary;
        activeNeutral.value = neutral;
    }
});

function handleLogout(): void {
    router.flushAll();
    router.post(logout.url());
}

const items = computed<DropdownMenuItem[][]>(() => [
    [
        {
            type: 'label' as const,
            label: user.value.name,
            description: user.value.email,
            avatar: user.value.avatar
                ? { src: user.value.avatar, alt: user.value.name }
                : undefined,
        },
    ],
    [
        {
            label: 'Perfil',
            icon: 'i-lucide-user',
            to: editProfile.url(),
        },
        {
            label: 'Planos',
            icon: 'i-lucide-credit-card',
            to: plansIndex.url(),
        },
        {
            label: 'Ajustes',
            icon: 'i-lucide-settings',
            to: editAppearance.url(),
        },
    ],
    [
        {
            label: 'Tema',
            icon: 'i-lucide-palette',
            children: [
                {
                    label: 'Primária',
                    slot: 'chip' as const,
                    chip: activePrimary.value,
                    children: PRIMARY_OPTIONS.map((color) => ({
                        label: color,
                        chip: color,
                        slot: 'chip' as const,
                        type: 'checkbox' as const,
                        checked: activePrimary.value === color,
                        onSelect(e: Event) {
                            e.preventDefault();
                            applyThemeColors(color, activeNeutral.value);
                        },
                    })),
                },
                {
                    label: 'Neutra',
                    slot: 'chip' as const,
                    chip: activeNeutral.value,
                    children: NEUTRAL_OPTIONS.map((color) => ({
                        label: color,
                        chip: color,
                        slot: 'chip' as const,
                        type: 'checkbox' as const,
                        checked: activeNeutral.value === color,
                        onSelect(e: Event) {
                            e.preventDefault();
                            applyThemeColors(activePrimary.value, color);
                        },
                    })),
                },
            ],
        },
        {
            label: 'Aparência',
            icon: 'i-lucide-sun-moon',
            children: APPEARANCE_OPTIONS.map((option: AppearanceOption) => ({
                label: option.label,
                icon: option.icon,
                type: 'checkbox' as const,
                checked: appearance.value === option.value,
                onSelect(e: Event) {
                    e.preventDefault();
                    updateAppearance(option.value);
                },
            })),
        },
    ],
    [
        {
            label: 'Documentação',
            icon: 'i-lucide-book-open',
            to: 'https://laravel.com/docs/starter-kits#vue',
            target: '_blank',
        },
        {
            label: 'Sair',
            icon: 'i-lucide-log-out',
            onSelect() {
                handleLogout();
            },
        },
    ],
]);
</script>

<template>
    <UDropdownMenu
        :items="items"
        :content="{ align: 'center', collisionPadding: 12 }"
        :ui="{
            content: collapsed
                ? 'w-48'
                : 'w-(--reka-dropdown-menu-trigger-width)',
        }"
    >
        <UButton
            :label="collapsed ? undefined : user?.name"
            :avatar="
                user?.avatar
                    ? { src: user.avatar, alt: user.name }
                    : {
                          text: getInitials(user?.name) || '—',
                          alt: user?.name ?? 'Usuário',
                      }
            "
            :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
            color="neutral"
            variant="ghost"
            block
            :square="collapsed"
            class="data-[state=open]:bg-elevated"
            :ui="{ trailingIcon: 'text-dimmed' }"
            data-test="user-menu"
        />

        <template #chip-leading="{ item }">
            <span
                class="inline-flex size-5 shrink-0 items-center justify-center"
            >
                <span
                    class="ring-bg size-2 rounded-full bg-(--chip-light) ring dark:bg-(--chip-dark)"
                    :style="chipVars(item)"
                />
            </span>
        </template>
    </UDropdownMenu>
</template>
