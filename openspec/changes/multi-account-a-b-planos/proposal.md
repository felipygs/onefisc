## Why

A plataforma precisa atender múltiplos escritórios de contabilidade com dados isolados, papéis distintos e planos de assinatura, sob administração de uma Account principal. Sem esse modelo, não há como separar clientes, controlar acesso por nível nem monetizar por plano.

## What Changes

- Modelo Account com dois perfis: A (principal, criada no onboarding) e B (escritório comum).
- Quatro níveis de usuário: super_admin (só na A), admin, operador e user, com fronteiras definidas.
- Onboarding cria a primeira Account A com super_admin; registro público fechado; novos usuários entram por convite (7 dias, senha no aceite).
- Catálogo de Plans gerenciado pela A (usuários, Clients, módulos, volume); novas Accounts nascem no básico com admin convidado no ato.
- Carteira de Clients isolada por Account (CNPJ pode repetir), com dados cadastrais e monitoramento agendado.
- Seletor de Accounts exclusivo do super_admin, com poderes de plataforma e auditoria.
- Auditoria única cobrindo plataforma e operação, com filtro por Account e ator.

## Capabilities

### New Capabilities

- `accounts`: criação e perfis de Account (A/B), vínculo usuário↔Account, criação de Accounts pela A.
- `roles`: níveis super_admin/admin/operador/user, permissões por nível, promoção a super_admin.
- `onboarding-invites`: onboarding da primeira Account, fechamento do registro público, convites com expiração.
- `plans`: catálogo de Plans, limites (usuários, Clients, módulos, volume), troca de Plan só pela A, bloqueio ao estourar.
- `clients`: carteira isolada por Account, dados do Client, monitoramento agendado.
- `account-switcher`: seletor do super_admin, poderes via seletor, auditoria de acesso.
- `audit`: log único de plataforma e operação com filtros.

### Modified Capabilities

- Nenhuma (projeto sem specs prévias).

## Impact

- Backend: models Account, Plan, Client, Invitation, AuditLog; vínculo user→account; policies/Gates por papel; rotas Inertia novas; Fortify com registro fechado.
- Frontend: páginas de onboarding, gestão de Accounts/Plans (A), carteira de Clients, seletor de Accounts, telas de auditoria; componentes Nuxt UI conforme catálogo abaixo.
- Banco: novas tabelas via migrations; SQLite em dev.
- Quebra de compatibilidade: **BREAKING** — registro público (`/register`) deixa de existir como auto-cadastro.
- **BREAKING (frontend)** — sidebar e layouts atuais em shadcn-vue (`resources/js/components/ui/*`, `AppSidebar.vue`, `NavMain.vue`) são substituídos pelo padrão Dashboard do Nuxt UI.

## UI Catalog (Nuxt UI v4)

Shell: `UApp` (raiz, obrigatório) → `UDashboardGroup` → `UDashboardSidebar` (collapsible, header com logo + `UDashboardSearchButton`, body com `UNavigationMenu` vertical por grupos de papel, footer com `UUser` + `UDropdownMenu`) → `UDashboardPanel` por página (`UDashboardNavbar` com `UDashboardSidebarToggle` + título + ações, `UDashboardToolbar` com filtros, `#body` rolável).

Sidebar por papel:
- super_admin (A): Dashboard, Accounts, Plans, Clients, Auditoria, Usuários + grupo Plataforma (Seletor via `AccountSwitcher`).
- admin: Dashboard, Clients, Monitoramento, Comunicações, Documentos, Usuários, Configurações.
- operador: Dashboard, Clients, Monitoramento, Comunicações, Documentos, Tarefas.
- user: Dashboard, Minhas Tarefas, Comunicações, Documentos.

Páginas e componentes:
- `Onboarding.vue`: `UAuthForm`-like custom (`UCard` + `UForm` + `UFormField` + `UInput` nome/email/senha + `UButton` submit + `UAlert` erro).
- `invitations/Accept.vue`: `UCard` + `UForm` (senha + confirmação) + `UAlert` expirado + `UButton`.
- `admin/Accounts/Index.vue`: `UDashboardPanel` + `UTable` (nome, perfil `UBadge`, plan, ações `UDropdownMenu`: ver, trocar plan, entrar via seletor) + `UButton` Nova Account.
- `admin/Accounts/Create.vue`: `UForm` (`UInput` nome + `UInput` email admin; Plan sempre o básico padrão, sem seleção) + `UButton`.
- `admin/Plans/Index.vue`: `UPricingPlans`/`UPricingPlan` (3 plans, destaque no atual) + `UButton` gerenciar.
- `admin/Plans/Edit.vue`: `UForm` (`UInput` nome/preço + `UInputNumber` limites + `UCheckboxGroup` módulos + `USwitch` padrão) + `UButton`.
- `clients/Index.vue`: `UDashboardToolbar` (`UInput` busca + `USelect` regime) + `UTable` (CNPJ, razão social, regime `UBadge`, contador, status monitoramento) + `UPagination` + `UButton` Novo Client.
- `clients/Create.vue` / `Edit.vue`: `UForm` (`UInput` CNPJ com máscara + `UInput` razão social + `USelect` regime + `UInput` contador) + `UButton`.
- `clients/Show.vue`: `UCard` dados + `UTabs` (Resumo, Monitoramento, Documentos, Comunicações) + `UTimeline` histórico + `UAlert` volume esgotado.
- `admin/Audit/Index.vue`: `UDashboardToolbar` (`USelect` Account + `UInputMenu` ator + `UInputDate` range) + `UTable` (momento, ator `UUser`, origem, alvo, ação `UBadge`, detalhes `USlideover`) + `UPagination`.
- `AccountSwitcher.vue`: `USelectMenu` buscável de Accounts + `UBanner` persistente "Atuando como {account}" + `UButton` sair.
- Estados globais: `UEmpty` (listas vazias), `USkeleton` (carregamento), `UAlert` (limite estourado com ação upgrade), `UToast` via `useToast` (sucesso/erro), `UTooltip` em ações icônicas.

Composables: `useToast` (feedback), `useOverlay` (confirmações), `useCurrentAccount` + `usePermissions` (visibilidade por papel).
