# Frontend fiscal (documentos) — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir o frontend do sistema de documentos no estilo do `_legacy`, adaptado à stack atual (Inertia + Nuxt UI v4).

**Architecture:** Inertia-first, sem API JSON nova: controllers finos compartilham props, páginas `documents/*` + aba Fiscal em `clients/Show.vue`, componentes em `resources/js/components/documents/*`. Sync é leitura pura; certificado em rail + modais.

**Tech Stack:** Laravel 11 + Inertia 3 + Vue 3.5 + Nuxt UI v4 (`UTable`, `UModal`, `USlideover`, `UTabs`, `UPagination`, `UEmpty`, `UAlert`) + `@unovis/vue` (gráfico) + Pest + `vue-tsc`.

**Spec:** `openspec/changes/fiscal-dfe-monitor/` — implementa as tasks **2.3, 4.2, 4.3, 4.4, 4.5** do `tasks.md`. O `tasks.md` é o contrato de escopo; este plano detalha a execução do subsistema frontend.

## Global Constraints

- Rotas via Wayfinder (`resources/js/routes/*` gerado pelo `@laravel/vite-plugin-wayfinder`); nunca hardcodar URLs.
- Isolamento por `account_id` em toda query (exceto super_admin via Seletor de Accounts); segredos (PFX/senha) nunca em log, props ou auditoria.
- PHP: 4 espaços, Pint (`composer lint:check`); PHPStan nível 7; Pest com SQLite in-memory.
- Frontend: `npm run types:check` + `npm run build` verdes; `data-test` em toda interação; `UToast` via `useToast` para feedback.
- Commits convencionais com escopo (`feat(documents): ...`); review antes de cada commit.

## Dependências (ler antes de começar)

1. **Backend fiscal (tasks 1.x–3.x, 4.1, 5.1 da mesma change)** — models, sync e guarda precisam existir para alimentar as props. Se ainda não existem, implementar o backend primeiro ou em paralelo; o Lote 0 define o contrato de props para desacoplar.
2. **`dashboard-template-shell`** — está migrando o shell para `UDashboardPanel` + padrão `UTable` Customers e removendo `AppSidebar/*`. O padrão `UTable` dessa change é a autoridade para `DocumentsTable`. Se ela ainda não aterrissou, construir sobre o shell vigente e fazer rebase depois (o plano usa "shell vigente" onde diz `AppSidebarLayout`).
3. **Referência visual (somente leitura):** `_legacy` em `/home/obsidian/dev/oniscan/_legacy/apps/web/app/{pages/documents,components/documents,components/clients/ClientDetailLayout.vue}` + `DESIGN.md` (Central Operacional, One-Green Rule, Flat-by-Default).

## Estrutura de arquivos

**Backend (novo):**
- `app/Http/Controllers/DocumentDashboardController.php` — `index` (GET `/documents`, props do portfólio), `all` (GET `/documents/all`, tabela global), `clients` (GET `/documents/clients`, atenção da carteira). Somente leitura.
- `app/Http/Controllers/CertificateController.php` — `store` (upload PFX + senha portal, só admin), `destroy` (remoção, só admin). Validação PKCS#12 + segredo criptografado.
- `app/Http/Controllers/FiscalDownloadController.php` — `show` (URL assinada curta para XML) e `pdf` (DANFE/DANFSe). Sem expor referência interna de storage.
- Modificar: `app/Http/Controllers/ClientController.php@show` (+ props da aba Fiscal: documentos paginados, estado sync, estado certificado), `routes/web.php` (+ 5 rotas com gates).

**Frontend (novo):**
- `resources/js/types/documents.ts` — tipos das props (documento, overview, atenção, sync-state, certificado).
- `resources/js/pages/documents/Index.vue`, `All.vue`, `Clients.vue`.
- `resources/js/components/documents/`: `PanelCards.vue`, `PanelChart.vue`, `PanelFamilies.vue`, `PanelRank.vue`, `PanelRecent.vue`, `PanelAttention.vue`, `DocumentsTable.vue`, `FiltersToolbar.vue`, `DetailSlideover.vue`, `DanfeModal.vue`, `SyncStateCard.vue`, `CredentialsRailCard.vue`, `CertificateUploadModal.vue`, `PortalPasswordModal.vue`.

