<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

type CalView = 'month' | 'week' | 'day';

interface CalendarRef {
    id: number;
    name: string;
}

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
    assignee: CalendarRef | null;
}

interface CalendarPayload {
    cal: CalView;
    date: string;
    start: string;
    end: string;
    competence: string | null;
    today: string;
    tasks: CalendarTask[];
    dateless: CalendarTask[];
}

interface Anchor {
    cal: CalView;
    date: string;
}

interface DayCell {
    date: string;
    day: number;
    inMonth: boolean;
    isToday: boolean;
    tasks: CalendarTask[];
}

const props = defineProps<{
    calendar: CalendarPayload;
}>();

const CAL_VIEWS: CalView[] = ['month', 'week', 'day'];

const VIEW_LABELS: Record<CalView, string> = {
    month: 'Mês',
    week: 'Semana',
    day: 'Dia',
};

// Mesma regra Normal-priority do shell (Processos.vue): `medium` (default
// atual) e `none` (legado) leem "Normal".
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
            return 'Normal';
    }
}

// Aritmética de datas em cima de strings `Y-m-d` (UTC): nunca cai em
// armadilha de fuso ao montar a grade ou navegar entre períodos.
function parseYmd(value: string): { y: number; m: number; d: number } {
    const [y, m, d] = value.split('-').map(Number);
    return { y, m, d };
}

function toYmd(y: number, m: number, d: number): string {
    const pad = (n: number): string => String(n).padStart(2, '0');
    return `${y}-${pad(m)}-${pad(d)}`;
}

function utcOf(value: string): Date {
    const { y, m, d } = parseYmd(value);
    return new Date(Date.UTC(y, m - 1, d));
}

function ymdOf(date: Date): string {
    return toYmd(
        date.getUTCFullYear(),
        date.getUTCMonth() + 1,
        date.getUTCDate(),
    );
}

function addDays(value: string, n: number): string {
    const date = utcOf(value);
    date.setUTCDate(date.getUTCDate() + n);
    return ymdOf(date);
}

function daysInMonthOf(y: number, m: number): number {
    return new Date(Date.UTC(y, m, 0)).getUTCDate();
}

function addMonths(value: string, n: number): string {
    const { y, m, d } = parseYmd(value);
    const total = y * 12 + (m - 1) + n;
    const ny = Math.floor(total / 12);
    const nm = (total % 12) + 1;
    return toYmd(ny, nm, Math.min(d, daysInMonthOf(ny, nm)));
}

function periodKey(cal: string, date: string): string {
    return `${cal}:${date}`;
}

const monthTitleFmt = new Intl.DateTimeFormat('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const dayLongFmt = new Intl.DateTimeFormat('pt-BR', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

const dayNumFmt = new Intl.DateTimeFormat('pt-BR', {
    day: 'numeric',
    timeZone: 'UTC',
});

const dayMonthFmt = new Intl.DateTimeFormat('pt-BR', {
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
});

const WEEKDAY_HEADERS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

// Estado de âncora sticky-local (Decisão 7): granularidade + data vivem no
// componente (e no localStorage), nunca na query da URL. O backend recebe
// os dois como DATA via `router.reload({ data })`, então a URL segue limpa.
const ANCHOR_KEY = 'work.calendario.anchor.v1';

function loadAnchor(): Anchor | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(ANCHOR_KEY);

        if (raw === null) {
            return null;
        }

        const parsed = JSON.parse(raw) as Partial<Anchor>;

        if (
            parsed.cal === 'month' ||
            parsed.cal === 'week' ||
            parsed.cal === 'day'
        ) {
            if (
                typeof parsed.date === 'string' &&
                /^\d{4}-\d{2}-\d{2}$/.test(parsed.date) &&
                !Number.isNaN(utcOf(parsed.date).getTime())
            ) {
                return { cal: parsed.cal, date: parsed.date };
            }
        }
    } catch {
        // Armazenamento indisponível ou corrompido: usa as props do servidor.
    }

    return null;
}

