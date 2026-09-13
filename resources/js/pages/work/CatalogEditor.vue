<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import WorkChildNav from '@/components/work/WorkChildNav.vue';
import { jsonHeaders } from '@/lib/xsrf';
import { index as catalogIndex } from '@/routes/work/catalog';
import {
    associationPreview,
    update as updateProcess,
    updateClients,
} from '@/actions/App/Http/Controllers/WorkProcessController';

interface EditorDefinition {
    id: number | null;
    title: string;
    position: number;
    description: string | null;
    due_day: number | null;
    competence_offset: string | null;
    priority: string | null;
    default_assigned_user_id: number | null;
    department_id: number | null;
    requires_document: boolean;
}

interface EditorProcess {
    id: number;
    title: string;
    description: string | null;
    status: string;
    source: string;
    association_regimes: string[];
    association_tag_ids: number[];
    extra_client_ids: number[];
    excluded_client_ids: number[];
    due_mode: string | null;
    due_day: number | null;
    estimated_duration_days: number | null;
    competence_offset: string | null;
    target_lead_days: number | null;
    recurrence_interval: number | null;
    recurrence_unit: string;
    cascade_execution: boolean;
    clients_count: number;
    definitions: EditorDefinition[];
}

interface EditorClient {
    id: number;
    razao_social: string;
    regime: string;
}

interface EditorTag {
    id: number;
    name: string;
}

interface PreviewPayload {
    client_ids: number[];
    count: number;
    by_source: {
        rule: number[];
        extras: number[];
    };
}

const props = defineProps<{
    process: EditorProcess;
    clients: EditorClient[];
    tags: EditorTag[];
    regimeOptions: string[];
    preview: PreviewPayload;
}>();

type EditorTab =
    | 'associacao'
    | 'clientes'
    | 'prazo'
    | 'tarefas'
    | 'recorrencia';

const EDITOR_TAB_KEY = 'work.catalog.editor.tab.v1';

// A instalação do marketplace (4.2) redireciona para o editor com `?tab=`:
// `associacao` é o valor canônico, `association` (EN) é o alias que o
// redirect de install envia. O query tem precedência e é persistido como
// as trocas manuais; valores desconhecidos caem no comportamento existente.
function resolveInitialTab(): EditorTab {
    try {
        const query = new URLSearchParams(window.location.search).get('tab');

        if (query === 'associacao' || query === 'association') {
            window.localStorage.setItem(EDITOR_TAB_KEY, 'associacao');

            return 'associacao';
        }

        const raw = window.localStorage.getItem(EDITOR_TAB_KEY);
        return raw === 'clientes' ||
            raw === 'prazo' ||
            raw === 'tarefas' ||
            raw === 'recorrencia'
            ? (raw as EditorTab)
            : 'associacao';
    } catch {
        return 'associacao';
    }
}

const activeTab = ref<EditorTab>(resolveInitialTab());

function selectTab(tab: EditorTab): void {
    activeTab.value = tab;

    try {
        window.localStorage.setItem(EDITOR_TAB_KEY, tab);
    } catch {
        // Armazenamento indisponível: as abas seguem funcionais na sessão.
    }
}

// ---- Rascunho (draft) inicializado das props -----------------------------

const regimes = ref<string[]>([...props.process.association_regimes]);
const tagIds = ref<number[]>([...props.process.association_tag_ids]);
const extraIds = ref<number[]>([...props.process.extra_client_ids]);
const excludedIds = ref<number[]>([...props.process.excluded_client_ids]);

const useTagFilter = ref(tagIds.value.length > 0);

const dueMode = ref<string | null>(props.process.due_mode);
const dueDay = ref<number | null>(props.process.due_day);
const estimatedDays = ref<number | null>(props.process.estimated_duration_days);
const competenceOffset = ref<string>(
    props.process.competence_offset ?? 'due_month',
);
const targetLeadDays = ref<number | null>(props.process.target_lead_days);

interface DraftTask {
    key: string;
    id: number | null;
    title: string;
}

