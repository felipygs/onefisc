## 1. Fundação do backend

- [ ] 1.1 Instalar Sanctum com `php artisan install:api` e Fortify com `composer require laravel/fortify`; publicar configs e verificar `php artisan route:list` sem erros e `composer test` verde.
- [ ] 1.2 Garantir SQLite em memória e `MAIL_MAILER=array` no `backend/phpunit.xml` e criar `tests/TestCase.php` com helpers de criação de Account/User; verificar um teste de fumaça passando.
- [ ] 1.3 Criar enum `AccountProfile` (A/B) e enum `UserRole` (super_admin/admin/operador/user); teste unitário cobre valores e rejeição de valor inválido.
- [ ] 1.4 Migration, model, factory e schema test de `plans` (`max_users`, `max_clients`, `modules` JSON, `monthly_query_volume`, `price_cents`, `is_default`); teste de persistência e casts.
- [ ] 1.5 Migration, model, factory e schema test de `accounts` (name, profile, `plan_id` nullable); teste cobre perfil A/B e relação com Plan.
- [ ] 1.6 Migration `users.account_id` + `users.role` e ajuste do model User (relação com Account); teste cobre vínculo e default `user`.
- [ ] 1.7 Migration, model e factory de `clients` (cnpj, razao_social, regime, contador_responsavel, `monitoring_enabled`, unique `account_id+cnpj`); teste cobre unique por Account e CNPJ repetido entre Accounts.
- [ ] 1.8 Migration, model e factory de `invitations` (name, email, role, token_hash unique, expires_at, accepted_at, invited_by_user_id); teste cobre token único e data de expiração.
- [ ] 1.9 Migration, model e factory de `audit_logs` (actor nullable, origin_account_id, target_account_id, action, metadata JSON, created_at); teste cobre persistência e ausência de updated_at.
- [ ] 1.10 PlanSeeder com Básico (padrão, 3 usuários, 10 Clients, módulo clients, 100 consultas), Intermediário e Avançado; teste confirma os 3 planos e exatamente um padrão.

## 2. Contexto de Account e papéis

- [ ] 2.1 Implementar singleton `CurrentAccount` e concern `BelongsToAccount` (global scope + preenchimento de `account_id`); teste de isolamento prova que leitura/escrita de outra Account não aparece.
- [ ] 2.2 Implementar middleware `resolve.account` (Account do usuário ou alvo do seletor válido apenas para super_admin) e registrá-lo nas rotas `/api`; teste cobre conta efetiva, alvo inválido ignorado e papel não-super_admin ignorado.
- [ ] 2.3 Implementar Policies/Gates de Account, Plan, Client, Invitation e AuditLog por papel + Account; teste por nível cobre permitido e negado de cada papel conforme `specs/roles`.
- [ ] 2.4 Implementar `GET /api/me` com usuário, Account, Plan, papel e estado do seletor; teste cobre 401 sem sessão e payload correto autenticado.

## 3. Autenticação

- [ ] 3.1 Configurar Fortify headless (login, logout, recuperação/redefinição de senha, rate limiting, respostas JSON) e Sanctum stateful com o domínio do BFF; testes cobrem login válido/inválido genérico, logout, reset válido e token expirado conforme `specs/authentication`.
- [ ] 3.2 Testes de integração do contrato de sessão do BFF: requisição autenticada, 401 após logout e rejeição de CSRF inválido; evidência no `php artisan test`.

## 4. Onboarding e convites

- [ ] 4.1 Implementar `GET /api/onboarding/status` e `POST /api/onboarding` transacional (Account A + super_admin + sessão); testes cobrem base vazia criando A e base populada negando.
- [ ] 4.2 Implementar `InvitationService::invite` (token hash de uso único, 7 dias, papel válido, e-mail único, convite pendente único e limite de usuários); testes cobrem cada negação e o convite válido.
- [ ] 4.3 Implementar `InvitationService::accept` e revogação, com notificação por e-mail (`MAIL_MAILER=array` nos testes); testes cobrem aceite válido, expirado, já usado, limite no aceite e revogação.
- [ ] 4.4 Implementar `AccountProvisioningService` e `GET/POST /api/accounts` (somente super_admin; nasce perfil B no Plan padrão + convite do admin inicial); testes cobrem criação completa e negação fora da A.

## 5. Plans e limites

