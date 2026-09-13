<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import WorkChildNav from '@/components/work/WorkChildNav.vue';
import { processos as workProcessos } from '@/routes/work';
import { index as tasksIndex, show as taskShow } from '@/routes/work/tasks';

type BoardStatus = 'backlog' | 'todo' | 'in_progress' | 'done';

type AgingBand = '1-7' | '8-15' | '16-30' | '31+';

interface MemberRef {
    id: number;
    name: string;
}

interface OverviewLink {
    route: string;
    competence?: string;
    status?: string;
    view?: string;
    assignee_id?: number;
    task?: number;
}

interface CounterMap {
    backlog: number;
    todo: number;
    in_progress: number;
    done: number;
    overdue: number;
    total: number;
}

interface LoadRow {
    member: MemberRef;
    backlog: number;
    todo: number;
    in_progress: number;
    done: number;
    total: number;
}

interface TeamRow extends LoadRow {
    completion_pct: number;
    link: OverviewLink;
}

interface AttentionTask {
    id: number;
    title: string;
    status: string;
    due_on: string | null;
    age_days: number;
    process: { id: number; title: string } | null;
    client: { id: number; razao_social: string } | null;
    assignee: MemberRef | null;
    link: OverviewLink;
}

interface OverviewPayload {
    competence: string;
    today: string;
    hasData: boolean;
    counters: CounterMap;
    links: Record<BoardStatus | 'overdue', OverviewLink>;
    donut: { done: number; total: number; label: string };
    load: LoadRow[];
    aging: { bands: Record<AgingBand, number>; total: number };
    attention: { overdue: AttentionTask[]; stale_backlog: number };
    team: TeamRow[];
}

const props = defineProps<{
    competence: string;
    today: string;
    overview: OverviewPayload;
}>();

const BOARD_ORDER: BoardStatus[] = ['backlog', 'todo', 'in_progress', 'done'];

const STATUS_LABELS: Record<BoardStatus, string> = {
    backlog: 'Backlog',
    todo: 'A fazer',
    in_progress: 'Em andamento',
    done: 'Concluídas',
};

// Núcleos sólidos via escala neutra do Tailwind (sempre disponível); só
// `done` usa a cor primária do tema (`--ui-primary`, mesmo token do
// HomeChart). Sem lib de charts: SVG/CSS puros, contagens exatas.
const SEGMENT_CLASS: Record<BoardStatus, string> = {
    backlog: 'bg-neutral-300 dark:bg-neutral-600',
    todo: 'bg-neutral-400 dark:bg-neutral-500',
    in_progress: 'bg-neutral-500 dark:bg-neutral-400',
    done: '',
};

const AGING_BANDS: Array<{ key: AgingBand; label: string }> = [
    { key: '1-7', label: '1–7 dias' },
    { key: '8-15', label: '8–15 dias' },
    { key: '16-30', label: '16–30 dias' },
    { key: '31+', label: '31+ dias' },
];

const EMPTY_TITLE = 'Nada por aqui nesta competência';
const EMPTY_DESCRIPTION =
    'Associe empresas aos processos operacionais e as tarefas da competência aparecem neste painel.';

function formatDate(iso: string | null): string {
    if (iso === null || iso === '') {
        return 'Sem prazo';
    }

    const [year, month, day] = iso.split('-').map(Number);

    if (!year || !month || !day) {
        return iso;
    }

    return new Date(year, month - 1, day).toLocaleDateString('pt-BR');
}

function ageText(days: number): string {
    return days === 1 ? 'há 1 dia' : `há ${days} dias`;
}

const monthTitleFmt = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

function competenceTitle(value: string): string {
    return monthTitleFmt.format(new Date(`${value}-01T00:00:00Z`));
}