const draftTasks = ref<DraftTask[]>(
    [...props.process.definitions]
        .sort((a, b) => a.position - b.position)
        .map((definition) => ({
            key: `id-${definition.id}`,
            id: definition.id,
            title: definition.title,
        })),
);
const newTaskTitle = ref('');

type RecurrencePreset =
    | 'off'
    | 'diaria'
    | 'mensal'
    | 'trimestral'
    | 'semestral'
    | 'anual'
    | 'custom';

function detectPreset(interval: number | null, unit: string): RecurrencePreset {
    if (unit === 'none') {
        return 'off';
    }

    if (interval === 1 && unit === 'day') {
        return 'diaria';
    }

    if (interval === 1 && unit === 'month') {
        return 'mensal';
    }

    if (interval === 3 && unit === 'month') {
        return 'trimestral';
    }

    if (interval === 6 && unit === 'month') {
        return 'semestral';
    }

    if (interval === 1 && unit === 'year') {
        return 'anual';
    }

    return 'custom';
}

const recurrencePreset = ref<RecurrencePreset>(
    detectPreset(
        props.process.recurrence_interval,
        props.process.recurrence_unit,
    ),
);
const customInterval = ref<number>(props.process.recurrence_interval ?? 1);
const customUnit = ref<string>(
    ['day', 'week', 'month', 'year'].includes(props.process.recurrence_unit)
        ? props.process.recurrence_unit
        : 'month',
);

function recurrencePayload(): { interval: number | null; unit: string } {
    switch (recurrencePreset.value) {
        case 'off':
            return { interval: null, unit: 'none' };
        case 'diaria':
            return { interval: 1, unit: 'day' };
        case 'mensal':
            return { interval: 1, unit: 'month' };
        case 'trimestral':
            return { interval: 3, unit: 'month' };
        case 'semestral':
            return { interval: 6, unit: 'month' };
        case 'anual':
            return { interval: 1, unit: 'year' };
        case 'custom':
            return {
                interval: customInterval.value,
                unit: customUnit.value,
            };
    }
}

// ---- Preview (leitura seca via endpoint dedicado) --------------------------

const preview = ref<PreviewPayload>(props.preview);
const previewLoading = ref(false);
let previewTimer: number | undefined;

const previewUrl = associationPreview.url({ process: props.process.id });

