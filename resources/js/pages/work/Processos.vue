<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import WorkChildNav from '@/components/work/WorkChildNav.vue';
import { move as moveTask } from '@/actions/App/Http/Controllers/WorkTaskController';
import { processos as workProcessos } from '@/routes/work';

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

const props = withDefaults(
    defineProps<{
        view: string;
        search?: string;
        processes?: TreeProcess[];
        board?: Record<BoardStatus, BoardCard[]>;
        hasAssignments?: boolean;
    }>(),
    { search: '', processes: () => [], board: undefined, hasAssignments: true },
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

// Estado de expansão sticky-local (Decisão 7): sobrevive a reloads, nunca
// abre sheet/modal — o workspace do par chega só na Onda 3 (4.3).
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
                                    <UButton
                                        color="neutral"
                                        variant="ghost"
                                        size="sm"
                                        block
                                        class="justify-start"
                                        :aria-expanded="
                                            isExpanded(
                                                `c:${process.id}:${client.id}`,
                                            )
                                        "
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
                                            <span class="min-w-0 flex-1">
                                                {{ task.title }}
                                            </span>
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

                <!-- Visão Calendário: Onda 2 (3.3) -->
                <UPageCard
                    v-else-if="view === 'calendario'"
                    title="Calendário chega na Onda 2"
                    description="As visões de mês, semana e dia com a semântica de vencimento chegam na Task 3.3."
                    icon="i-lucide-calendar"
                    data-test="work-calendario-empty"
                />

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
</template>