function persistAnchor(next: Anchor): void {
    try {
        window.localStorage.setItem(ANCHOR_KEY, JSON.stringify(next));
    } catch {
        // Armazenamento indisponível: o calendário segue funcional na sessão.
    }
}

const anchor = ref<Anchor>(
    loadAnchor() ?? { cal: props.calendar.cal, date: props.calendar.date },
);

// Guarda contra respostas atrasadas: cada reload ganha um token crescente e
// só a resposta do período mais recente pode atualizar o que está visível —
// uma resposta atrasada de outro mês nunca pinta por cima do mês atual.
let requestSeq = 0;
const latestSeq = ref(0);
const latestKey = ref(periodKey(anchor.value.cal, anchor.value.date));
const visible = ref<CalendarPayload>(props.calendar);
const loading = ref(false);
const loadError = ref<string | null>(null);

watch(
    () => props.calendar,
    (incoming) => {
        if (periodKey(incoming.cal, incoming.date) !== latestKey.value) {
            return;
        }

        visible.value = incoming;
    },
);

function requestPeriod(next: Anchor): void {
    anchor.value = next;
    persistAnchor(next);
    requestSeq += 1;
    const seq = requestSeq;
    latestSeq.value = seq;
    latestKey.value = periodKey(next.cal, next.date);
    loading.value = true;
    loadError.value = null;

    // `reload` revisita a mesma URL preservando estado/rolagem; `only`
    // limita a resposta à prop `calendar` e `preserveUrl` mantém a URL limpa
    // (Decisão 7: granularidade + âncora vivem em estado sticky-local, nunca
    // na query — `data` viaja só no corpo da visita parcial).
    router.reload({
        data: { cal: next.cal, date: next.date },
        only: ['calendar'],
        preserveUrl: true,
        onError: () => {
            if (seq === latestSeq.value) {
                loadError.value =
                    'Não foi possível carregar o período. Tente novamente.';
            }
        },
        onFinish: () => {
            if (seq === latestSeq.value) {
                loading.value = false;
            }
        },
    });
}

function goView(next: CalView): void {
    if (next === anchor.value.cal) {
        return;
    }

    requestPeriod({ cal: next, date: anchor.value.date });
}

function goDay(date: string): void {
    requestPeriod({ cal: 'day', date });
}

function step(direction: -1 | 1): void {
    const current = anchor.value;

    const date =
        current.cal === 'month'
            ? addMonths(current.date, direction)
            : addDays(
                  current.date,
                  current.cal === 'week' ? 7 * direction : direction,
              );

    requestPeriod({ cal: current.cal, date });
}

function goToday(): void {
    requestPeriod({ cal: anchor.value.cal, date: props.calendar.today });
}

function retry(): void {
    requestPeriod(anchor.value);
}

onMounted(() => {
    // Âncora sticky de outra sessão: recarrega uma vez para o período
    // guardado em vez de mostrar o mês corrente e pular depois.
    const stored = loadAnchor();

    if (
        stored !== null &&
        periodKey(stored.cal, stored.date) !==
            periodKey(props.calendar.cal, props.calendar.date)
    ) {
        requestPeriod(stored);
    }
});

const tasksByDate = computed(() => {
    const grouped = new Map<string, CalendarTask[]>();

    for (const task of visible.value.tasks) {
        if (task.due_on === null) {
            continue;
        }

        const list = grouped.get(task.due_on);

        if (list === undefined) {
            grouped.set(task.due_on, [task]);
        } else {
            list.push(task);
        }
    }

    return grouped;
});

const MAX_PER_DAY = 3;

