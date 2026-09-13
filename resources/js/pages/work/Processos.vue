<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import WorkCalendar from '@/components/work/WorkCalendar.vue';
import WorkChildNav from '@/components/work/WorkChildNav.vue';
import {
    move as moveTask,
    store as storeTask,
} from '@/actions/App/Http/Controllers/WorkTaskController';
import { processos as workProcessos } from '@/routes/work';
import { show as showWorkspace } from '@/routes/work/processes/clients';

type BoardStatus = 'backlog' | 'todo' | 'in_progress' | 'done';

interface WorkAssignee {
    id: number;
    name: string;
}

interface TreeTask {
    id: number;
    title: string;
    status: string;
    position: number;
    priority: string;
    due_on: string | null;
    assignee: WorkAssignee | null;
}

interface TreeClient {
    id: number;
    razao_social: string;
    tasks: TreeTask[];
}

interface TreeProcess {
    id: number;
    title: string;
    source: string;
    status: string;
    clients_count: number;
    clients: TreeClient[];
}

interface BoardCard {
    id: number;
    title: string;
    status: string;
    position: number;
    priority: string;
    due_on: string | null;
    process: { id: number; title: string } | null;
    client: { id: number; razao_social: string } | null;
    assignee: WorkAssignee | null;
}

// Espelha o payload `calendar` do WorkViewController (visões mês/semana/dia
// por `due_on` + lista `dateless`); a forma canônica vive no backend, aqui é
// estrutural para a prop do WorkCalendar.
interface CalendarTask {
    id: number;
    title: string;
    status: string;
    position: number;
    priority: string;
    due_on: string | null;
    target_date: string | null;
    is_overdue: boolean;
    process: { id: number; title: string } | null;
    client: { id: number; razao_social: string } | null;
    assignee: WorkAssignee | null;
}

interface CalendarPayload {
    cal: 'month' | 'week' | 'day';
    date: string;
    start: string;
    end: string;
    competence: string | null;
    today: string;
    tasks: CalendarTask[];
    dateless: CalendarTask[];
}

const props = withDefaults(
    defineProps<{
        view: string;
        search?: string;
        processes?: TreeProcess[];
        board?: Record<BoardStatus, BoardCard[]>;
        calendar?: CalendarPayload | null;
        hasAssignments?: boolean;
    }>(),
    {
        search: '',
        processes: () => [],
        board: undefined,
        calendar: null,
        hasAssignments: true,
    },
);

const BOARD_ORDER: BoardStatus[] = ['backlog', 'todo', 'in_progress', 'done'];

const BOARD_LABELS: Record<BoardStatus, string> = {
    backlog: 'Backlog',
    todo: 'A fazer',
    in_progress: 'Em andamento',
    done: 'Concluída',
};

const VIEW_SUBTITLES: Record<string, string> = {
    processo:
        'Processos operacionais da Account, empresas associadas e tarefas.',
    tarefas: 'Quadro de tarefas por situação.',
    calendario: 'Visão de calendário do trabalho.',
    cliente: 'Trabalho organizado por empresa.',
};

function priorityLabel(priority: string): string {
    switch (priority) {
        case 'low':
            return 'Baixa';
        case 'high':
            return 'Alta';
        case 'urgent':
            return 'Urgente';
        case 'medium':
        case 'none':
        default:
            // `medium` é o default atual e `none` é legado: ambos leem Normal.
            return 'Normal';
    }
}

function taskStatusLabel(status: string): string {
    switch (status) {
        case 'backlog':
            return 'Backlog';
        case 'todo':
            return 'A fazer';
        case 'in_progress':
            return 'Em andamento';
        case 'done':
            return 'Concluída';
        default:
            return status;
    }
}

function processStatusLabel(status: string): string {
    switch (status) {
        case 'active':
            return 'Ativo';
        case 'archived':
            return 'Arquivado';
        default:
            return status;
    }
}