// Competência sticky-local (mesmo padrão da âncora do calendário): vive no
// componente + localStorage, nunca na query da URL. A troca recarrega os
// painéis via `router.reload({ data })` com a URL limpa (`preserveUrl`).
const COMPETENCE_KEY = 'work.overview.competence.v1';
const COMPETENCE_PATTERN = /^\d{4}-(0[1-9]|1[0-2])$/;

function loadCompetence(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(COMPETENCE_KEY);

        if (raw !== null && COMPETENCE_PATTERN.test(raw)) {
            return raw;
        }
    } catch {
        // Armazenamento indisponível ou corrompido: usa a prop do servidor.
    }

    return null;
}

function persistCompetence(next: string): void {
    try {
        window.localStorage.setItem(COMPETENCE_KEY, next);
    } catch {
        // Armazenamento indisponível: a central segue funcional na sessão.
    }
}

const competenceInput = ref<string>(
    loadCompetence() ?? props.overview.competence,
);

// Guarda contra respostas atrasadas (mesmo padrão do WorkCalendar): cada
// reload ganha um token crescente e só a resposta da competência mais
// recente pinta os painéis.
let requestSeq = 0;
const latestSeq = ref(0);
const latestKey = ref(props.overview.competence);
const visible = ref<OverviewPayload>(props.overview);
const loading = ref(false);
const loadError = ref<string | null>(null);

watch(
    () => props.overview,
    (incoming) => {
        if (incoming.competence !== latestKey.value) {
            return;
        }

        visible.value = incoming;
    },
);

function requestCompetence(next: string): void {
    competenceInput.value = next;
    persistCompetence(next);
    requestSeq += 1;
    const seq = requestSeq;
    latestSeq.value = seq;
    latestKey.value = next;
    loading.value = true;
    loadError.value = null;

    router.reload({
        data: { competence: next },
        only: ['competence', 'today', 'overview'],
        preserveUrl: true,
        onError: () => {
            if (seq === latestSeq.value) {
                loadError.value =
                    'Não foi possível carregar a central. Tente novamente.';
            }
        },
        onFinish: () => {
            if (seq === latestSeq.value) {
                loading.value = false;
            }
        },
    });
}

function onCompetenceChange(): void {
    const next = competenceInput.value;

    if (next === visible.value.competence) {
        return;
    }

    if (!COMPETENCE_PATTERN.test(next)) {
        competenceInput.value = visible.value.competence;
        return;
    }

    requestCompetence(next);
}

function retry(): void {
    requestCompetence(competenceInput.value);
}

onMounted(() => {
    // Competência sticky de outra sessão: recarrega uma vez para o mês
    // guardado em vez de mostrar o mês corrente e pular depois.
    const stored = loadCompetence();

    if (stored !== null && stored !== props.overview.competence) {
        requestCompetence(stored);
    }
});

// Deep-links vindos do backend (route+params): contadores por situação vão
// para a lista de tarefas filtrada por competência+status; atrasadas e
// equipe caem no quadro/tarefas filtrados por competência.
function counterHref(status: BoardStatus): string {
    const link = visible.value.links[status];

    return tasksIndex.url({
        query: {
            competence: link.competence ?? visible.value.competence,
            status: link.status ?? status,
        },
    });
}

function totalHref(): string {
    return tasksIndex.url({
        query: { competence: visible.value.competence },
    });
}

function overdueHref(): string {
    const link = visible.value.links.overdue;

    return workProcessos.url(
        { view: link.view ?? 'tarefas' },
        { query: { competence: link.competence ?? visible.value.competence } },
    );
}

function memberHref(memberId: number): string {
    return tasksIndex.url({
        query: {
            competence: visible.value.competence,
            assignee_id: memberId,
        },
    });
}

function taskHref(taskId: number): string {
    return taskShow.url({ task: taskId });
}

const DONUT_RADIUS = 48;
const DONUT_CIRCUMFERENCE = 2 * Math.PI * DONUT_RADIUS;

const donutFraction = computed(() =>
    visible.value.donut.total > 0
        ? visible.value.donut.done / visible.value.donut.total
        : 0,
);

