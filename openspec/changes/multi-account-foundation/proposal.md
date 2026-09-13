## Why

A plataforma atende múltiplos escritórios de contabilidade e precisa de contas isoladas, papéis distintos, planos de assinatura e administração central pela operação principal. O repo foi reestruturado em `backend/` (Laravel API) e `frontend/` (Nuxt UI), e a base multi-account anterior não existe mais nesta estrutura. Sem esse alicerce não há como separar carteiras, controlar acesso por nível nem monetizar por plano.

## What Changes

- Fundação de autenticação por sessão: o backend Laravel expõe Sanctum stateful + Fortify; o frontend Nuxt autentica via BFF (server routes que repassam cookies httpOnly same-origin). Registro público fechado.
- Modelo de Account com dois perfis: A (principal, criada no onboarding) e B (escritório comum).
- Quatro níveis de usuário: super_admin (só na A), admin, operador e user, com fronteiras de permissão e navegação por nível.
- Onboarding cria a primeira Account A com super_admin; novos usuários entram somente por convite (7 dias, senha no aceite).
- Catálogo de Plans gerenciado pela A (3 planos: Básico padrão, Intermediário, Avançado); novas Accounts nascem no Básico com admin convidado no ato; troca de plano só pela A.
- Carteira de Clients isolada por Account (mesmo CNPJ pode repetir), com dados cadastrais (CNPJ, razão social, regime tributário, contador) e limites por Plan.
- Seletor de Accounts exclusivo do super_admin, com banner persistente "atuando como" e saída explícita.
- Auditoria única cobrindo plataforma e operação, com filtro por Account, ator e período.
- **BREAKING**: não existe mais auto-cadastro público (`/register`); a entrada é onboarding (base vazia) ou convite.

## Capabilities

### New Capabilities

- `authentication`: login/logout por sessão contra o backend via BFF, usuário atual, recuperação de senha; sem auto-cadastro.
- `accounts`: criação e perfis de Account (A/B), vínculo usuário↔Account, criação de Accounts B pela A.
- `roles`: níveis super_admin/admin/operador/user, permissões por nível e visibilidade de navegação.
- `onboarding-invites`: onboarding da primeira Account A, fechamento do registro público, convites com expiração e senha no aceite.
- `plans`: catálogo de 3 plans, limites (usuários, Clients, módulos, volume), plano padrão e troca exclusiva pela A.
- `clients`: carteira de Clients isolada por Account, dados cadastrais e monitoramento agendado (contrato; integração SERPRO fica para change futuro).
- `account-switcher`: seletor do super_admin, contexto de atuação e auditoria de acesso.
- `audit`: log único de plataforma e operação com filtros por Account, ator e período.

### Modified Capabilities

- Nenhuma. `openspec/specs/` está vazio; todas as capabilities acima são novas.

## Impact

- Backend (`backend/`): migrations `accounts`, `plans`, `clients`, `invitations`, `audit_logs` + colunas `account_id`/`role` em `users`; models e global scopes por Account; middleware de contexto de Account e do seletor; Gates/Policies por papel; services de provisionamento de Account, limites de Plan e convites; Sanctum + Fortify; observer de auditoria; seed dos 3 Plans.
- Frontend (`frontend/`): server routes BFF (`/api/auth/*`, `/api/*`) para o Laravel; páginas de login/onboarding/aceite de convite; área da A (Accounts, Plans, Auditoria); carteira de Clients; `AccountSwitcher` com banner; navegação do shell filtrada por papel.
- Banco: SQLite em dev; migrations reversíveis.
- Contrato frontend↔backend: JSON REST sob `/api`, sessão por cookie; documentado no design.
- Fora de escopo: integração real SERPRO, monitoramento executável, cobrança/pagamento (troca de plano é manual pela A).
