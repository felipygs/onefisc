## Why

A revisão geral encontrou contratos centrais marcados como concluídos sem estarem operacionais, além de lacunas de auditoria, concorrência, confiabilidade e verdade dos dados apresentados. Esta mudança estabiliza a fundação antes de ampliar o módulo fiscal ou arquivar as mudanças existentes.

## What Changes

- Corrige o Seletor de Accounts para que o `super_admin` atue na Account alvo com os poderes previstos, mantendo origem, alvo e ator na auditoria.
- Completa os fluxos HTTP e de interface para criar Account B, convidar seu admin inicial e promover outro `super_admin` somente dentro da Account A.
- Torna Onboarding, definição do Plan padrão e limites de usuários, Clients e volume seguros contra concorrência.
- Fortalece a validação de CNPJ e as restrições de integridade para perfil de Account e papel de usuário.
- Garante auditoria para operações em lote e alinha os códigos de evento consumidos pelas notificações.
- Substitui o monitoramento síncrono e silencioso por despacho horário, isolado por Client, idempotente e com falha visível e auditável.
- Remove dados financeiros ilustrativos apresentados como reais, torna estados de demonstração explícitos e substitui a página pública padrão do Laravel por uma entrada coerente com o produto.
- Torna busca e filtros de Clients server-side e válidos para toda a carteira, com controles acessíveis e comportamento responsivo.
- Restaura o gate oficial: PHPStan dentro do limite configurado, formatação integral, migration aplicada, testes PostgreSQL no CI e auditoria de dependências.

## Capabilities

### New Capabilities

- `platform-stability`: autorização via Seletor de Accounts, provisionamento administrativo, invariantes de domínio e limites seguros sob concorrência.
- `operational-reliability`: auditoria completa, monitoramento horário por Client, execução idempotente e falhas observáveis.
- `frontend-product-integrity`: dados confiáveis ou explicitamente demonstrativos, busca global da carteira, acessibilidade e identidade pública do produto.

### Modified Capabilities

- Nenhuma; a biblioteca principal ainda não possui specs arquivadas, e esta mudança consolida requisitos corretivos sobre capacidades ainda ativas.

## Impact

- Backend: gates/policies, middleware de contexto, controllers, Form Requests, services/actions, jobs, scheduler, observers e migrations.
- Frontend: shell, Dashboard, Welcome, tabela e busca de Clients, páginas administrativas e componentes interativos.
- Qualidade: Pest, testes frontend, PHPStan, Vite Plus, GitHub Actions, PostgreSQL e auditorias Composer/npm.
- OpenSpec: nova mudança de remediação; `multi-account-a-b-planos` não deve ser arquivada antes deste trabalho.

## Out-of-Scope

- Implementar as integrações SEFAZ, ADN, portal NFS-e, certificados A1, Documento Fiscal ou DANFE planejados em `fiscal-dfe-monitor`.
- Criar métricas financeiras, faturamento ou vendas reais; o Dashboard deve usar apenas dados existentes no domínio ou estados explicitamente vazios/demonstrativos.
- Trocar Laravel, Inertia, Vue, Nuxt UI, PostgreSQL ou o modelo Account A/B.