**Frontend (modificar):**
- `resources/js/pages/clients/Show.vue` — 4ª aba `Fiscal` com sub-tabs Documentos/Sincronização/Certificado.

**Testes (novo):**
- `tests/Feature/Documents/DocumentIsolationTest.php`, `DocumentFiltersTest.php`, `SyncStateTest.php`, `CertificateUiTest.php`, `FiscalDownloadTest.php`.

---

### Lote 0 — Contrato de props (desbloqueia todo o resto)

**Files:**
- Create: `resources/js/types/documents.ts`
- Test: nenhum (tipos; verificação via `vue-tsc`)

**Interfaces:**
- Produces: tipos `FiscalDocumentRow`, `DocumentsOverview`, `AttentionRow`, `SyncState`, `CertificateState` consumidos pelos Lotes 1–5.

- [ ] **Step 1: Criar `resources/js/types/documents.ts` com os tipos do contrato**

```ts
export type FiscalFamily = 'nfe' | 'cte' | 'nfse';
export interface FiscalDocumentRow {
    id: number; family: FiscalFamily; doc_type: string;
    number: string | null; series: string | null; key: string | null;
    derived_from_key: boolean; emission_at: string | null;
    issuer_name: string | null; issuer_tax_id: string | null;
    recipient_name: string | null; recipient_tax_id: string | null;
    status: 'authorized' | 'cancelled' | 'denied' | 'pending' | null;
    status_label: string; has_xml: boolean; has_danfe: boolean;
    client?: { id: number; name: string } | null;
}
export interface SyncState {
    last_run_at: string | null; new_documents: number;
    next_run_at: string | null; blocked_until: string | null;
    volume_exhausted: boolean;
}
export type CertificateState =
    | { status: 'valid'; expires_at: string }
    | { status: 'expiring'; expires_at: string }
    | { status: 'expired' }
    | { status: 'missing' };
export interface AttentionRow {
    client_id: number; name: string; tax_id: string;
    reason: 'sync_failed' | 'coverage_limited' | 'certificate_missing' | 'certificate_expiring' | 'pending_xml';
}
export interface DocumentsOverview {
    totals: { documents: number; pending_xml: number; sync_attention: number; certificates_expiring: number; clients: number; clients_with_documents: number };
    families: { family: FiscalFamily; count: number }[];
    rankings: { clients: { id: number; name: string; value: number }[] };
    recent: FiscalDocumentRow[]; attention: AttentionRow[];
}
```

- [ ] **Step 2: Verificar tipos compilam**