async function refreshPreview(): Promise<void> {
    previewLoading.value = true;

    try {
        // Overrides always sent (even empty): presence distinguishes an
        // empty draft from "no override", per the endpoint contract.
        const params = new URLSearchParams({
            regimes: regimes.value.join(','),
            tag_ids: (useTagFilter.value ? tagIds.value : []).join(','),
            extra_ids: extraIds.value.join(','),
            excluded_ids: excludedIds.value.join(','),
        });

        const response = await fetch(`${previewUrl}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            preview.value = (await response.json()) as PreviewPayload;
        }
    } catch {
        // Mantém o último preview: a contagem exibida segue válida.
    } finally {
        previewLoading.value = false;
    }
}

// O preview reflete o rascunho, não o salvo: qualquer mudança de regra
// re-sonda o endpoint com debounce.
watch([regimes, tagIds, extraIds, excludedIds], () => {
    window.clearTimeout(previewTimer);
    previewTimer = window.setTimeout(() => {
        void refreshPreview();
    }, 350);
});

watch(useTagFilter, (enabled) => {
    if (!enabled) {
        tagIds.value = [];
    }
});

const clientsById = computed<Record<number, EditorClient>>(() => {
    const map: Record<number, EditorClient> = {};

    for (const client of props.clients) {
        map[client.id] = client;
    }

    return map;
});

const computedClients = computed<EditorClient[]>(() =>
    preview.value.client_ids
        .map((id) => clientsById.value[id])
        .filter((client): client is EditorClient => client !== undefined),
);

const ruleClients = computed<EditorClient[]>(() =>
    preview.value.by_source.rule
        .map((id) => clientsById.value[id])
        .filter((client): client is EditorClient => client !== undefined),
);

// ---- Toggles ----------------------------------------------------------------

const REGIME_LABELS: Record<string, string> = {
    simples: 'Simples Nacional',
    presumido: 'Lucro Presumido',
    real: 'Lucro Real',
    mei: 'MEI',
};

function regimeLabel(regime: string): string {
    return REGIME_LABELS[regime] ?? regime;
}

function toggleRegime(regime: string): void {
    if (regime === 'all') {
        regimes.value = regimes.value.includes('all') ? [] : ['all'];
        return;
    }

    const next = regimes.value.filter((item) => item !== 'all');

    regimes.value = next.includes(regime)
        ? next.filter((item) => item !== regime)
        : [...next, regime];
}

function toggleId(list: number[], id: number): number[] {
    return list.includes(id)
        ? list.filter((item) => item !== id)
        : [...list, id];
}

// ---- Tarefas (checklist ordenado, salvo via 2.1 update no aplicar) -----------

function addTask(): void {
    const title = newTaskTitle.value.trim();

    if (title === '') {
        return;
    }

    draftTasks.value.push({
        key: `new-${Date.now()}-${draftTasks.value.length}`,
        id: null,
        title,
    });
    newTaskTitle.value = '';
}

function moveTask(index: number, direction: -1 | 1): void {
    const target = index + direction;

    if (target < 0 || target >= draftTasks.value.length) {
        return;
    }

    const copy = [...draftTasks.value];
    const [moved] = copy.splice(index, 1);
    copy.splice(target, 0, moved);
    draftTasks.value = copy;
}

function removeTask(index: number): void {
    draftTasks.value = draftTasks.value.filter((_, i) => i !== index);
}

// ---- Re-sync dos ids do checklist após a perna 1 ----------------------------
//
// Tarefas novas têm `id: null` no rascunho. Se a perna 1 persistir mas a
// perna 2 falhar, uma retentativa reenviaria os mesmos `id: null` — e o
// `syncDefinitions` do 2.1 apagaria as linhas recém-criadas para recriá-las
// com outros ids. Qualquer tarefa já materializada contra os ids antigos
// perderia o vínculo e a rematerialização duplicaria tarefas. Por isso,
// após a perna 1, os ids são relidos do servidor ANTES da perna 2: a partir
// daí a retentativa carrega ids reais e o update vira idempotente. Se a
// releitura falhar, a retentativa é bloqueada (`needsRefresh`) até um reload
// completo trazer props frescas.

const needsRefresh = ref(false);

function rebaseTaskIds(): void {
    const fresh = [...props.process.definitions].sort(
        (a, b) => a.position - b.position,
    );

    const diverged =
        fresh.length !== draftTasks.value.length ||
        fresh.some((definition) => definition.id === null);

    if (diverged) {
        throw new Error(
            'O checklist salvo divergiu do rascunho. Recarregue a página antes de tentar de novo.',
        );
    }

    draftTasks.value = fresh.map((definition, index) => ({
        key: `id-${definition.id}`,
        id: definition.id,
        title: draftTasks.value[index]?.title ?? definition.title,
    }));
}

function resyncDefinitionIds(): Promise<void> {
    return new Promise((resolve, reject) => {
        router.reload({
            only: ['process'],
            onSuccess: () => {
                try {
                    rebaseTaskIds();
                    resolve();
                } catch (error) {
                    reject(error);
                }
            },
            onError: () =>
                reject(
                    new Error(
                        'O checklist foi salvo, mas não foi possível confirmar os itens. Recarregue a página antes de tentar de novo.',
                    ),
                ),
            onNetworkError: () =>
                reject(
                    new Error(
                        'O checklist pode ter sido salvo, mas a confirmação falhou (rede). Recarregue a página antes de tentar de novo.',
                    ),
                ),
        });
    });
}

// ---- Aplicar associação (2.1 update + 2.2 endpoint, um fluxo) -----------------

const applying = ref(false);
const applyError = ref<string | null>(null);
const applyResult = ref<number | null>(null);

function toNullableInt(value: unknown): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(parsed) && parsed >= 0 ? Math.trunc(parsed) : null;
}

async function putJson(
    url: string,
    body: Record<string, unknown>,
): Promise<void> {
    // `redirect: manual`: o `fetch` reemite PUT ao seguir um 302 (só
    // POST/303 viram GET), então seguir o redirect do 2.1/2.2 daria PUT na
    // rota de índice (405) mesmo com a escrita feita. Em modo manual o
    // redirect chega como resposta opaca (status 0): tratamos como sucesso
    // provável e o fluxo confirma a sessão logo adiante relendo o preview
    // autenticado — sem sessão, a leitura falha e o erro aparece.
    const response = await fetch(url, {
        method: 'PUT',
        headers: jsonHeaders(),
        body: JSON.stringify(body),
        redirect: 'manual',
    });

    if (response.status === 422) {
        const payload = (await response.json()) as { message?: string };

        throw new Error(
            payload.message ?? 'Verifique os campos e tente de novo.',
        );
    }

    if (
        response.status === 0 ||
        (response.status >= 200 && response.status < 400)
    ) {
        return;
    }

    throw new Error(`Falha ao salvar (HTTP ${response.status}).`);
}

async function applyAssociation(): Promise<void> {
    if (applying.value || needsRefresh.value) {
        return;
    }

    applying.value = true;
    applyError.value = null;
    applyResult.value = null;

    let rulesPersisted = false;
    let idsResynced = false;

    try {
        const recurrence = recurrencePayload();
        const byId: Record<number, EditorDefinition> = {};

        for (const definition of props.process.definitions) {
            if (definition.id !== null) {
                byId[definition.id] = definition;
            }
        }

        // Primeira perna: persiste regras + prazo + recorrência + checklist
        // através do 2.1 update.
        await putJson(updateProcess.url({ process: props.process.id }), {
            association_regimes: regimes.value,
            association_tag_ids: useTagFilter.value ? tagIds.value : [],
            extra_client_ids: extraIds.value,
            excluded_client_ids: excludedIds.value,
            due_mode: dueMode.value,
            due_day: dueMode.value === 'fixed_day' ? dueDay.value : null,
            estimated_duration_days:
                dueMode.value === 'estimated' ? estimatedDays.value : null,
            competence_offset: competenceOffset.value,
            target_lead_days: targetLeadDays.value,
            recurrence_interval: recurrence.interval,
            recurrence_unit: recurrence.unit,
            definitions: draftTasks.value.map((task, index) => {
                const kept = task.id !== null ? byId[task.id] : undefined;

                // Tarefas novas vão só com título+posição: nulos explícitos
                // violariam defaults NOT NULL do banco (`priority`,
                // `requires_document`) — os defaults assumem. Nas mantidas,
                // os valores lidos do servidor circulam intactos.
                return {
                    ...(task.id !== null
                        ? {
                              id: task.id,
                              description: kept?.description ?? null,
                              due_day: kept?.due_day ?? null,
                              competence_offset:
                                  kept?.competence_offset ?? null,
                              priority: kept?.priority ?? null,
                              default_assigned_user_id:
                                  kept?.default_assigned_user_id ?? null,
                              department_id: kept?.department_id ?? null,
                              requires_document:
                                  kept?.requires_document ?? false,
                          }
                        : {}),
                    title: task.title,
                    position: index,
                };
            }),
        });

        // Perna 1b: as regras acabaram de persistir, então os ids das
        // tarefas novas já existem no servidor — relê antes da perna 2 para
        // que uma retentativa carregue ids reais (ver comentário acima).
        rulesPersisted = true;
        await resyncDefinitionIds();
        idsResynced = true;

        // Segunda perna: o conjunto efetivo (lido do preview) através do 2.2.
        // Segue redirects (GET segue como GET): sem sessão cai no HTML do
        // login e a leitura abaixo falha com erro honesto.
        const previewResponse = await fetch(previewUrl, {
            headers: { Accept: 'application/json' },
        });

        const contentType = previewResponse.headers.get('content-type') ?? '';

        if (!previewResponse.ok || !contentType.includes('application/json')) {
            throw new Error(
                'Regras salvas, mas não foi possível ler o conjunto efetivo.',
            );
        }

        const fresh = (await previewResponse.json()) as PreviewPayload;

        await putJson(updateClients.url({ process: props.process.id }), {
            client_ids: fresh.client_ids,
        });

        preview.value = fresh;
        applyResult.value = fresh.count;

        router.reload();
    } catch (error) {
        // Retentativa insegura só quando a perna 1 persistiu mas os ids não
        // foram confirmados: o rascunho ainda tem `id: null` e reenviá-lo
        // recriaria linhas. Trava até um reload completo. Nos demais casos
        // (perna 1 falhou = nada criado; re-sync ok = ids reais) a
        // retentativa é idempotente e segue liberada.
        if (rulesPersisted && !idsResynced) {
            needsRefresh.value = true;
        }

        applyError.value =
            error instanceof Error
                ? error.message
                : 'Não foi possível aplicar a associação.';
    } finally {
        applying.value = false;
    }
}
</script>

<template>
    <Head :title="`Editor — ${process.title}`" />

    <UDashboardPanel id="work-catalog-editor">
        <template #header>
            <UDashboardNavbar :title="process.title">
                <template #leading>
                    <UDashboardSidebarCollapse />
                </template>
                <template #trailing>
                    <UButton
                        color="neutral"
                        variant="ghost"
                        :to="catalogIndex.url()"
                    >
                        Voltar ao catálogo
                    </UButton>
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <div class="flex flex-col gap-4">
                <p class="text-muted text-sm">
                    {{ process.clients_count }}
                    {{
                        process.clients_count === 1
                            ? 'empresa associada'
                            : 'empresas associadas'
                    }}
                    · {{ process.definitions.length }}
                    {{
                        process.definitions.length === 1 ? 'tarefa' : 'tarefas'
                    }}
                    no checklist.
                </p>

                <WorkChildNav :active="'catalogo'" />

                <UTabs
                    :model-value="activeTab"
                    :items="[
                        { label: 'Associação', value: 'associacao' },
                        { label: 'Clientes e Exceções', value: 'clientes' },
                        { label: 'Prazo', value: 'prazo' },
                        { label: 'Tarefas', value: 'tarefas' },
                        { label: 'Recorrência', value: 'recorrencia' },
                    ]"
                    data-test="work-editor-tabs"
                    @update:model-value="selectTab($event as EditorTab)"
                />

                <!-- 1. Associação -->
                <section
                    v-if="activeTab === 'associacao'"
                    class="flex flex-col gap-4"
                    data-test="work-editor-tab-associacao"
                >
                    <UCard>
                        <template #header>
                            <h2 class="text-sm font-semibold">
                                Regimes tributários
                            </h2>
                            <p class="text-muted text-xs">
                                Vazio ou “Todos” = todas as empresas da Account.
                            </p>
                        </template>

                        <div class="flex flex-wrap gap-2">
                            <UButton
                                :variant="
                                    regimes.includes('all')
                                        ? 'solid'
                                        : 'outline'
                                "
                                color="neutral"
                                size="sm"
                                :data-test="`work-editor-regime-all`"
                                @click="toggleRegime('all')"
                            >
                                Todos
                            </UButton>
                            <UButton
                                v-for="regime in regimeOptions"
                                :key="regime"
                                :variant="
                                    regimes.includes(regime)
                                        ? 'solid'
                                        : 'outline'
                                "
                                color="neutral"
                                size="sm"
                                :data-test="`work-editor-regime-${regime}`"
                                @click="toggleRegime(regime)"
                            >
                                {{ regimeLabel(regime) }}
                            </UButton>
                        </div>
                    </UCard>

                    <UCard>
                        <template #header>
                            <h2 class="text-sm font-semibold">Etiquetas</h2>
                        </template>

                        <UCheckbox
                            :model-value="useTagFilter"
                            label="Filtrar por etiquetas"
                            data-test="work-editor-tags-toggle"
                            @update:model-value="useTagFilter = Boolean($event)"
                        />

                        <div
                            v-if="useTagFilter"
                            class="mt-3 flex flex-wrap gap-2"
                        >
                            <p
                                v-if="tags.length === 0"
                                class="text-muted text-sm"
                            >
                                Nenhuma etiqueta na Account.
                            </p>
                            <UButton
                                v-for="tag in tags"
                                :key="tag.id"
                                :variant="
                                    tagIds.includes(tag.id)
                                        ? 'solid'
                                        : 'outline'
                                "
                                color="neutral"
                                size="sm"
                                :data-test="`work-editor-tag-${tag.id}`"
                                @click="tagIds = toggleId(tagIds, tag.id)"
                            >
                                {{ tag.name }}
                            </UButton>
                        </div>
                    </UCard>

                    <UCard data-test="work-editor-preview">
                        <template #header>
                            <h2 class="text-sm font-semibold">
                                Resumo do conjunto
                            </h2>
                        </template>

                        <p
                            class="text-sm"
                            data-test="work-editor-preview-count"
                        >
                            <span v-if="previewLoading">Atualizando…</span>
                            <span v-else>
                                {{ preview.count }}
                                {{
                                    preview.count === 1 ? 'empresa' : 'empresas'
                                }}
                                no conjunto ({{ preview.by_source.rule.length }}
                                pela regra,
                                {{ preview.by_source.extras.length }}
                                adicionadas).
                            </span>
                        </p>
                    </UCard>
                </section>

                <!-- 2. Clientes e Exceções -->
                <section
                    v-if="activeTab === 'clientes'"
                    class="flex flex-col gap-4"
                    data-test="work-editor-tab-clientes"
                >
                    <UCard>
                        <template #header>
                            <h2 class="text-sm font-semibold">
                                Conjunto calculado ({{
                                    computedClients.length
                                }})
                            </h2>
                        </template>

                        <ul
                            v-if="computedClients.length > 0"
                            class="flex flex-col gap-1.5 text-sm"
                        >
                            <li
                                v-for="client in computedClients"
                                :key="client.id"
                                :data-test="`work-editor-computed-${client.id}`"
                            >
                                {{ client.razao_social }}
                                <span class="text-muted text-xs">
                                    · {{ regimeLabel(client.regime) }}
                                </span>
                            </li>
                        </ul>
                        <p v-else class="text-muted text-sm">
                            Nenhuma empresa no conjunto com as regras atuais.
                        </p>
                    </UCard>

                    <UCard>
                        <template #header>
                            <h2 class="text-sm font-semibold">
                                Adicionados (extras)
                            </h2>
                            <p class="text-muted text-xs">
                                Empresas fora da regra, incluídas à mão.
                            </p>
                        </template>

                        <div
                            class="flex max-h-64 flex-col gap-1.5 overflow-y-auto"
                        >
                            <UCheckbox
                                v-for="client in clients"
                                :key="client.id"
                                :model-value="extraIds.includes(client.id)"
                                :label="client.razao_social"
                                :data-test="`work-editor-extra-${client.id}`"
                                @update:model-value="
                                    extraIds = toggleId(extraIds, client.id)
                                "
                            />
                        </div>
                    </UCard>

                    <UCard>
                        <template #header>
                            <h2 class="text-sm font-semibold">
                                Removidos (exceções)
                            </h2>
                            <p class="text-muted text-xs">
                                Empresas da regra, excluídas à mão.
                            </p>
                        </template>

                        <p
                            v-if="ruleClients.length === 0"
                            class="text-muted text-sm"
                        >
                            A regra atual não alcança nenhuma empresa.
                        </p>
                        <div
                            v-else
                            class="flex max-h-64 flex-col gap-1.5 overflow-y-auto"
                        >
                            <UCheckbox
                                v-for="client in ruleClients"
                                :key="client.id"
                                :model-value="excludedIds.includes(client.id)"
                                :label="client.razao_social"
                                :data-test="`work-editor-excluded-${client.id}`"
                                @update:model-value="
                                    excludedIds = toggleId(
                                        excludedIds,
                                        client.id,
                                    )
                                "
                            />
                        </div>
                    </UCard>
                </section>

                <!-- 3. Prazo -->
                <section
                    v-if="activeTab === 'prazo'"
                    class="flex flex-col gap-4"
                    data-test="work-editor-tab-prazo"
                >
                    <UCard>
                        <template #header>
                            <h2 class="text-sm font-semibold">
                                Modo de vencimento
                            </h2>
                        </template>

                        <URadioGroup
                            v-model="dueMode"
                            :items="[
                                { label: 'Sem regra de prazo', value: null },
                                { label: 'Dia fixo', value: 'fixed_day' },
                                { label: 'Prazo estimado', value: 'estimated' },
                            ]"
                            data-test="work-editor-due-mode"
                        />

                        <div
                            v-if="dueMode === 'fixed_day'"
                            class="mt-3 max-w-xs"
                        >
                            <UFormField
                                label="Dia do vencimento"
                                name="due_day"
                            >
                                <UInput
                                    :model-value="
                                        dueDay === null ? '' : String(dueDay)
                                    "
                                    type="number"
                                    min="1"
                                    max="31"
                                    data-test="work-editor-due-day"
                                    @update:model-value="
                                        dueDay = toNullableInt($event)
                                    "
                                />
                            </UFormField>
                        </div>

                        <div
                            v-if="dueMode === 'estimated'"
                            class="mt-3 max-w-xs"
                        >
                            <UFormField
                                label="Duração estimada (dias)"
                                name="estimated_duration_days"
                            >
                                <UInput
                                    :model-value="
                                        estimatedDays === null
                                            ? ''
                                            : String(estimatedDays)
                                    "
                                    type="number"
                                    min="0"
                                    data-test="work-editor-estimated-days"
                                    @update:model-value="
                                        estimatedDays = toNullableInt($event)
                                    "
                                />
                            </UFormField>
                        </div>
                    </UCard>

                    <UCard>
                        <template #header>
                            <h2 class="text-sm font-semibold">
                                Competência e meta
                            </h2>
                        </template>

                        <div class="grid max-w-xl gap-3 sm:grid-cols-2">
                            <UFormField
                                label="Competência"
                                name="competence_offset"
                            >
                                <USelect
                                    v-model="competenceOffset"
                                    :items="[
                                        {
                                            label: 'Mês do vencimento',
                                            value: 'due_month',
                                        },
                                        {
                                            label: 'Mês anterior',
                                            value: 'previous_month',
                                        },
                                    ]"
                                    data-test="work-editor-competence-offset"
                                />
                            </UFormField>
                            <UFormField
                                label="Antecedência da meta (dias)"
                                name="target_lead_days"
                            >
                                <UInput
                                    :model-value="
                                        targetLeadDays === null
                                            ? ''
                                            : String(targetLeadDays)
                                    "
                                    type="number"
                                    min="0"
                                    data-test="work-editor-target-lead-days"
                                    @update:model-value="
                                        targetLeadDays = toNullableInt($event)
                                    "
                                />
                            </UFormField>
                        </div>
                    </UCard>
                </section>

                <!-- 4. Tarefas -->
                <section
                    v-if="activeTab === 'tarefas'"
                    class="flex flex-col gap-3"
                    data-test="work-editor-tab-tarefas"
                >
                    <ol
                        v-if="draftTasks.length > 0"
                        class="flex flex-col gap-2"
                        data-test="work-editor-task-list"
                    >
                        <li
                            v-for="(task, index) in draftTasks"
                            :key="task.key"
                            :data-test="`work-editor-task-${task.id ?? task.key}`"
                            class="border-default flex items-center gap-2 rounded-lg border p-2"
                        >
                            <span
                                class="text-muted w-6 text-right text-xs tabular-nums"
                            >
                                {{ index + 1 }}
                            </span>
                            <UInput
                                v-model="task.title"
                                class="flex-1"
                                :aria-label="`Tarefa ${index + 1}`"
                            />
                            <UButton
                                color="neutral"
                                variant="ghost"
                                size="xs"
                                icon="i-lucide-arrow-up"
                                :aria-label="`Subir tarefa ${index + 1}`"
                                :disabled="index === 0"
                                :data-test="`work-editor-task-up-${index}`"
                                @click="moveTask(index, -1)"
                            />
                            <UButton
                                color="neutral"
                                variant="ghost"
                                size="xs"
                                icon="i-lucide-arrow-down"
                                :aria-label="`Descer tarefa ${index + 1}`"
                                :disabled="index === draftTasks.length - 1"
                                :data-test="`work-editor-task-down-${index}`"
                                @click="moveTask(index, 1)"
                            />
                            <UButton
                                color="error"
                                variant="ghost"
                                size="xs"
                                icon="i-lucide-trash-2"
                                :aria-label="`Remover tarefa ${index + 1}`"
                                :data-test="`work-editor-task-remove-${index}`"
                                @click="removeTask(index)"
                            />
                        </li>
                    </ol>
                    <p v-else class="text-muted text-sm">
                        Checklist vazio — adicione a primeira tarefa abaixo.
                    </p>

                    <div class="flex gap-2">
                        <UInput
                            v-model="newTaskTitle"
                            class="max-w-sm flex-1"
                            placeholder="Nova tarefa…"
                            data-test="work-editor-task-new"
                            @keyup.enter="addTask"
                        />
                        <UButton
                            icon="i-lucide-plus"
                            data-test="work-editor-task-add"
                            @click="addTask"
                        >
                            Adicionar
                        </UButton>
                    </div>
                    <p class="text-muted text-xs">
                        O checklist é salvo pelo Aplicar associação, junto das
                        regras.
                    </p>
                </section>

                <!-- 5. Recorrência -->
                <section
                    v-if="activeTab === 'recorrencia'"
                    class="flex flex-col gap-4"
                    data-test="work-editor-tab-recorrencia"
                >
                    <UCard>
                        <template #header>
                            <h2 class="text-sm font-semibold">Repetição</h2>
                        </template>

                        <URadioGroup
                            v-model="recurrencePreset"
                            :items="[
                                { label: 'Desligada', value: 'off' },
                                { label: 'Diária', value: 'diaria' },
                                { label: 'Mensal', value: 'mensal' },
                                { label: 'Trimestral', value: 'trimestral' },
                                { label: 'Semestral', value: 'semestral' },
                                { label: 'Anual', value: 'anual' },
                                { label: 'Personalizada', value: 'custom' },
                            ]"
                            data-test="work-editor-recurrence"
                        />

                        <div
                            v-if="recurrencePreset === 'custom'"
                            class="mt-3 grid max-w-xl gap-3 sm:grid-cols-2"
                        >
                            <UFormField
                                label="Intervalo"
                                name="recurrence_interval"
                            >
                                <UInput
                                    :model-value="String(customInterval)"
                                    type="number"
                                    min="0"
                                    data-test="work-editor-recurrence-interval"
                                    @update:model-value="
                                        customInterval =
                                            toNullableInt($event) ?? 1
                                    "
                                />
                            </UFormField>
                            <UFormField label="Unidade" name="recurrence_unit">
                                <USelect
                                    v-model="customUnit"
                                    :items="[
                                        { label: 'Dias', value: 'day' },
                                        { label: 'Semanas', value: 'week' },
                                        { label: 'Meses', value: 'month' },
                                        { label: 'Anos', value: 'year' },
                                    ]"
                                    data-test="work-editor-recurrence-unit"
                                />
                            </UFormField>
                        </div>
                    </UCard>
                </section>

                <!-- Aplicar -->
                <div
                    class="border-default bg-default/95 sticky bottom-0 flex flex-wrap items-center gap-3 border-t py-3 backdrop-blur"
                >
                    <UButton
                        size="lg"
                        :loading="applying"
                        :disabled="needsRefresh"
                        data-test="work-editor-apply"
                        @click="applyAssociation"
                    >
                        Aplicar associação
                    </UButton>
                    <p
                        v-if="needsRefresh"
                        class="text-warning text-sm font-medium"
                        data-test="work-editor-refresh-needed"
                    >
                        O checklist foi salvo, mas a confirmação falhou.
                        Recarregue a página antes de aplicar de novo.
                    </p>
                    <p
                        v-if="applyResult !== null"
                        class="text-success text-sm font-medium"
                        data-test="work-editor-apply-result"
                    >
                        Associação aplicada — {{ applyResult }}
                        {{ applyResult === 1 ? 'empresa' : 'empresas' }}.
                    </p>
                    <p
                        v-if="applyError !== null"
                        class="text-error text-sm font-medium"
                        data-test="work-editor-apply-error"
                    >
                        {{ applyError }}
                    </p>
                </div>
            </div>
        </template>
    </UDashboardPanel>
</template>