const monthCells = computed<DayCell[]>(() => {
    const { y, m } = parseYmd(visible.value.start.slice(0, 7) + '-01');
    const first = toYmd(y, m, 1);
    const leading = (utcOf(first).getUTCDay() + 6) % 7;
    const total = Math.ceil((leading + daysInMonthOf(y, m)) / 7) * 7;
    const cells: DayCell[] = [];

    for (let i = 0; i < total; i += 1) {
        const date = addDays(first, i - leading);
        const tasks = tasksByDate.value.get(date) ?? [];

        cells.push({
            date,
            day: parseYmd(date).d,
            inMonth: date >= visible.value.start && date <= visible.value.end,
            isToday: date === visible.value.today,
            tasks,
        });
    }

    return cells;
});

const weekDays = computed<DayCell[]>(() => {
    const days: DayCell[] = [];

    for (let i = 0; i < 7; i += 1) {
        const date = addDays(visible.value.start, i);

        days.push({
            date,
            day: parseYmd(date).d,
            inMonth: true,
            isToday: date === visible.value.today,
            tasks: tasksByDate.value.get(date) ?? [],
        });
    }

    return days;
});

const periodTitle = computed(() => {
    const shown = visible.value;

    if (shown.cal === 'day') {
        return dayLongFmt.format(utcOf(shown.start));
    }

    if (shown.cal === 'week') {
        const [sy, sm] = shown.start.split('-').map(Number);
        const [ey, em] = shown.end.split('-').map(Number);

        if (sy === ey && sm === em) {
            return `${dayNumFmt.format(utcOf(shown.start))}–${dayNumFmt.format(utcOf(shown.end))} de ${monthTitleFmt.format(utcOf(shown.end))}`;
        }

        return `${dayMonthFmt.format(utcOf(shown.start))} – ${dayMonthFmt.format(utcOf(shown.end))}`;
    }

    return monthTitleFmt.format(utcOf(shown.start));
});

const stepLabel = computed(() =>
    anchor.value.cal === 'month'
        ? 'mês'
        : anchor.value.cal === 'week'
          ? 'semana'
          : 'dia',
);

function dayAriaLabel(cell: DayCell): string {
    const count = cell.tasks.length;
    const when = dayMonthFmt.format(utcOf(cell.date));

    if (count === 0) {
        return `${when}, sem tarefas`;
    }

    return `${when}, ${count} ${count === 1 ? 'tarefa' : 'tarefas'}`;
}
</script>