- [ ] 5.1 Implementar `PlanLimitService` (usuários contando convites pendentes válidos, exclusão do próprio convite no aceite, Clients, módulos e volume) com `ValidationException` de upgrade; testes cobrem limite exato, estouro e convites expirados ignorados.
- [ ] 5.2 Implementar `GET/POST/PATCH /api/plans` e `PATCH /api/accounts/{account}/plan` restritos à A, com efeito imediato; testes cobrem catálogo, criação de Plan, troca pela A e negação para admin de B.

## 6. Clients

- [ ] 6.1 Implementar CRUD `GET/POST/PATCH/DELETE /api/clients` com busca/paginação, validação de CNPJ, regime e contador, unique por Account e limite do Plan; testes cobrem cadastro completo, inválido, CNPJ repetido na Account, busca, edição, exclusão e isolamento entre Accounts.
- [ ] 6.2 Implementar o toggle de monitoramento por Client; testes cobrem ativação/desativação persistidas e refletidas na listagem conforme `specs/clients`.
- [ ] 6.3 Garantir que operador administra Clients e user é negado; testes de autorização por papel para cada operação.

## 7. Seletor e auditoria

- [ ] 7.1 Implementar `POST/DELETE /api/switch` e o estado de atuação no `/api/me` (somente super_admin, alvo validado, vínculo preservado); testes cobrem entrada, dados escopados à alvo, saída e negação para outros papéis.
- [ ] 7.2 Implementar `AuditService` + `AuditObserver` cobrindo Accounts, Users, Invitations, Plans, Clients e eventos do seletor, com best-effort (falha não quebra a operação); testes cobrem registro de cada grupo e operação concluída com auditoria falhando.
- [ ] 7.3 Implementar `GET /api/audit` com filtros de Account, ator e período, paginação e visibilidade (super_admin tudo, admin própria Account, operador/user negado); testes cobrem cada filtro, cada papel e a ausência de rotas de edição/exclusão.

## 8. Frontend: BFF e sessão

- [ ] 8.1 Implementar server routes do BFF (`/api/auth/login`, `/logout`, `/forgot-password`, `/reset-password`, `/api/me` e proxy autenticado) com cookie selado httpOnly e envs `BACKEND_URL`/`SESSION_SECRET`; verificação com `pnpm typecheck` e smoke manual de login/logout.
- [ ] 8.2 Implementar middleware de rota do Nuxt (redirecionar visitante para login; encaminhar para onboarding quando a base estiver vazia; liberar aceite de convite); verificação com `pnpm typecheck` e smoke manual dos três casos.

## 9. Frontend: shell e páginas

- [ ] 9.1 Adaptar o shell do template com navegação filtrada por papel e composables `useMe`/`usePermissions`; verificação com `pnpm typecheck` e conferência visual dos menus por papel.
- [ ] 9.2 Implementar páginas de login, recuperação e redefinição de senha com validação Zod e mensagens do backend; verificação com `pnpm typecheck` e smoke manual de erro/sucesso.
- [ ] 9.3 Implementar página de onboarding e página de aceite de convite (`/invite/[token]`); verificação com `pnpm typecheck` e smoke manual de convite válido e expirado.
- [ ] 9.4 Implementar área da A: lista/criação de Accounts e catálogo/edição de Plans; verificação com `pnpm typecheck` e smoke manual de criar Account B com convite.
- [ ] 9.5 Implementar carteira de Clients (lista com busca, cadastro, edição, exclusão e toggle de monitoramento); verificação com `pnpm typecheck` e smoke manual do fluxo completo.
- [ ] 9.6 Implementar `AccountSwitcher` com banner persistente "atuando como", saída explícita e página de auditoria com filtros; verificação com `pnpm typecheck` e smoke manual de entrar/sair e ver eventos.

## 10. Verificação final

- [ ] 10.1 Rodar `composer test` no backend e colar a saída como evidência; corrigir qualquer falha de Pint/PHPUnit.
- [ ] 10.2 Rodar `pnpm lint` e `pnpm typecheck` no frontend e colar a saída como evidência.
- [ ] 10.3 Smoke E2E manual em base limpa: onboarding cria A, A cria B com convite, admin da B entra, limites bloqueiam no estouro, super_admin atua via seletor e a auditoria registra tudo; registrar o passo a passo no change.
