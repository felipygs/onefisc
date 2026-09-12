# Plan — dashboard-template-shell

Execução em lotes sequenciais (shell compartilha arquivos; sem paralelismo entre lotes).
Referencia tarefas de `tasks.md`; não duplica seu escopo.

## Decisões de fundação (lote 1)

- Tema: `ui({ router: 'inertia', ui: { colors: { primary: 'green', neutral: 'zinc' } } })` em
  `vite.config.ts` + escala de verdes do template em `resources/css/app.css` (`@theme`).
  Fonte: manter Instrument Sans (identidade do produto, já carregada); sem troca para
  Public Sans neste change.
- Deps novas (exigidas pelo padrão do template): `date-fns`, `@internationalized/date`,
  `@unovis/vue`, `@unovis/ts`, `@tanstack/table-core`, `scule`.

## Lote 0 — Setup (pré-requisito do design, sem ID de tarefa)

- `npm install -S date-fns @internationalized/date @unovis/vue @unovis/ts @tanstack/table-core scule`
- Verificar: `npm run build` verde.

## Lote 1 — Fundação (1.1, 1.2)

- 1.1 RED: n/a (configuração visual; exceção de config do TDD). GREEN: tema acima.
  Verificar: `npm run build` + screenshots Dashboard/Clients/Settings vs. auth (checklist manual).
- 1.2 `resources/js/composables/useDashboard.ts` (novo): estado compartilhado
  `isNotificationsSlideoverOpen`, atalhos `g-h`/`g-c`/`g-s`/`n` ignorados com foco em
  campo editável, fechamento ao navegar (evento `navigate` do router Inertia).
  Verificar: `npm run types:check` + checklist manual de atalhos.

## Lote 2 — Shell global (2.1–2.5)

RED primeiro (Pest, `tests/Feature/DashboardShellTest.php` novo):
- super_admin recebe prop `switchableAccounts` (id+name); admin comum não recebe.
- resposta do dashboard contém prop `notifications` (lista).
Comandos: `php artisan test --filter=DashboardShellTest` (ver falhar), implementar, rever passar.

GREEN backend — `app/Http/Middleware/HandleInertiaRequests.php` (`share`):
- `switchableAccounts`: só quando `manage-platform`, `Account::orderBy('name')` (id, name).
- `notifications`: últimos 10 `AuditLog` da Account (origin ou target = atual) com actor,
  mapeados para `{ id, sender: { name }, body: <ação legível>, date: <created_at> }`.

GREEN frontend (novos em `resources/js/components/shell/`, exceto onde indicado):
- 2.1 `AppShell.vue` (novo, substitui `layouts/app/*`): `UDashboardGroup` + `UDashboardSidebar`
  (collapsible, resizable) + `UNavigationMenu` (Home→`dashboard`, Clients→`clients.index`,
  Settings trigger→General/Security/Appearance existentes; Members/Notifications entram no
  lote 5.1) + Feedback/Help externos. Reescreve `layouts/AppLayout.vue` para o novo shell.
- 2.2 `DashboardSearch.vue` (novo): `UDashboardSearchButton` + `UDashboardSearch` com grupos
  Go to (rotas Wayfinder) e Clients (lê `usePage().props.clients.data` quando presente).
- 2.3 `NotificationsSlideover.vue` (novo): lê prop compartilhada `notifications`, tempo
  relativo, link ao relacionado, abre por sino e atalho.
- 2.4 `TeamsMenu.vue` (novo): Account atual + lista `switchableAccounts`, selecionar via
  `router.post(switcher.select)`, sair via `router.delete(switcher.destroy)`, links para
  `switcher.index` e `plans.index`; `UserMenu.vue` (novo): `auth.user` real, perfil, Plan,
  ajustes, cores, aparência (`useAppearance`), docs, sair.
- 2.5 `CookieConsent.vue` (novo, `useCookie` do VueUse); `PlanLimitWarning` mantido e
  renderizado no topo de cada painel.
Verificar por tarefa: Pest do lote + `npm run types:check` + `npm run build` + checklist.

## Lote 3 — Home (3.1, 3.2)

RED: estender `tests/Feature/DashboardTest.php` — dashboard contém props `home.stats`
(4 itens) e `home.sales` (lista). `php artisan test --filter=DashboardTest` deve falhar.
GREEN: `app/Http/Controllers/DashboardController.php` (novo, `index`) + `routes/web.php`
(`Route::inertia('dashboard',...)` → controller, mesmo path): `home` com contagens reais
(Clients, usuários) + receita/pedidos ilustrativos e `sales` ilustrativa (estrutura fixa).
- 3.1 Reescreve `resources/js/pages/Dashboard.vue`: `UDashboardPanel` + Navbar/Toolbar,
  `HomeDateRangePicker`, `HomePeriodSelect`, `HomeStats`, `HomeChart` (Unovis isolado com
  fallback), `HomeSales`; intervalo/período atualizam os três blocos.
- 3.2 Dropdown Novo (Novo Client→`clients.create`) no Navbar.
Verificar: Pest + types + build + checklist (troca de intervalo, dropdown).

## Lote 4 — Clients (4.1, 4.2)