<template>
    <div class="flex flex-col gap-4" data-test="work-calendar">
        <!-- Barra de período: navegação + troca mês/semana/dia -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1">
                <UButton
                    color="neutral"
                    variant="ghost"
                    icon="i-lucide-chevron-left"
                    :aria-label="`Ver ${stepLabel === 'mês' ? 'o mês anterior' : stepLabel === 'semana' ? 'a semana anterior' : 'o dia anterior'}`"
                    data-test="work-calendar-prev"
                    @click="step(-1)"
                />
                <UButton
                    color="neutral"
                    variant="ghost"
                    :aria-label="`Voltar para ${stepLabel === 'dia' ? 'hoje' : stepLabel === 'semana' ? 'esta semana' : 'este mês'}`"
                    data-test="work-calendar-today"
                    @click="goToday"
                >
                    Hoje
                </UButton>
                <UButton
                    color="neutral"
                    variant="ghost"
                    icon="i-lucide-chevron-right"
                    :aria-label="`Ver ${stepLabel === 'mês' ? 'o próximo mês' : stepLabel === 'semana' ? 'a próxima semana' : 'o próximo dia'}`"
                    data-test="work-calendar-next"
                    @click="step(1)"
                />
            </div>

            <h2
                class="min-w-0 flex-1 text-sm font-semibold first-letter:uppercase"
            >
                {{ periodTitle }}
            </h2>

            <div
                class="flex items-center gap-1"
                role="group"
                aria-label="Visão do calendário"
            >
                <UButton
                    v-for="option in CAL_VIEWS"
                    :key="option"
                    color="neutral"
                    :variant="anchor.cal === option ? 'solid' : 'ghost'"
                    size="sm"
                    :aria-pressed="anchor.cal === option"
                    :data-test="`work-calendar-view-${option}`"
                    @click="goView(option)"
                >
                    {{ VIEW_LABELS[option] }}
                </UButton>
            </div>
        </div>

        <p
            v-if="loading"
            class="text-muted text-sm"
            data-test="work-calendar-loading"
        >
            A carregar período…
        </p>

        <UPageCard
            v-if="loadError !== null"
            title="Falha ao carregar o calendário"
            :description="loadError"
            icon="i-lucide-calendar-x"
            data-test="work-calendar-error"
        >
            <UButton
                color="neutral"
                variant="solid"
                size="sm"
                class="mt-3"
                data-test="work-calendar-retry"
                @click="retry"
            >
                Tentar novamente
            </UButton>
        </UPageCard>

        <template v-else>
            <p
                v-if="visible.tasks.length === 0"
                class="text-muted text-sm"
                data-test="work-calendar-empty"
            >
                Nenhuma tarefa neste período.
            </p>

            <!-- Mês: grade Seg–Dom, dias vizinhos apagados sem plotar -->
            <div
                v-if="visible.cal === 'month'"
                class="flex flex-col gap-1"
                data-test="work-calendar-grid"
            >
                <div
                    class="text-muted grid grid-cols-7 gap-1 text-center text-xs font-medium"
                >
                    <span v-for="name in WEEKDAY_HEADERS" :key="name">
                        {{ name }}
                    </span>
                </div>

                <div class="grid grid-cols-7 gap-1">
                    <button
                        v-for="cell in monthCells"
                        :key="cell.date"
                        type="button"
                        class="border-default flex min-h-20 flex-col gap-1 rounded-md border p-1 text-left focus-visible:outline-2"
                        :class="{
                            'opacity-45': !cell.inMonth,
                            'border-primary': cell.isToday,
                        }"
                        :aria-label="dayAriaLabel(cell)"
                        :aria-current="cell.isToday ? 'date' : undefined"
                        :data-test="`work-calendar-day-${cell.date}`"
                        @click="goDay(cell.date)"
                    >
                        <span
                            class="text-xs font-semibold"
                            :class="{ 'text-primary': cell.isToday }"
                        >
                            {{ cell.day }}
                        </span>
                        <span
                            v-for="task in cell.tasks.slice(0, MAX_PER_DAY)"
                            :key="task.id"
                            class="truncate rounded px-1 text-xs"
                            :class="
                                task.is_overdue
                                    ? 'bg-error/15 text-error font-medium'
                                    : 'bg-elevated text-default'
                            "
                            :data-test="`work-calendar-task-${task.id}`"
                        >
                            {{ task.title }}
                        </span>
                        <span
                            v-if="cell.tasks.length > MAX_PER_DAY"
                            class="text-muted px-1 text-xs"
                            :data-test="`work-calendar-more-${cell.date}`"
                        >
                            +{{ cell.tasks.length - MAX_PER_DAY }} mais
                        </span>
                    </button>
                </div>
            </div>

            <!-- Semana: faixa de 7 dias Seg–Dom -->
            <div
                v-else-if="visible.cal === 'week'"
                class="grid grid-cols-1 gap-2 sm:grid-cols-7"
                data-test="work-calendar-week"
            >
                <section
                    v-for="(cell, index) in weekDays"
                    :key="cell.date"
                    :data-test="`work-calendar-weekday-${cell.date}`"
                    :aria-label="`${WEEKDAY_HEADERS[index]} ${dayMonthFmt.format(utcOf(cell.date))}`"
                    class="border-default flex flex-col gap-1.5 rounded-md border p-2"
                    :class="{ 'border-primary': cell.isToday }"
                >
                    <button
                        type="button"
                        class="flex items-baseline justify-between gap-1 text-left"
                        :aria-label="`Abrir dia ${dayMonthFmt.format(utcOf(cell.date))}`"
                        @click="goDay(cell.date)"
                    >
                        <span class="text-muted text-xs font-medium">
                            {{ WEEKDAY_HEADERS[index] }}
                        </span>
                        <span
                            class="text-sm font-semibold"
                            :class="{ 'text-primary': cell.isToday }"
                            :aria-current="cell.isToday ? 'date' : undefined"
                        >
                            {{ cell.day }}
                        </span>
                    </button>

                    <ul class="flex flex-col gap-1">
                        <li
                            v-for="task in cell.tasks"
                            :key="task.id"
                            :data-test="`work-calendar-task-${task.id}`"
                            class="rounded px-1.5 py-1 text-xs"
                            :class="
                                task.is_overdue
                                    ? 'bg-error/15 text-error font-medium'
                                    : 'bg-elevated text-default'
                            "
                        >
                            <span class="block truncate font-medium">
                                {{ task.title }}
                            </span>
                            <span class="text-muted block truncate">
                                {{ task.client?.razao_social ?? 'Sem empresa' }}
                                ·
                                {{ priorityLabel(task.priority) }}
                            </span>
                            <UBadge
                                v-if="task.is_overdue"
                                color="error"
                                variant="subtle"
                                size="sm"
                                class="mt-1"
                                :data-test="`work-calendar-overdue-${task.id}`"
                            >
                                Atrasada
                            </UBadge>
                        </li>
                    </ul>

                    <p
                        v-if="cell.tasks.length === 0"
                        class="text-muted text-xs"
                    >
                        Sem tarefas.
                    </p>
                </section>
            </div>

            <!-- Dia: lista do dia único -->
            <div
                v-else
                class="flex flex-col gap-2"
                data-test="work-calendar-daylist"
            >
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="task in visible.tasks"
                        :key="task.id"
                        :data-test="`work-calendar-task-${task.id}`"
                        class="border-default flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border p-2.5 text-sm"
                        :class="{ 'border-error/60': task.is_overdue }"
                    >
                        <span class="min-w-0 flex-1 font-medium">
                            {{ task.title }}
                        </span>
                        <UBadge
                            v-if="task.is_overdue"
                            color="error"
                            variant="subtle"
                            :data-test="`work-calendar-overdue-${task.id}`"
                        >
                            Atrasada
                        </UBadge>
                        <UBadge color="neutral" variant="outline">
                            {{ priorityLabel(task.priority) }}
                        </UBadge>
                        <span class="text-muted text-xs">
                            {{ task.process?.title ?? '—' }} ·
                            {{ task.client?.razao_social ?? 'Sem empresa' }}
                        </span>
                        <span class="text-muted text-xs">
                            {{ task.assignee?.name ?? 'Sem responsável' }}
                        </span>
                    </li>
                </ul>
            </div>

            <!-- Sem data: listadas, nunca plotadas -->
            <section
                class="border-default flex flex-col gap-2 rounded-lg border p-3"
                data-test="work-calendar-dateless"
                aria-label="Tarefas sem data definida"
            >
                <h3 class="text-sm font-semibold">Sem data definida</h3>

                <ul
                    v-if="visible.dateless.length > 0"
                    class="flex flex-col gap-1.5"
                >
                    <li
                        v-for="task in visible.dateless"
                        :key="task.id"
                        :data-test="`work-calendar-dateless-${task.id}`"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
                    >
                        <span class="min-w-0 flex-1">{{ task.title }}</span>
                        <UBadge color="neutral" variant="outline">
                            {{ priorityLabel(task.priority) }}
                        </UBadge>
                        <span class="text-muted text-xs">
                            {{ task.client?.razao_social ?? 'Sem empresa' }}
                        </span>
                    </li>
                </ul>

                <p v-else class="text-muted text-sm">
                    Nenhuma tarefa sem data.
                </p>
            </section>
        </template>
    </div>
</template>