function sourceLabel(source: string): string {
    switch (source) {
        case 'manual':
            return 'Manual';
        case 'marketplace':
            return 'Marketplace';
        default:
            return source;
    }
}

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

// Busca server-side via router.get (query-string), com debounce para não
// disparar uma visita por tecla.
const searchInput = ref(props.search);
let searchTimer: number | undefined;

watch(searchInput, (value) => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        const trimmed = value.trim();
        router.get(
            workProcessos.url(),
            trimmed === '' ? {} : { search: trimmed },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 350);
});

// Estado de expansão sticky-local (Decisão 7): sobrevive a reloads. O
// workspace do par (4.3) abre em modal por cima da árvore, sem trocar de rota.
const EXPANDED_KEY = 'work.processos.expanded.v1';

function loadExpanded(): Record<string, boolean> {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        const raw = window.localStorage.getItem(EXPANDED_KEY);
        return raw === null ? {} : (JSON.parse(raw) as Record<string, boolean>);
    } catch {
        return {};
    }
}

const expanded = ref<Record<string, boolean>>(loadExpanded());

function persistExpanded(): void {
    try {
        window.localStorage.setItem(
            EXPANDED_KEY,
            JSON.stringify(expanded.value),
        );
    } catch {
        // Armazenamento indisponível: a árvore segue funcional na sessão.
    }
}

function isExpanded(key: string): boolean {
    return expanded.value[key] === true;
}

function toggleExpanded(key: string): void {
    expanded.value[key] = !isExpanded(key);
    persistExpanded();
}

// Move no board via PATCH work.tasks.move (Wayfinder action): status+posição
// atualizam juntos e o redirect de volta recarrega as props do board.
const movingId = ref<number | null>(null);

function columnCards(status: BoardStatus): BoardCard[] {
    return props.board?.[status] ?? [];
}

function moveCard(card: BoardCard, target: BoardStatus): void {
    if (movingId.value !== null || card.status === target) {
        return;
    }

    movingId.value = card.id;
    router.patch(
        moveTask.url({ task: card.id }),
        { status: target, position: columnCards(target).length },
        {
            preserveScroll: true,
            onFinish: () => {
                movingId.value = null;
            },
        },
    );
}

function neighbor(card: BoardCard, direction: -1 | 1): BoardStatus | null {
    const index = BOARD_ORDER.findIndex((status) => status === card.status);

    if (index === -1) {
        return null;
    }

    return BOARD_ORDER[index + direction] ?? null;
}

// Workspace do par processo–empresa (4.3): modal local alimentado pelo
// endpoint de leitura `work.processes.clients.show`. Estado 100% local (sem
// troca de rota); Escape/fundo fecham pelo comportamento padrão do UModal,
// que também prende o foco (trap da lib).
interface WorkspaceAssignee {
    id: number;
    name: string;
}

interface WorkspaceTask {
    id: number;
    title: string;
    status: string;
    position: number;
    priority: string;
    due_on: string | null;
    target_date: string | null;
    assignee: WorkspaceAssignee | null;
}

interface WorkspacePayload {
    process: { id: number; title: string; description: string | null };
    client: { id: number; name: string; tax_id: string };
    progress: { done: number; total: number };
    summary: {
        next_due: string | null;
        highest_open_priority: string | null;
        open_count: number;
        done_count: number;
    };
    tasks: WorkspaceTask[];
    can_create_task: boolean;
    documents_available: boolean;
}

const WORKSPACE_STATUS_OPTIONS = [
    { label: 'Backlog', value: 'backlog' },
    { label: 'A fazer', value: 'todo' },
    { label: 'Em andamento', value: 'in_progress' },
    { label: 'Concluída', value: 'done' },
];

const workspaceOpen = ref(false);
const workspaceLoading = ref(false);
const workspaceError = ref<string | null>(null);
const workspace = ref<WorkspacePayload | null>(null);
const workspaceProcessId = ref<number | null>(null);
const workspaceClientId = ref<number | null>(null);
const selectedTaskId = ref<number | null>(null);
const newTaskTitle = ref('');
const creatingTask = ref(false);
const movingWorkspaceTaskId = ref<number | null>(null);