Run: `npm run types:check`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add resources/js/types/documents.ts
git commit -m "feat(documents): add fiscal frontend prop contract types"
```

---

### Lote 1 — Certificado em rail + modais (task 2.3)

**Files:**
- Create: `app/Http/Controllers/CertificateController.php`, `resources/js/components/documents/CredentialsRailCard.vue`, `resources/js/components/documents/CertificateUploadModal.vue`, `resources/js/components/documents/PortalPasswordModal.vue`
- Modify: `routes/web.php`, `app/Http/Controllers/ClientController.php@show` (+ prop `certificate: CertificateState`)
- Test: `tests/Feature/Documents/CertificateUiTest.php`

**Interfaces:**
- Consumes: `CertificateState` (Lote 0).
- Produces: rotas `certificates.store` / `certificates.destroy` usadas pelo Lote 5.

- [ ] **Step 1: Write the failing test (permissão + nunca reexibir senha)**

```php
it('denies certificate upload to non-admin and never exposes the secret', function () {
    $account = Account::factory()->create();
    $client = Client::factory()->for($account)->create();
    $operator = User::factory()->create();
    $account->users()->attach($operator, ['role' => 'operador']);

    $this->actingAs($operator)
        ->post(route('certificates.store', $client), ['pfx' => 'x', 'password' => 's'])
        ->assertForbidden();

    $this->actingAs($account->admins()->first())
        ->get(route('clients.show', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('certificate.status', 'missing')
            ->missing('certificate.password'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CertificateUiTest`
Expected: FAIL (controller/rotas não existem)

- [ ] **Step 3: Write minimal implementation** — rotas com gate admin + `CertificateController@store/destroy` (valida PFX via PKCS#12, guarda criptografado, audita sem segredo) + prop `certificate` no `show`; componentes: rail com `UBadge` de validade, `UModal` + `UForm` (arquivo + senha), alerta de erro junto da ação, `useToast` no sucesso.

- [ ] **Step 4: Run tests + frontend checks**

Run: `php artisan test --filter=CertificateUiTest` (PASS), `npm run types:check` (PASS), `npm run build` (PASS)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/CertificateController.php routes/web.php "app/Http/Controllers/ClientController.php" resources/js/components/documents/CredentialsRailCard.vue resources/js/components/documents/CertificateUploadModal.vue resources/js/components/documents/PortalPasswordModal.vue tests/Feature/Documents/CertificateUiTest.php
git commit -m "feat(documents): certificate rail with upload modals"
```

---

### Lote 2 — Tabela avançada + filtros (task 4.3)

**Files:**
- Create: `resources/js/components/documents/DocumentsTable.vue`, `resources/js/components/documents/FiltersToolbar.vue`, `tests/Feature/Documents/DocumentFiltersTest.php`
- Modify: `app/Http/Controllers/ClientController.php@show` (+ paginator `documents` com filtros `?q&family&status&origin`), `app/Http/Controllers/DocumentDashboardController.php@all` (se Lote 4 ainda não existir, criar o controller aqui com só o método `all`)

**Interfaces:**
- Consumes: `FiscalDocumentRow` (Lote 0).
- Produces: `DocumentsTable` + `FiltersToolbar` reutilizados nos Lotes 4 e 5.

- [ ] **Step 1: Write the failing test (filtro por modelo + isolamento)**

```php
it('filters documents by family within the account only', function () {
    [$account, $other] = Account::factory()->count(2)->create();
    $client = Client::factory()->for($account)->create();
    FiscalDocument::factory()->for($client)->create(['family' => 'cte']);
    FiscalDocument::factory()->for(Client::factory()->for($other)->create())->create(['family' => 'cte']);

    $this->actingAs($account->admins()->first())
        ->get(route('documents.all', ['family' => 'cte']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('documents.total', 1)
            ->where('documents.data.0.family', 'cte'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DocumentFiltersTest`
Expected: FAIL

- [ ] **Step 3: Write minimal implementation** — backend: query com global scope + filtros + `->paginate()` preservando query-string; frontend: `DocumentsTable` em `UTable` (coluna Documento com `UBadge` de família + número/série + chave mono + selo "Derivado"; ordenação Documento/Emissão/Status; dropdown Ações; `selectable=false`), `FiltersToolbar` (`UInput` com atalho `/` focando o campo, popover Tipo/Status/Origem, menu Exibição, botão limpar), mudanças disparam `router.get(url, params, { preserveScroll: true, preserveState: true })`, `UPagination` → `router.get` com `page`.

- [ ] **Step 4: Run tests + frontend checks**

Run: `php artisan test --filter=DocumentFiltersTest` (PASS), `npm run types:check` (PASS), `npm run build` (PASS)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DocumentDashboardController.php "app/Http/Controllers/ClientController.php" routes/web.php resources/js/components/documents/DocumentsTable.vue resources/js/components/documents/FiltersToolbar.vue tests/Feature/Documents/DocumentFiltersTest.php
git commit -m "feat(documents): advanced table with filters"
```

---

### Lote 3 — Overlays: detalhe + DANFE + download (task 4.4)

**Files:**
- Create: `app/Http/Controllers/FiscalDownloadController.php`, `resources/js/components/documents/DetailSlideover.vue`, `resources/js/components/documents/DanfeModal.vue`, `tests/Feature/Documents/FiscalDownloadTest.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `FiscalDocumentRow` (Lote 0), ações da tabela (Lote 2).
- Produces: overlays usados nos Lotes 4 e 5.

- [ ] **Step 1: Write the failing test (URL assinada curta, sem vazar storage)**

```php
it('downloads xml through a short-lived signed url without leaking storage refs', function () {
    $doc = FiscalDocument::factory()->create();
    $this->actingAs($doc->client->account->admins()->first());

    $url = $this->get(route('fiscal.download', $doc))->assertOk()->json('download_url');

    expect($url)->toContain('signature=')
        ->and($this->get($url)->assertOk()->headers->get('content-type'))->toContain('xml');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FiscalDownloadTest`
Expected: FAIL

- [ ] **Step 3: Write minimal implementation** — `FiscalDownloadController@show` (XML) e `@pdf` (DANFE/DANFSe; 404 honesto quando `has_danfe=false`), `signed` + expiração curta; `DetailSlideover` (`USlideover`: badge status, emitente/destinatário, chave mono com copiar + toast, completude/proveniência com selo ADN/XML derivado, seções disponíveis/pendentes); `DanfeModal` (`UModal` + `iframe` do PDF, erro com retry só quando retryable).

- [ ] **Step 4: Run tests + frontend checks**

Run: `php artisan test --filter=FiscalDownloadTest` (PASS), `npm run types:check` (PASS), `npm run build` (PASS)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/FiscalDownloadController.php routes/web.php resources/js/components/documents/DetailSlideover.vue resources/js/components/documents/DanfeModal.vue tests/Feature/Documents/FiscalDownloadTest.php
git commit -m "feat(documents): detail slideover with danfe preview and signed downloads"
```

---

### Lote 4 — Portfólio `/documents` (task 4.2)

**Files:**
- Create: `resources/js/pages/documents/Index.vue`, `resources/js/pages/documents/All.vue`, `resources/js/pages/documents/Clients.vue`, `resources/js/components/documents/PanelCards.vue`, `resources/js/components/documents/PanelChart.vue`, `resources/js/components/documents/PanelFamilies.vue`, `resources/js/components/documents/PanelRank.vue`, `resources/js/components/documents/PanelRecent.vue`, `resources/js/components/documents/PanelAttention.vue`, `tests/Feature/Documents/DocumentIsolationTest.php`
- Modify: `app/Http/Controllers/DocumentDashboardController.php` (+ `index`, `clients`), `routes/web.php`

**Interfaces:**
- Consumes: `DocumentsOverview`, `AttentionRow` (Lote 0); tabela/filtros/overlays (Lotes 2–3).
- Produces: rotas `documents.index|all|clients` linkadas pela sidebar e pela aba do Client.

- [ ] **Step 1: Write the failing test (isolamento do portfólio)**

```php
it('scopes the portfolio overview to the current account', function () {
    [$account, $other] = Account::factory()->count(2)->create();
    FiscalDocument::factory()->for(Client::factory()->for($account)->create())->create();
    FiscalDocument::factory()->for(Client::factory()->for($other)->create())->create();

    $this->actingAs($account->admins()->first())
        ->get(route('documents.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('overview.totals.documents', 1));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DocumentIsolationTest`
Expected: FAIL

- [ ] **Step 3: Write minimal implementation** — controller com agregados Eloquent escopados (totais, famílias, rankings top 8, recentes, atenção com motivo→badge); `Index.vue` com `UTabs` (Visão/Mercadorias/Serviços/Operação) + `UPageCard`s + `PanelChart` (`@unovis/vue` com fallback vazio) + demais painéis; `All.vue` (tabela+filtros globais, coluna Cliente visível); `Clients.vue` (atenção da carteira); `UEmpty`/`USkeleton`/`UAlert` nos estados.

- [ ] **Step 4: Run tests + frontend checks**

Run: `php artisan test --filter=DocumentIsolationTest` (PASS), `npm run types:check` (PASS), `npm run build` (PASS)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DocumentDashboardController.php routes/web.php resources/js/pages/documents/ resources/js/components/documents/Panel*.vue tests/Feature/Documents/DocumentIsolationTest.php
git commit -m "feat(documents): portfolio dashboard with global table and attention"
```

---

### Lote 5 — Aba Fiscal no Client (task 4.5)

**Files:**
- Create: `resources/js/components/documents/SyncStateCard.vue`, `tests/Feature/Documents/SyncStateTest.php`
- Modify: `resources/js/pages/clients/Show.vue`, `app/Http/Controllers/ClientController.php@show` (+ prop `sync: SyncState`)

**Interfaces:**
- Consumes: tudo dos Lotes 0–3.
- Produces: aba Fiscal completa (fim da v1 de frontend).

- [ ] **Step 1: Write the failing test (sync leitura pura + bloqueio visível)**

```php
it('exposes the sync state read-only with block and volume flags', function () {
    $client = Client::factory()->create();
    FiscalSyncSubscription::factory()->for($client)->create([
        'blocked_until' => now()->addMinutes(30),
    ]);

    $this->actingAs($client->account->admins()->first())
        ->get(route('clients.show', $client))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->whereNotNull('sync.blocked_until')
            ->where('sync.volume_exhausted', false));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SyncStateTest`
Expected: FAIL

- [ ] **Step 3: Write minimal implementation** — 4ª aba `Fiscal` no `TABS` do `Show.vue` com sub-tabs Documentos (tabela+filtros+overlays, coluna Cliente oculta) / Sincronização (`SyncStateCard`: capacidade por família, última execução, próximo ciclo, bloqueio, volume esgotado com CTA upgrade — nenhum botão de disparo) / Certificado (rail + modais do Lote 1); `UEmpty` orientando subir certificado quando vazio.

- [ ] **Step 4: Run tests + frontend checks**

Run: `php artisan test --filter=SyncStateTest` (PASS), `npm run types:check` (PASS), `npm run build` (PASS)

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/clients/Show.vue resources/js/components/documents/SyncStateCard.vue "app/Http/Controllers/ClientController.php" tests/Feature/Documents/SyncStateTest.php
git commit -m "feat(documents): fiscal tab on client page"
```

---

### Lote 6 — Gate final (task 5.2, parte frontend)

- [ ] **Step 1: Rodar o gate completo**

Run: `php artisan test` (PASS), `npm run types:check` (PASS), `npm run build` (PASS), `composer lint:check` (PASS), `npm run check` (PASS)

- [ ] **Step 2: Smoke manual fim a fim** — subir A1 → ciclo encontra resumo → ciência auto → XML guardado → slideover → DANFE no modal → volume contou → município não aderente mostra cobertura limitada; conferir por papel (admin vê modais, operador não).
- [ ] **Step 3: Marcar tasks 2.3, 4.2–4.5 como feitas no `tasks.md` e arquivar a change (`openspec-archive-change`) por último, nunca antes.**

## Self-Review

1. **Spec coverage:** portfólio → Lote 4; tabela/filtros → Lote 2; detalhe/DANFE → Lote 3; sync leitura → Lote 5; certificado rail/modais → Lote 1; isolamento/auditoria sem segredos → testes de cada lote; volume → `SyncStateCard` (Lote 5) + backend (task 3.5, fora deste plano).
2. **Placeholder scan:** sem TBD/TODO; comandos e arquivos exatos em cada passo.
3. **Type consistency:** tipos do Lote 0 (`FiscalDocumentRow`, `SyncState`, `CertificateState`, `AttentionRow`, `DocumentsOverview`) reutilizados verbatim nos lotes seguintes.
