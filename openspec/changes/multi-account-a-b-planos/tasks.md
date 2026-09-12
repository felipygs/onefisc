## 1. Base de dados

- [x] 1.1 Criar migrations de `accounts` (profile A/B), `plans` (limites + is_default) e seed dos 3 Plans com básico padrão, e verificar com `php artisan migrate --seed`
- [x] 1.2 Adicionar `account_id` (nullable) + `role` (enum super_admin/admin/operador/user, default `user`) em `users` sem backfill (bases de dev são recriadas), e verificar com `php artisan migrate` e teste de vínculo
- [x] 1.3 Criar migrations de `clients` (account_id, CNPJ, razão social, regime, contador), `invitations` (token hash, expiração) e `audit_logs`, e verificar com `php artisan migrate`

## 2. Domínio e autorização

- [x] 2.1 Criar models Account, Plan, Client, Invitation, AuditLog com global scope por Account, e verificar com teste de isolamento (uma Account não enxerga dados da outra)
- [x] 2.2 Implementar middleware de contexto de Account (usuário ou alvo do seletor) + compartilhamento via Inertia, e verificar com teste de contexto efetivo
- [x] 2.3 Implementar Gates/Policies por nível (super_admin/admin/operador/user) conforme specs/roles, e verificar com testes de permissão por papel

## 3. Onboarding e convites

- [x] 3.1 Implementar onboarding (base vazia cria Account A + super_admin) e bloqueio do `/register` com base populada, e verificar com testes dos dois cenários
- [x] 3.2 Implementar convites (nome, e-mail, papel, expiração 7 dias, senha no aceite) e criação de Account pela A com admin inicial, e verificar com testes de aceite válido/expirado
- [x] 3.3 Criar páginas Inertia de onboarding e aceite de convite com Nuxt UI, e verificar com `npm run build` + smoke manual

## 4. Plans e limites

- [ ] 4.1 Implementar services de checagem de limite (usuários, Clients, módulos, volume) no ponto de criação/uso, e verificar com testes de bloqueio ao estourar
- [ ] 4.2 Implementar troca de Plan só pela A e área de gestão do catálogo restrita ao super_admin, e verificar com testes de permissão + smoke da tela
- [ ] 4.3 Exibir aviso de limite com ação de upgrade nas telas afetadas, e verificar com `npm run build` + `npm run types:check`

## 5. Carteira de Clients

- [ ] 5.1 Implementar CRUD de Clients com validação dos 4 campos e isolamento por Account, e verificar com testes de CRUD + isolamento + CNPJ repetido entre Accounts
- [ ] 5.2 Implementar monitoramento agendado por Client com contagem no volume do Plan, e verificar com teste de suspensão ao esgotar volume
- [ ] 5.3 Criar páginas Inertia da carteira com Nuxt UI, e verificar com `npm run build` + smoke manual

## 6. Seletor e auditoria

- [ ] 6.1 Implementar seletor de Accounts (sessão switch_account_id, só super_admin, banner + saída explícita), e verificar com testes de acesso negado por papel e retorno à origem
- [ ] 6.2 Implementar auditoria via observer (ator, origem, alvo, ação, momento) cobrindo plataforma e operação, e verificar com testes de registro nos eventos-chave
- [ ] 6.3 Criar telas de seletor e consulta de auditoria com filtros por Account/ator, e verificar com `npm run build` + smoke manual

## 7. Validação final

- [ ] 7.1 Rodar suíte completa (`php artisan test`, `npm run types:check`, `npm run build`, `npm run check`) e corrigir falhas
- [ ] 7.2 Executar smoke fim a fim: onboarding → criar B → convidar admin → carteira → trocar Plan → seletor → auditoria
