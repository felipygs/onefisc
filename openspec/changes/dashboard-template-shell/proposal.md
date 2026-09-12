## Why

A área logada ainda usa o shell legado (sidebar/header/breadcrumbs) divergente do padrão Nuxt UI já adotado nas telas de domínio, o que duplica a linguagem visual e trava a evolução do produto. O template oficial `nuxt-ui-templates/dashboard` define o padrão esperado e o change `migrate-nuxt-ui` deixou explicitamente a adoção do shell `UDashboard*` como follow-up.

## What Changes

- Adota o shell do template como layout global da área logada: sidebar colapsável e redimensionável, botão e paleta de busca, navbar e toolbar por painel, slideover de notificações, atalhos de teclado e aviso de cookies, preservando rotas, papéis (admin, operador, user, super_admin) e permissões por Account.
- Funde o Seletor de Accounts do super_admin no `TeamsMenu` do template (listar Accounts, criar e gerenciar) e o usuário real no `UserMenu` (perfil, cobrança/Plan, ajustes, tema, documentação, sair); o aviso de limite de Plan passa a aparecer no topo do painel.
- Transforma o Dashboard na Home do template (cartões de estatísticas, gráfico, vendas recentes, seletor de intervalo e período) com conteúdo inicial ilustrativo e ligação futura aos dados de Client e Documentos Fiscais.
- Reestiliza o índice de Clients no padrão da tabela Customers do template (busca por e-mail, filtro por status, ordenação, seleção, paginação, visibilidade de colunas, modais de adicionar e excluir) sobre os dados reais da carteira de cada Account (CNPJ, razão social, regime tributário, contador responsável).
- Reorganiza os ajustes no layout Settings do template (sub-navegação General, Members, Notifications, Security) reaproveitando as telas existentes de perfil, senha, passkeys, 2FA, aparência e gestão de usuários via Convite.
- **BREAKING**: remove o shell legado (`AppSidebar`, `AppHeader`, `NavMain`, `NavFooter`, `NavUser`, `Breadcrumbs`, `PlaceholderPattern` e layouts `app/*`) das rotas logadas; `AuthLayout`, Onboarding e aceite de Convite permanecem inalterados.

## Capabilities

### New Capabilities

- `frontend/dashboard-shell`: shell idêntico ao template para a área logada, incluindo sidebar, busca, notificações, atalhos e fusão do Seletor de Accounts e do usuário real.
- `frontend/dashboard-pages`: páginas Dashboard (Home), Clients e Settings no padrão visual e de interação do template, sobre rotas e dados reais do produto.

### Modified Capabilities

- Nenhuma (sem spec anterior em `openspec/specs/`; comportamento de Account, Plan, Client, Onboarding e Convite preservado).

## Impact

- Frontend: `resources/js/layouts/*`, `resources/js/components/*`, `resources/js/pages/Dashboard.vue`, `resources/js/pages/clients/*`, `resources/js/pages/settings/*`, `resources/js/composables/*`, `resources/css/app.css`, `package.json` (libs de data e gráfico do template).
- Backend: nenhum contrato novo obrigatório; agregações da Home trafegam como props do Inertia (podem nascer ilustrativas).
- Dependência: assume o catálogo único Nuxt UI do change `migrate-nuxt-ui`; sem ele o shell volta a misturar dois sistemas.

## Out-of-Scope

- Copiar a página Inbox demo do template (sem equivalente no domínio fiscal; notificações vivem no slideover e na futura central de auditoria).
- Ligar a Home a dados fiscais reais (receita, pedidos, sincronização fiscal 1x/hora por Client); esta change aceita conteúdo ilustrativo com contrato de props.
- Alterar rotas, controllers, policies, limites de Plan, fluxo de Onboarding, Convite (expira em 7 dias), Manifestação do Destinatário, Certificado digital, DANFE ou cobertura de NFS-e nacional.
- Trocar a fonte global ou o tema de cores além do necessário para fidelidade (primary green, neutral zinc do template).