RED: `tests/Feature/Clients/ClientBulkTest.php` (novo) — exclusão em massa apaga só os
selecionados da Account atual (rota nova `clients.bulk-destroy`), 403 para papel user,
ids de outra Account ignorados (404/inalterados).
GREEN: rota + `ClientController@bulkDestroy` (valida `ids[]`, policy `operate-clients`,
escopo da Account).
- 4.1 Reescreve `resources/js/pages/clients/Index.vue` no padrão Customers: `UTable`
  (seleção, avatar+nome, e-mail ordenável, regime como status com badge, contador,
  ações por linha), busca, filtro por regime, visibilidade de colunas, paginação do
  servidor via `UPagination` (comportamento atual preservado).
- 4.2 Linha: copiar ID (clipboard+toast), Ver detalhes e Ver documentos (→`clients.show`),
  Excluir (modal de confirmação→destroy); barra de seleção → modal em massa→bulk-destroy;
  Novo client →`clients.create` (página existente).
Verificar: `php artisan test --filter=Client` + types + build + checklist (filtros, massa).

## Lote 5 — Settings (5.1, 5.2)

- 5.1 Reescreve `resources/js/layouts/settings/Layout.vue`: `UDashboardPanel` + Navbar +
  Toolbar + `UNavigationMenu` (General→`profile.edit`, Members→`members.index` nova,
  Notifications→`notifications.edit` nova, Security→`security.edit`,
  Appearance→`appearance.edit`). Atualiza sidebar do lote 2 com os dois links novos.
  Verificar: `php artisan test --filter=ProfileUpdateTest` + navegação General→Members.
- 5.2 RED: `tests/Feature/Settings/MembersTest.php` (novo) — página lista usuários da
  Account, convite cria `Invitation` com 7 dias (via `InvitationService::invite`),
  token expirado recusado (cobrir via teste de aceite existente + novo de isolamento).
  GREEN: `routes/settings.php` (+`routes/web.php` se preciso): `GET settings/members`
  (`members.index`, gate `manage-users`), `POST invitations` (`invitations.store` →
  `InvitationController@store` existente, sem rota até aqui) + página
  `resources/js/pages/settings/Members.vue` (lista + formulário de convite).
- Notifications: migration `notification_preferences` JSON nullable em `users` + casts,
  `PUT settings/notifications` + página `Notifications.vue` com switches (convites,
  limites de Plan, monitoramento). RED: Pest de persistência por usuário antes.
  Verificar: Pest + types + build.

## Lote 6 — Remoção e gate (6.1, 6.2)

- 6.1 Apagar shell legado (`components/AppSidebar.vue`, `AppHeader.vue`, `NavMain.vue`,
  `NavFooter.vue`, `NavUser.vue`, `UserMenuContent.vue`, `Breadcrumbs.vue`,
  `layouts/app/*`, `PlaceholderPattern.vue` se sem uso; `Heading.vue` só se sem uso).
  Verificar: `grep -r "components/ui\|AppSidebar\|AppHeader\|NavMain\|NavUser\|Breadcrumbs\|PlaceholderPattern" resources/js resources/views` vazio + `npm run build`.
- 6.2 Gate: `npm run types:check`, `npm run check`, `php artisan test`, Pint/PHPStan
  (`composer lint:check`, `phpstan analyse` se disponíveis), checklist por papel
  (super_admin, admin, operador, user) + isolamento de carteira.
- Commits por lote (`feat(dashboard-shell): ...`), review antes de cada commit,
  archive por último (sempre).

## Comandos de verificação (todos os lotes)

- `php artisan test --filter=<Nome>`
- `npm run types:check`
- `npm run check`
- `npm run build`

## Gotcha: regenerar rotas Wayfinder

- `resources/js/routes` e `resources/js/actions` NÃO estão no git (gerados).
- Sempre regenerar com `php artisan wayfinder:generate --with-form` (equivale ao
  `formVariants: true` do `vite.config.ts`); sem `--with-form` os helpers `.form`
  somem e dezenas de arquivos quebram no `types:check`.

## Banco real (Postgres)

- O stack oficial é Postgres 18 via `docker-compose.yml` (`postgres:18-alpine`).
- O gate (`phpunit.xml`) segue em SQLite in-memory; `phpunit.pgsql.xml` espelha a
  config apontando para o banco `testing` no Postgres local para verificação de
  compatibilidade: `vendor/bin/pest -c phpunit.pgsql.xml [--filter=<Nome>]`.
- Subir o banco: `docker compose up -d postgres` (usuário `laravel`, banco `testing`
  criado à parte do banco de dev).
- Queries novas devem ser compatíveis com Postgres (sem `LIKE` case-sensitive onde
  fizer diferença, sem `GROUP BY` frouxo, coluna JSON via tipo `json`).

## Decisões visuais (verificadas por screenshot contra a referência oficial)

- Fonte: Public Sans (igual ao template) via Bunny Fonts, em `vite.config.ts` e
  `resources/css/app.css`.
- Tokens `--color-primary`/`--color-secondary`: pertencem ao Nuxt UI globalmente
  (verde do tema); subtrees shadcn (`Auth*Layout`, `settings/Profile`,
  `settings/Security`) usam o escopo `.shadcn` até `components/ui/*` ser removido.
  Sem o termo "legacy" no código.
- `TeamsMenu`/`UserMenu` exibem avatar com iniciais quando não há foto.
