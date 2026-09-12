## Context

Ver proposal.md (Why). Estado atual: Laravel 13 com starter kit Vue (Inertia 3, Fortify, Pest), `users` sem vínculo de Account, `/register` público aberto, sem Plans, Clients ou auditoria. Banco SQLite em dev. Ver specs em `specs/` para os requisitos.

## Goals / Non-Goals

**Goals:**

- Isolar dados por Account em todas as leituras e escritas.
- Fechar o registro público e roteá-lo para onboarding (base vazia) ou bloqueio (base populada).
- Dar ao super_admin operação via seletor sem duplicar credenciais.
- Enforçar limites de Plan no ponto de criação/uso, com mensagem acionável.

**Non-Goals:**

- Provedor de pagamento/assinatura (cobrança é manual pela A nesta fase).
- Integração real SERPRO/contador (Clients e monitoramento nascem com contrato de integração, implementação em change futura).
- Módulos de operação (tarefas, comunicações, documentos) além do necessário para permissões.

## Decisions

**Escopo por coluna `account_id` + global scope.** Todas as entidades de negócio carregam `account_id` e um escopo global filtra pelo contexto atual. Alternativa (banco por Account) foi descartada: complexidade operacional sem benefício nesta escala.

**Contexto de Account resolvido por middleware.** Um middleware resolve a Account efetiva (a do usuário, ou a alvo do seletor para super_admin) e a compartilha via `app()->instance('currentAccount')` + props Inertia. Alternativa (parâmetro em cada controller) foi descartada por risco de esquecimento.

**Papéis como enum em `users.role` + Gates/Policies.** Quatro níveis fixos dispensam tabela de permissões; Gates verificam nível e Account. Alternativa (Spatie Permission) foi descartada: granularidade de permissões nomeadas não é necessária agora.

**Seletor via sessão `switch_account_id`.** O super_admin grava a Account alvo na sessão; o middleware usa esse valor quando presente e válido. Alternativa (token separado) foi descartada: sessão já é o mecanismo de auth do Inertia.

**Plan como catálogo em tabela.** `plans` com colunas de limite (max_users, max_clients, módulos em JSON, volume mensal) + flag `is_default`. Checagem de limite em services antes de criar/usar. Alternativa (config em código) foi descartada: a A precisa editar sem deploy.

**Auditoria via observer + tabela `audit_logs`.** Observer genérico registra model events dos agregados cobertos, com ator (user), origem (account do ator), alvo (account efetiva) e metadados. Alternativa (pacote de activity log) foi descartada para manter o esquema sob controle.

**Convites com token hash + expiração.** Tabela `invitations` (account_id, name, email, role, token hash, expires_at). Aceite cria o user e invalida o convite. Alternativa (link mágico sem senha) foi descartada: Fortify já cobre senha + 2FA.

## Risks / Trade-offs

- [Esquecer `account_id` em nova entidade vaza dados] → Migration + teste de isolamento por entidade; global scope como rede de segurança.
- [Seletor esquecido aberto amplia blast radius] → Banner visível "atuando como X", saída explícita, auditoria de cada ação.
- [Limite de Plan checado só no frontend] → Checagem sempre no backend (service), frontend só exibe.
- [Observer de auditoria falha e quebra a escrita] → Observer nunca lança exceção (try/catch + log); auditoria é best-effort, não transacional.

## Migration Plan

1. Migrations: `accounts`, `plans` (+ seed dos 3 Plans com básico padrão), `account_id`+`role` em `users` (colunas nullable/default, sem backfill: não há base legada a preservar; bases de dev existentes são recriadas com `migrate:fresh --seed`), `clients`, `invitations`, `audit_logs`.
2. Deploy: rodar migrate + seed; verificar `/register` bloqueado quando há Accounts.
3. Rollback: migrations reversíveis; feature indisponível até re-migrate (sem perda, tabelas novas).

## Open Questions

- Nenhuma que altere specs, abordagem ou tasks. Detalhes de UI (telas do seletor, layout da área de Plans) serão definidos na implementação com Nuxt UI.