const workspacePct = computed<number>(() => {
    const progress = workspace.value?.progress;

    if (!progress || progress.total === 0) {
        return 0;
    }

    return Math.round((progress.done / progress.total) * 100);
});

// Destino da ação primária conforme o contrato 4.3. A rota de documentos por
// empresa é owned pelo agente fiscal e não existe neste worktree — o href
// segue literal e o gate (presente IFF disponível) é o comportamento sob teste.
const workspaceDocsUrl = computed<string>(() =>
    workspace.value === null
        ? ''
        : `/documents/client/${workspace.value.client.id}`,
);

async function fetchWorkspace(): Promise<void> {
    if (workspaceProcessId.value === null || workspaceClientId.value === null) {
        return;
    }

    workspaceLoading.value = true;
    workspaceError.value = null;

    try {
        const response = await fetch(
            showWorkspace.url({
                process: workspaceProcessId.value,
                client: workspaceClientId.value,
            }),
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        workspace.value = (await response.json()) as WorkspacePayload;
    } catch {
        workspace.value = null;
        workspaceError.value =
            'Não foi possível carregar o workspace. Tente novamente.';
    } finally {
        workspaceLoading.value = false;
    }
}

function openWorkspace(
    processId: number,
    clientId: number,
    taskId: number | null = null,
): void {
    workspaceProcessId.value = processId;
    workspaceClientId.value = clientId;
    selectedTaskId.value = taskId;
    newTaskTitle.value = '';
    workspaceError.value = null;
    // Mantém o contexto visível atrás do modal ao fechar.
    expanded.value[`p:${processId}`] = true;
    expanded.value[`c:${processId}:${clientId}`] = true;
    persistExpanded();
    workspaceOpen.value = true;
    void fetchWorkspace().then(() => {
        if (taskId !== null) {
            void nextTick(() => {
                document
                    .querySelector(
                        `[data-test="work-workspace-task-${taskId}"]`,
                    )
                    ?.scrollIntoView({ block: 'nearest' });
            });
        }
    });
}

function closeWorkspace(): void {
    workspaceOpen.value = false;
}

function changeWorkspaceStatus(task: WorkspaceTask, status: string): void {
    if (
        movingWorkspaceTaskId.value !== null ||
        task.status === status ||
        !['backlog', 'todo', 'in_progress', 'done'].includes(status)
    ) {
        return;
    }

    movingWorkspaceTaskId.value = task.id;
    router.patch(
        moveTask.url({ task: task.id }),
        { status, position: task.position },
        {
            preserveScroll: true,
            onFinish: () => {
                movingWorkspaceTaskId.value = null;
                void fetchWorkspace();
            },
        },
    );
}

function createWorkspaceTask(): void {
    const title = newTaskTitle.value.trim();

    if (
        creatingTask.value ||
        title === '' ||
        workspaceProcessId.value === null ||
        workspaceClientId.value === null
    ) {
        return;
    }

    creatingTask.value = true;
    router.post(
        storeTask.url(),
        {
            work_process_id: workspaceProcessId.value,
            client_id: workspaceClientId.value,
            title,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                newTaskTitle.value = '';
            },
            onFinish: () => {
                creatingTask.value = false;
                void fetchWorkspace();
            },
        },
    );
}
</script>