const donutDash = computed(
    () =>
        `${(donutFraction.value * DONUT_CIRCUMFERENCE).toFixed(2)} ${DONUT_CIRCUMFERENCE.toFixed(2)}`,
);

const maxLoad = computed(() =>
    visible.value.load.reduce((max, row) => Math.max(max, row.total), 0),
);

const maxAging = computed(() =>
    AGING_BANDS.reduce(
        (max, band) => Math.max(max, visible.value.aging.bands[band.key]),
        0,
    ),
);

function segmentWidth(count: number, total: number): string {
    if (total <= 0) {
        return '0%';
    }

    return `${((count / total) * 100).toFixed(1)}%`;
}
</script>

<template>
    <Head title="Visão geral do trabalho" />

    <UDashboardPanel id="work-overview">
        <template #header>
            <UDashboardNavbar title="Work">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <div class="flex flex-col gap-4" data-test="work-overview">
                <WorkChildNav active="overview" />

                <div class="flex flex-wrap items-center gap-2">
                    <h1
                        class="min-w-0 flex-1 text-sm font-semibold first-letter:uppercase"
                        data-test="work-overview-title"
                    >
                        {{ competenceTitle(visible.competence) }}
                    </h1>

                    <label
                        class="text-muted flex items-center gap-2 text-sm"
                        for="work-overview-competence"
                    >
                        Competência
                        <input
                            id="work-overview-competence"
                            v-model="competenceInput"
                            type="month"
                            class="border-default bg-default text-default rounded-md border px-2 py-1 text-sm"
                            data-test="work-overview-competence"
                            @change="onCompetenceChange"
                        />
                    </label>
                </div>

                <p
                    v-if="loading"
                    class="text-muted text-sm"
                    data-test="work-overview-loading"
                >
                    A carregar central…
                </p>

                <!-- 1. Situação: contadores por status + atrasadas -->
                <section
                    data-test="work-overview-situation"
                    aria-label="Situação das tarefas"
                    class="flex flex-col gap-2"
                >
                    <h2 class="text-sm font-semibold">Situação</h2>

                    <div
                        v-if="loading"
                        class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6"
                        data-test="work-overview-loading-situation"
                        aria-hidden="true"
                    >
                        <div
                            v-for="n in 6"
                            :key="n"
                            class="bg-elevated h-20 animate-pulse rounded-lg"
                        />
                    </div>

                    <UPageCard
                        v-else-if="loadError !== null"
                        title="Falha ao carregar a situação"
                        :description="loadError"
                        icon="i-lucide-chart-column"
                        data-test="work-overview-error-situation"
                    >
                        <UButton
                            color="neutral"
                            variant="solid"
                            size="sm"
                            class="mt-3"
                            data-test="work-overview-retry-situation"
                            @click="retry"
                        >
                            Tentar novamente
                        </UButton>
                    </UPageCard>

                    <UPageCard
                        v-else-if="!visible.hasData"
                        :title="EMPTY_TITLE"
                        :description="EMPTY_DESCRIPTION"
                        icon="i-lucide-chart-column"
                        data-test="work-overview-empty-situation"
                    />

                    <div
                        v-else
                        class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6"
                    >
                        <!-- Âncoras puras: este stack é Vue 3 + Inertia sem
                             Nuxt — `NuxtLink` não existe e renderizaria um
                             elemento morto (pego no smoke 3.4). -->
                        <a
                            v-for="status in BOARD_ORDER"
                            :key="status"
                            :href="counterHref(status)"
                            :data-test="`work-overview-counter-${status}`"
                            class="border-default hover:border-primary rounded-lg border p-3 transition-colors"
                        >
                            <p class="text-muted text-xs">
                                {{ STATUS_LABELS[status] }}
                            </p>
                            <p
                                class="text-highlighted text-2xl font-semibold tabular-nums"
                            >
                                {{ visible.counters[status] }}
                            </p>
                        </a>
                        <a
                            :href="overdueHref()"
                            data-test="work-overview-counter-overdue"
                            class="border-default hover:border-primary rounded-lg border p-3 transition-colors"
                        >
                            <p class="text-muted text-xs">Atrasadas</p>
                            <p
                                class="text-error text-2xl font-semibold tabular-nums"
                            >
                                {{ visible.counters.overdue }}
                            </p>
                        </a>
                        <a
                            :href="totalHref()"
                            data-test="work-overview-counter-total"
                            class="border-default hover:border-primary rounded-lg border p-3 transition-colors"
                        >
                            <p class="text-muted text-xs">Total</p>
                            <p
                                class="text-highlighted text-2xl font-semibold tabular-nums"
                            >
                                {{ visible.counters.total }}
                            </p>
                        </a>
                    </div>
                </section>

                <!-- 2. Donut: associações processo–empresa concluídas -->
                <section
                    data-test="work-overview-donut-panel"
                    aria-label="Processos concluídos"
                    class="flex flex-col gap-2"
                >
                    <h2 class="text-sm font-semibold">Processos concluídos</h2>

                    <div
                        v-if="loading"
                        class="bg-elevated h-36 animate-pulse rounded-lg"
                        data-test="work-overview-loading-donut"
                        aria-hidden="true"
                    />

                    <UPageCard
                        v-else-if="loadError !== null"
                        title="Falha ao carregar os concluídos"
                        :description="loadError"
                        icon="i-lucide-chart-pie"
                        data-test="work-overview-error-donut"
                    >
                        <UButton
                            color="neutral"
                            variant="solid"
                            size="sm"
                            class="mt-3"
                            data-test="work-overview-retry-donut"
                            @click="retry"
                        >
                            Tentar novamente
                        </UButton>
                    </UPageCard>

                    <UPageCard
                        v-else-if="!visible.hasData"
                        :title="EMPTY_TITLE"
                        :description="EMPTY_DESCRIPTION"
                        icon="i-lucide-chart-pie"
                        data-test="work-overview-empty-donut"
                    />

                    <div
                        v-else
                        class="border-default flex flex-wrap items-center gap-4 rounded-lg border p-4"
                        data-test="work-overview-donut"
                    >
                        <div class="relative h-28 w-28 shrink-0">
                            <svg
                                viewBox="0 0 120 120"
                                class="h-full w-full -rotate-90"
                                role="img"
                                :aria-label="`${visible.donut.label} associações concluídas`"
                            >
                                <circle
                                    cx="60"
                                    cy="60"
                                    :r="DONUT_RADIUS"
                                    fill="none"
                                    stroke="var(--ui-border)"
                                    stroke-width="16"
                                />
                                <circle
                                    cx="60"
                                    cy="60"
                                    :r="DONUT_RADIUS"
                                    fill="none"
                                    stroke="var(--ui-primary)"
                                    stroke-width="16"
                                    stroke-linecap="round"
                                    :stroke-dasharray="donutDash"
                                />
                            </svg>
                            <p
                                class="text-highlighted absolute inset-0 flex items-center justify-center text-sm font-semibold"
                                data-test="work-overview-donut-text"
                            >
                                {{ visible.donut.label }}
                            </p>
                        </div>
                        <div class="min-w-0 flex-1 text-sm">
                            <p class="font-medium">
                                {{ visible.donut.done }} de
                                {{ visible.donut.total }}
                                {{
                                    visible.donut.total === 1
                                        ? 'associação concluída'
                                        : 'associações concluídas'
                                }}
                            </p>
                            <p class="text-muted mt-1">
                                Uma associação conta como concluída quando todas
                                as tarefas estão concluídas.
                            </p>
                        </div>
                    </div>
                </section>

                <!-- 3. Carga por colaborador: barras empilhadas por situação -->
                <section
                    data-test="work-overview-load"
                    aria-label="Carga por colaborador"
                    class="flex flex-col gap-2"
                >
                    <h2 class="text-sm font-semibold">Carga por colaborador</h2>

                    <div
                        v-if="loading"
                        class="flex flex-col gap-2"
                        data-test="work-overview-loading-load"
                        aria-hidden="true"
                    >
                        <div
                            v-for="n in 3"
                            :key="n"
                            class="bg-elevated h-12 animate-pulse rounded-lg"
                        />
                    </div>

                    <UPageCard
                        v-else-if="loadError !== null"
                        title="Falha ao carregar a carga"
                        :description="loadError"
                        icon="i-lucide-chart-bar"
                        data-test="work-overview-error-load"
                    >
                        <UButton
                            color="neutral"
                            variant="solid"
                            size="sm"
                            class="mt-3"
                            data-test="work-overview-retry-load"
                            @click="retry"
                        >
                            Tentar novamente
                        </UButton>
                    </UPageCard>

                    <UPageCard
                        v-else-if="!visible.hasData"
                        :title="EMPTY_TITLE"
                        :description="EMPTY_DESCRIPTION"
                        icon="i-lucide-chart-bar"
                        data-test="work-overview-empty-load"
                    />

                    <UPageCard
                        v-else-if="visible.load.length === 0"
                        title="Sem carga atribuída"
                        description="Há tarefas na competência, mas nenhuma com responsável. Atribua responsáveis para distribuir o trabalho aqui."
                        icon="i-lucide-user-x"
                        data-test="work-overview-empty-load-unassigned"
                    />

                    <ul v-else class="flex flex-col gap-2">
                        <li
                            v-for="row in visible.load"
                            :key="row.member.id"
                            :data-test="`work-overview-load-${row.member.id}`"
                            class="border-default rounded-lg border p-3"
                        >
                            <div
                                class="flex items-baseline justify-between gap-2 text-sm"
                            >
                                <span
                                    class="min-w-0 flex-1 truncate font-medium"
                                >
                                    {{ row.member.name }}
                                </span>
                                <span class="text-muted tabular-nums">
                                    {{ row.total }}
                                    {{ row.total === 1 ? 'tarefa' : 'tarefas' }}
                                </span>
                            </div>
                            <div
                                class="bg-elevated mt-2 flex h-3 overflow-hidden rounded-full"
                                role="img"
                                :aria-label="`${row.member.name}: ${row.total} tarefas`"
                            >
                                <span
                                    v-for="status in BOARD_ORDER"
                                    :key="status"
                                    :class="SEGMENT_CLASS[status]"
                                    :style="{
                                        width: segmentWidth(
                                            row[status],
                                            maxLoad,
                                        ),
                                        ...(status === 'done'
                                            ? {
                                                  backgroundColor:
                                                      'var(--ui-primary)',
                                              }
                                            : {}),
                                    }"
                                    :title="`${STATUS_LABELS[status]}: ${row[status]}`"
                                />
                            </div>
                            <p class="text-muted mt-1.5 text-xs tabular-nums">
                                <span
                                    v-for="(status, index) in BOARD_ORDER"
                                    :key="status"
                                >
                                    <span v-if="index > 0"> · </span>
                                    {{ STATUS_LABELS[status] }}:
                                    {{ row[status] }}
                                </span>
                            </p>
                        </li>
                    </ul>
                </section>

                <!-- 4. Envelhecimento dos atrasos por faixa -->
                <section
                    data-test="work-overview-aging"
                    aria-label="Envelhecimento dos atrasos"
                    class="flex flex-col gap-2"
                >
                    <h2 class="text-sm font-semibold">
                        Envelhecimento dos atrasos
                    </h2>

                    <div
                        v-if="loading"
                        class="bg-elevated h-36 animate-pulse rounded-lg"
                        data-test="work-overview-loading-aging"
                        aria-hidden="true"
                    />

                    <UPageCard
                        v-else-if="loadError !== null"
                        title="Falha ao carregar o envelhecimento"
                        :description="loadError"
                        icon="i-lucide-hourglass"
                        data-test="work-overview-error-aging"
                    >
                        <UButton
                            color="neutral"
                            variant="solid"
                            size="sm"
                            class="mt-3"
                            data-test="work-overview-retry-aging"
                            @click="retry"
                        >
                            Tentar novamente
                        </UButton>
                    </UPageCard>

                    <UPageCard
                        v-else-if="!visible.hasData"
                        :title="EMPTY_TITLE"
                        :description="EMPTY_DESCRIPTION"
                        icon="i-lucide-hourglass"
                        data-test="work-overview-empty-aging"
                    />

                    <UPageCard
                        v-else-if="visible.aging.total === 0"
                        title="Sem atrasos"
                        description="Nenhuma tarefa aberta venceu antes de hoje nesta competência."
                        icon="i-lucide-check"
                        data-test="work-overview-empty-aging-clean"
                    />

                    <ul
                        v-else
                        class="border-default flex flex-col gap-2 rounded-lg border p-4"
                    >
                        <li
                            v-for="band in AGING_BANDS"
                            :key="band.key"
                            :data-test="`work-overview-aging-band-${band.key}`"
                            class="flex items-center gap-3 text-sm"
                        >
                            <span class="w-20 shrink-0 tabular-nums">
                                {{ band.label }}
                            </span>
                            <span
                                class="h-3 min-w-1 rounded-full"
                                :style="{
                                    width: segmentWidth(
                                        visible.aging.bands[band.key],
                                        maxAging,
                                    ),
                                    backgroundColor: 'var(--ui-primary)',
                                    opacity:
                                        band.key === '1-7'
                                            ? 0.35
                                            : band.key === '8-15'
                                              ? 0.55
                                              : band.key === '16-30'
                                                ? 0.8
                                                : 1,
                                }"
                            />
                            <span class="text-muted tabular-nums">
                                {{ visible.aging.bands[band.key] }}
                            </span>
                        </li>
                    </ul>
                </section>

                <!-- 5. Atenção: piores atrasos + backlog parado -->
                <section
                    data-test="work-overview-attention"
                    aria-label="Atenção"
                    class="flex flex-col gap-2"
                >
                    <h2 class="text-sm font-semibold">Atenção</h2>

                    <div
                        v-if="loading"
                        class="bg-elevated h-28 animate-pulse rounded-lg"
                        data-test="work-overview-loading-attention"
                        aria-hidden="true"
                    />

                    <UPageCard
                        v-else-if="loadError !== null"
                        title="Falha ao carregar a atenção"
                        :description="loadError"
                        icon="i-lucide-siren"
                        data-test="work-overview-error-attention"
                    >
                        <UButton
                            color="neutral"
                            variant="solid"
                            size="sm"
                            class="mt-3"
                            data-test="work-overview-retry-attention"
                            @click="retry"
                        >
                            Tentar novamente
                        </UButton>
                    </UPageCard>

                    <UPageCard
                        v-else-if="!visible.hasData"
                        :title="EMPTY_TITLE"
                        :description="EMPTY_DESCRIPTION"
                        icon="i-lucide-siren"
                        data-test="work-overview-empty-attention"
                    />

                    <div
                        v-else
                        class="border-default flex flex-col gap-3 rounded-lg border p-4"
                    >
                        <ul
                            v-if="visible.attention.overdue.length > 0"
                            class="flex flex-col gap-2"
                        >
                            <li
                                v-for="task in visible.attention.overdue"
                                :key="task.id"
                                :data-test="`work-overview-attention-${task.id}`"
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
                            >
                                <a
                                    :href="taskHref(task.id)"
                                    class="text-primary min-w-0 flex-1 font-medium hover:underline"
                                >
                                    {{ task.title }}
                                </a>
                                <UBadge color="error" variant="subtle">
                                    {{ ageText(task.age_days) }}
                                </UBadge>
                                <span class="text-muted text-xs">
                                    {{ task.process?.title ?? '—' }} ·
                                    {{
                                        task.client?.razao_social ??
                                        'Sem empresa'
                                    }}
                                </span>
                                <span class="text-muted text-xs">
                                    {{ formatDate(task.due_on) }}
                                </span>
                            </li>
                        </ul>
                        <p
                            v-else
                            class="text-muted text-sm"
                            data-test="work-overview-attention-clean"
                        >
                            Nenhum atraso nesta competência.
                        </p>
                        <p
                            class="text-muted text-sm tabular-nums"
                            data-test="work-overview-stale"
                        >
                            {{
                                visible.attention.stale_backlog === 0
                                    ? 'Nenhuma tarefa parada em backlog.'
                                    : `${visible.attention.stale_backlog} ${visible.attention.stale_backlog === 1 ? 'tarefa parada' : 'tarefas paradas'} em backlog.`
                            }}
                        </p>
                    </div>
                </section>

                <!-- 6. Equipe: tabela por membro -->
                <section
                    data-test="work-overview-team"
                    aria-label="Equipe"
                    class="flex flex-col gap-2"
                >
                    <h2 class="text-sm font-semibold">Equipe</h2>

                    <div
                        v-if="loading"
                        class="bg-elevated h-36 animate-pulse rounded-lg"
                        data-test="work-overview-loading-team"
                        aria-hidden="true"
                    />

                    <UPageCard
                        v-else-if="loadError !== null"
                        title="Falha ao carregar a equipe"
                        :description="loadError"
                        icon="i-lucide-users"
                        data-test="work-overview-error-team"
                    >
                        <UButton
                            color="neutral"
                            variant="solid"
                            size="sm"
                            class="mt-3"
                            data-test="work-overview-retry-team"
                            @click="retry"
                        >
                            Tentar novamente
                        </UButton>
                    </UPageCard>

                    <UPageCard
                        v-else-if="!visible.hasData"
                        :title="EMPTY_TITLE"
                        :description="EMPTY_DESCRIPTION"
                        icon="i-lucide-users"
                        data-test="work-overview-empty-team"
                    />

                    <UPageCard
                        v-else-if="visible.team.length === 0"
                        title="Sem carga atribuída"
                        description="Há tarefas na competência, mas nenhuma com responsável. Atribua responsáveis para acompanhar a equipe aqui."
                        icon="i-lucide-user-x"
                        data-test="work-overview-empty-team-unassigned"
                    />

                    <div
                        v-else
                        class="border-default overflow-x-auto rounded-lg border"
                    >
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-muted text-left text-xs">
                                    <th class="px-3 py-2 font-medium">
                                        Membro
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium tabular-nums"
                                    >
                                        Backlog
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium tabular-nums"
                                    >
                                        A fazer
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium tabular-nums"
                                    >
                                        Em andam.
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium tabular-nums"
                                    >
                                        Concl.
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium tabular-nums"
                                    >
                                        Total
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium tabular-nums"
                                    >
                                        Conclusão
                                    </th>
                                    <th class="px-3 py-2 text-right">
                                        <span class="sr-only">Tarefas</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in visible.team"
                                    :key="row.member.id"
                                    :data-test="`work-overview-team-${row.member.id}`"
                                    class="border-default border-t"
                                >
                                    <td class="px-3 py-2 font-medium">
                                        {{ row.member.name }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{ row.backlog }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{ row.todo }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{ row.in_progress }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{ row.done }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{ row.total }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{ row.completion_pct }}%
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <a
                                            :href="memberHref(row.member.id)"
                                            :data-test="`work-overview-team-link-${row.member.id}`"
                                            class="text-primary text-xs hover:underline"
                                        >
                                            Ver tarefas
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </template>
    </UDashboardPanel>
</template>