<template>
    <Head title="Work" />

    <UDashboardPanel id="work-processos">
        <template #header>
            <UDashboardNavbar title="Work">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <div class="flex flex-col gap-4">
                <p class="text-muted text-sm">
                    {{ VIEW_SUBTITLES[view] ?? VIEW_SUBTITLES['processo'] }}
                </p>

                <WorkChildNav :active="view" />

                <!-- Visão Processo (default): árvore processo → empresa → tarefa -->
                <div v-if="view === 'processo'" class="flex flex-col gap-3">
                    <UInput
                        v-model="searchInput"
                        class="max-w-sm"
                        icon="i-lucide-search"
                        placeholder="Buscar por processo ou empresa…"
                        data-test="work-search"
                    />

                    <ul
                        v-if="processes.length > 0"
                        class="flex flex-col gap-3"
                        data-test="work-process-list"
                    >
                        <li
                            v-for="process in processes"
                            :key="process.id"
                            :data-test="`work-process-row-${process.id}`"
                            class="border-default rounded-lg border"
                        >
                            <UButton
                                color="neutral"
                                variant="ghost"
                                block
                                class="justify-start"
                                :aria-expanded="isExpanded(`p:${process.id}`)"
                                :data-test="`work-process-toggle-${process.id}`"
                                @click="toggleExpanded(`p:${process.id}`)"
                            >
                                <UIcon
                                    name="i-lucide-chevron-down"
                                    class="transition-transform duration-200"
                                    :class="{
                                        '-rotate-90': !isExpanded(
                                            `p:${process.id}`,
                                        ),
                                    }"
                                />
                                <span
                                    class="min-w-0 flex-1 truncate text-left font-medium"
                                >
                                    {{ process.title }}
                                </span>
                                <UBadge color="neutral" variant="subtle">
                                    {{ sourceLabel(process.source) }}
                                </UBadge>
                                <UBadge
                                    :color="
                                        process.status === 'active'
                                            ? 'success'
                                            : 'neutral'
                                    "
                                    variant="subtle"
                                >
                                    {{ processStatusLabel(process.status) }}
                                </UBadge>
                                <span
                                    class="text-muted text-sm whitespace-nowrap"
                                >
                                    {{ process.clients_count }}
                                    {{
                                        process.clients_count === 1
                                            ? 'empresa'
                                            : 'empresas'
                                    }}
                                </span>
                            </UButton>

                            <ul
                                v-if="isExpanded(`p:${process.id}`)"
                                class="border-default flex flex-col gap-2 border-t p-3"
                            >
                                <li
                                    v-for="client in process.clients"
                                    :key="client.id"
                                    :data-test="`work-company-${process.id}-${client.id}`"
                                    class="bg-elevated/25 rounded-md p-2"
                                >
                                    <div class="flex items-center gap-1">
                                        <UButton
                                            color="neutral"
                                            variant="ghost"
                                            size="sm"
                                            square
                                            :aria-expanded="
                                                isExpanded(
                                                    `c:${process.id}:${client.id}`,
                                                )
                                            "
                                            :aria-label="`Expandir ${client.razao_social}`"
                                            :data-test="`work-company-toggle-${process.id}-${client.id}`"
                                            @click="
                                                toggleExpanded(
                                                    `c:${process.id}:${client.id}`,
                                                )
                                            "
                                        >
                                            <UIcon
                                                name="i-lucide-chevron-down"
                                                class="transition-transform duration-200"
                                                :class="{
                                                    '-rotate-90': !isExpanded(
                                                        `c:${process.id}:${client.id}`,
                                                    ),
                                                }"
                                            />
                                        </UButton>
                                        <UButton
                                            color="neutral"
                                            variant="ghost"
                                            size="sm"
                                            block
                                            class="min-w-0 flex-1 justify-start"
                                            :aria-label="`Abrir workspace de ${client.razao_social}`"
                                            :data-test="`work-workspace-open-${process.id}-${client.id}`"
                                            @click="
                                                openWorkspace(
                                                    process.id,
                                                    client.id,
                                                )
                                            "
                                        >
                                            <span
                                                class="min-w-0 flex-1 truncate text-left"
                                            >
                                                {{ client.razao_social }}
                                            </span>
                                            <span
                                                class="text-muted text-xs whitespace-nowrap"
                                            >
                                                {{ client.tasks.length }}
                                                {{
                                                    client.tasks.length === 1
                                                        ? 'tarefa'
                                                        : 'tarefas'
                                                }}
                                            </span>
                                        </UButton>
                                    </div>

                                    <ul
                                        v-if="
                                            isExpanded(
                                                `c:${process.id}:${client.id}`,
                                            )
                                        "
                                        class="mt-2 flex flex-col gap-1.5 pl-2"
                                    >
                                        <li
                                            v-for="task in client.tasks"
                                            :key="task.id"
                                            :data-test="`work-task-${task.id}`"
                                            class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
                                        >
                                            <button
                                                type="button"
                                                class="min-w-0 flex-1 cursor-pointer truncate text-left hover:underline"
                                                :aria-label="`Abrir workspace na tarefa ${task.title}`"
                                                :data-test="`work-workspace-task-open-${task.id}`"
                                                @click="
                                                    openWorkspace(
                                                        process.id,
                                                        client.id,
                                                        task.id,
                                                    )
                                                "
                                            >
                                                {{ task.title }}
                                            </button>
                                            <UBadge
                                                color="neutral"
                                                variant="subtle"
                                            >
                                                {{
                                                    taskStatusLabel(task.status)
                                                }}
                                            </UBadge>
                                            <UBadge
                                                color="neutral"
                                                variant="outline"
                                            >
                                                {{
                                                    priorityLabel(task.priority)
                                                }}
                                            </UBadge>
                                            <span class="text-muted text-xs">
                                                {{ formatDate(task.due_on) }}
                                            </span>
                                            <span class="text-muted text-xs">
                                                {{
                                                    task.assignee?.name ??
                                                    'Sem responsável'
                                                }}
                                            </span>
                                        </li>
                                        <li
                                            v-if="client.tasks.length === 0"
                                            class="text-muted text-sm"
                                        >
                                            Nenhuma tarefa para este par
                                            processo–empresa.
                                        </li>
                                    </ul>
                                </li>
                                <li
                                    v-if="process.clients.length === 0"
                                    class="text-muted text-sm"
                                >
                                    Nenhuma empresa associada a este processo.
                                </li>
                            </ul>
                        </li>
                    </ul>

                    <UPageCard
                        v-else-if="search.trim() !== ''"
                        title="Nenhum resultado"
                        :description="`Nada encontrado para “${search.trim()}” em processos ou empresas.`"
                        icon="i-lucide-search-x"
                        data-test="work-process-empty-search"
                    />
                    <UPageCard
                        v-else-if="!hasAssignments"
                        title="Nenhum Client atribuído"
                        description="Você ainda não tem Clients atribuídos. Peça a um admin ou operador para atribuir Clients e o trabalho aparece aqui."
                        icon="i-lucide-user-x"
                        data-test="work-process-empty-assignments"
                    />
                    <UPageCard
                        v-else
                        title="Nenhum processo operacional"
                        description="A Account ainda não tem processos operacionais com empresas associadas."
                        icon="i-lucide-briefcase"
                        data-test="work-process-empty"
                    />
                </div>

                <!-- Visão Tarefas: board backlog|todo|in_progress|done -->
                <div v-else-if="view === 'tarefas'" class="flex flex-col gap-3">
                    <div
                        data-test="work-board"
                        class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4"
                    >
                        <section
                            v-for="status in BOARD_ORDER"
                            :key="status"
                            :data-test="`work-board-column-${status}`"
                            :aria-label="BOARD_LABELS[status]"
                            class="border-default flex flex-col gap-2 rounded-lg border p-3"
                        >
                            <h2
                                class="flex items-center justify-between text-sm font-semibold"
                            >
                                {{ BOARD_LABELS[status] }}
                                <span class="text-muted font-normal">
                                    {{ columnCards(status).length }}
                                </span>
                            </h2>

                            <ul class="flex flex-col gap-2">
                                <li
                                    v-for="card in columnCards(status)"
                                    :key="card.id"
                                    :data-test="`work-card-${card.id}`"
                                    class="border-default rounded-md border p-2.5"
                                >
                                    <p class="text-sm font-medium">
                                        {{ card.title }}
                                    </p>
                                    <p class="text-muted mt-1 text-xs">
                                        {{ card.process?.title ?? '—' }} ·
                                        {{
                                            card.client?.razao_social ??
                                            'Sem empresa'
                                        }}
                                    </p>
                                    <div
                                        class="mt-2 flex flex-wrap items-center gap-1.5"
                                    >
                                        <UBadge
                                            color="neutral"
                                            variant="outline"
                                            :data-test="`work-card-priority-${card.id}`"
                                        >
                                            {{ priorityLabel(card.priority) }}
                                        </UBadge>
                                        <span class="text-muted text-xs">
                                            {{ formatDate(card.due_on) }}
                                        </span>
                                        <span class="text-muted text-xs">
                                            {{
                                                card.assignee?.name ??
                                                'Sem responsável'
                                            }}
                                        </span>
                                    </div>
                                    <div class="mt-2 flex gap-1.5">
                                        <UButton
                                            v-if="neighbor(card, -1) !== null"
                                            color="neutral"
                                            variant="ghost"
                                            size="xs"
                                            icon="i-lucide-arrow-left"
                                            :loading="movingId === card.id"
                                            :aria-label="`Mover para ${BOARD_LABELS[neighbor(card, -1)!]}`"
                                            :data-test="`work-move-${card.id}-${neighbor(card, -1)}`"
                                            @click="
                                                moveCard(
                                                    card,
                                                    neighbor(card, -1)!,
                                                )
                                            "
                                        />
                                        <UButton
                                            v-if="neighbor(card, 1) !== null"
                                            color="neutral"
                                            variant="ghost"
                                            size="xs"
                                            icon="i-lucide-arrow-right"
                                            :loading="movingId === card.id"
                                            :aria-label="`Mover para ${BOARD_LABELS[neighbor(card, 1)!]}`"
                                            :data-test="`work-move-${card.id}-${neighbor(card, 1)}`"
                                            @click="
                                                moveCard(
                                                    card,
                                                    neighbor(card, 1)!,
                                                )
                                            "
                                        />
                                    </div>
                                </li>
                            </ul>

                            <p
                                v-if="columnCards(status).length === 0"
                                class="text-muted text-sm"
                            >
                                Nenhuma tarefa aqui.
                            </p>
                        </section>
                    </div>

                    <UPageCard
                        v-if="
                            BOARD_ORDER.every(
                                (status) => columnCards(status).length === 0,
                            ) && !hasAssignments
                        "
                        title="Nenhum Client atribuído"
                        description="Você ainda não tem Clients atribuídos. Peça a um admin ou operador para atribuir Clients e as tarefas aparecem aqui."
                        icon="i-lucide-user-x"
                        data-test="work-board-empty-assignments"
                    />
                    <UPageCard
                        v-else-if="
                            BOARD_ORDER.every(
                                (status) => columnCards(status).length === 0,
                            )
                        "
                        title="Nenhuma tarefa por aqui"
                        description="Quando houver tarefas nos seus processos e empresas, elas aparecem neste quadro."
                        icon="i-lucide-kanban"
                        data-test="work-board-empty"
                    />
                </div>

                <!-- Visão Calendário: mês/semana/dia por vencimento (3.3) -->
                <template v-else-if="view === 'calendario'">
                    <WorkCalendar v-if="calendar" :calendar="calendar" />
                    <UPageCard
                        v-else
                        title="Calendário indisponível"
                        description="Não foi possível carregar o calendário. Recarregue a página."
                        icon="i-lucide-calendar"
                        data-test="work-calendario-empty"
                    />
                </template>

                <!-- Visão Clientes: Onda 3 com o workspace (4.3) -->
                <UPageCard
                    v-else-if="view === 'cliente'"
                    title="Visão por empresa chega com o workspace na Onda 3"
                    description="A visão por empresa abre junto do workspace do par processo–empresa na Task 4.3."
                    icon="i-lucide-building-2"
                    data-test="work-cliente-empty"
                />
            </div>
        </template>
    </UDashboardPanel>

    <!-- Workspace do par processo–empresa (4.3): estado local, sem rota. -->
    <UModal
        v-model:open="workspaceOpen"
        :title="workspace?.process.title ?? 'Workspace do par'"
        description="Progresso, resumo e tarefas do par processo–empresa."
        :ui="{ content: 'sm:max-w-5xl' }"
    >
        <template #body>
            <!-- UModal não repassa data-test ao diálogo: o id de teste vive
                neste wrapper interno. -->
            <div data-test="work-workspace-modal">
                <div
                    v-if="workspaceLoading && workspace === null"
                    class="flex flex-col gap-3"
                    data-test="work-workspace-loading"
                >
                    <USkeleton class="h-6 w-2/3" />
                    <USkeleton class="h-3 w-full" />
                    <div class="grid gap-4 md:grid-cols-2">
                        <USkeleton class="h-40 w-full" />
                        <USkeleton class="h-40 w-full" />
                    </div>
                </div>

                <div
                    v-else-if="workspaceError !== null"
                    class="flex flex-col gap-3"
                    data-test="work-workspace-error"
                >
                    <UAlert
                        color="error"
                        variant="soft"
                        title="Não foi possível carregar o workspace"
                        :description="workspaceError"
                    />
                    <UButton
                        color="neutral"
                        variant="outline"
                        icon="i-lucide-rotate-cw"
                        label="Tentar novamente"
                        class="self-start"
                        data-test="work-workspace-retry"
                        @click="fetchWorkspace()"
                    />
                </div>

                <div
                    v-else-if="workspace !== null"
                    class="grid gap-6 md:grid-cols-2"
                >
                    <section aria-label="Processo operacional">
                        <p
                            class="text-muted text-xs font-medium tracking-wide uppercase"
                        >
                            Processo Operacional
                        </p>
                        <h3
                            class="mt-1 text-base font-semibold"
                            data-test="work-workspace-title"
                        >
                            {{ workspace.process.title }}
                        </h3>

                        <div class="mt-3">
                            <div
                                class="flex items-baseline justify-between gap-2 text-sm"
                            >
                                <span class="text-muted">Progresso</span>
                                <span data-test="work-workspace-progress">
                                    {{ workspace.progress.done }} de
                                    {{ workspace.progress.total }}
                                    {{
                                        workspace.progress.total === 1
                                            ? 'concluída'
                                            : 'concluídas'
                                    }}
                                </span>
                            </div>
                            <UProgress
                                :model-value="workspacePct"
                                size="sm"
                                class="mt-1"
                                data-test="work-workspace-progressbar"
                            />
                        </div>

                        <div
                            class="border-default mt-4 rounded-md border p-3"
                            data-test="work-workspace-client"
                        >
                            <p
                                class="text-muted text-xs font-medium tracking-wide uppercase"
                            >
                                Cliente Monitorado
                            </p>
                            <p class="mt-1 font-medium">
                                {{ workspace.client.name }}
                            </p>
                            <p class="text-muted text-sm tabular-nums">
                                {{ workspace.client.tax_id }}
                            </p>
                        </div>

                        <p
                            v-if="workspace.process.description"
                            class="mt-3 text-sm"
                            data-test="work-workspace-description"
                        >
                            {{ workspace.process.description }}
                        </p>

                        <div class="mt-4" data-test="work-workspace-summary">
                            <p
                                class="text-muted text-xs font-medium tracking-wide uppercase"
                            >
                                Resumo operacional
                            </p>
                            <dl class="mt-2 flex flex-col gap-1.5 text-sm">
                                <div
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <dt class="text-muted">
                                        Próximo vencimento
                                    </dt>
                                    <dd>
                                        {{
                                            workspace.summary.next_due === null
                                                ? 'Sem vencimentos em aberto'
                                                : formatDate(
                                                      workspace.summary
                                                          .next_due,
                                                  )
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <dt class="text-muted">
                                        Prioridade mais alta em aberto
                                    </dt>
                                    <dd>
                                        {{
                                            workspace.summary
                                                .highest_open_priority === null
                                                ? '—'
                                                : priorityLabel(
                                                      workspace.summary
                                                          .highest_open_priority,
                                                  )
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <dt class="text-muted">Em aberto</dt>
                                    <dd>{{ workspace.summary.open_count }}</dd>
                                </div>
                                <div
                                    class="flex items-baseline justify-between gap-2"
                                >
                                    <dt class="text-muted">Concluídas</dt>
                                    <dd>{{ workspace.summary.done_count }}</dd>
                                </div>
                            </dl>
                        </div>
                    </section>

                    <section aria-label="Tarefas operacionais">
                        <p
                            class="text-muted text-xs font-medium tracking-wide uppercase"
                        >
                            Tarefas Operacionais
                        </p>

                        <ul
                            v-if="workspace.tasks.length > 0"
                            class="mt-2 flex flex-col gap-2"
                        >
                            <li
                                v-for="task in workspace.tasks"
                                :key="task.id"
                                :data-test="`work-workspace-task-${task.id}`"
                                class="border-default rounded-md border p-2.5"
                                :class="{
                                    'border-primary ring-primary/30 ring-1':
                                        selectedTaskId === task.id,
                                }"
                            >
                                <p class="text-sm font-medium">
                                    {{ task.title }}
                                </p>
                                <p class="text-muted mt-0.5 text-xs">
                                    {{ formatDate(task.due_on) }} ·
                                    {{
                                        task.assignee?.name ?? 'Sem responsável'
                                    }}
                                </p>
                                <div
                                    class="mt-2 flex flex-wrap items-center gap-1.5"
                                >
                                    <USelect
                                        :model-value="task.status"
                                        :items="WORKSPACE_STATUS_OPTIONS"
                                        size="xs"
                                        :disabled="
                                            movingWorkspaceTaskId === task.id
                                        "
                                        :aria-label="`Situação de ${task.title}`"
                                        :data-test="`work-workspace-status-${task.id}`"
                                        @update:model-value="
                                            changeWorkspaceStatus(
                                                task,
                                                String($event),
                                            )
                                        "
                                    />
                                    <UBadge color="neutral" variant="outline">
                                        {{ priorityLabel(task.priority) }}
                                    </UBadge>
                                    <UBadge color="neutral" variant="subtle">
                                        {{ taskStatusLabel(task.status) }}
                                    </UBadge>
                                </div>
                            </li>
                        </ul>
                        <p
                            v-else
                            class="text-muted mt-2 text-sm"
                            data-test="work-workspace-empty"
                        >
                            Nenhuma tarefa operacional para este par.
                        </p>

                        <div
                            v-if="workspace.can_create_task"
                            class="mt-3 flex gap-2"
                        >
                            <UInput
                                v-model="newTaskTitle"
                                class="min-w-0 flex-1"
                                placeholder="Nova tarefa operacional…"
                                :aria-label="'Título da nova tarefa operacional'"
                                data-test="work-workspace-new"
                                @keyup.enter="createWorkspaceTask()"
                            />
                            <UButton
                                icon="i-lucide-plus"
                                label="Adicionar"
                                :loading="creatingTask"
                                :disabled="newTaskTitle.trim() === ''"
                                data-test="work-workspace-create"
                                @click="createWorkspaceTask()"
                            />
                        </div>
                    </section>
                </div>
            </div>
        </template>

        <template #footer>
            <div class="flex w-full items-center justify-between gap-2">
                <UButton
                    color="neutral"
                    variant="ghost"
                    label="Fechar"
                    data-test="work-workspace-close"
                    @click="closeWorkspace()"
                />
                <UButton
                    v-if="workspace?.documents_available"
                    :to="workspaceDocsUrl"
                    icon="i-lucide-file-text"
                    label="Ver documentos"
                    data-test="work-workspace-docs"
                />
            </div>
        </template>
    </UModal>
</template>
