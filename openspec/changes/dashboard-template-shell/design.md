## Context

Ver `proposal.md` (Why). Estado atual: plugin Nuxt UI v4 ativo (`vite.config.ts` com `ui({ router: 'inertia' })`, `app.use(ui)` em `app.ts`, `@import '@nuxt/ui'` em `app.css`, `UApp` no `AppLayout`), telas de domínio já em Nuxt UI e shell legado em shadcn (`AppSidebar`, `AppHeader`, `Nav*`, `Breadcrumbs`, layouts `app/*`). O change `migrate-nuxt-ui` unifica o catálogo e deixa o shell `UDashboard*` como follow-up. O template de referência é `nuxt-ui-templates/dashboard/app` (layout `default.vue`, composable `useDashboard`, páginas Home/Customers/Settings, componentes Teams/User/Search/Notifications). Restrições: sem mudar rotas, controllers, policies ou o vocabulário de Account, Plan, Client, super_admin, Onboarding e Convite.

## Goals / Non-Goals

**Goals:**

- Shell global idêntico ao template para a área logada, com busca, notificações, atalhos e fusão do Seletor de Accounts e do usuário real.
- Home, Clients e Settings no padrão de interação do template sobre dados e permissões reais.
- Dependências de data e gráfico resolvidas sem regressão no gate (`types:check`, `check`, Pint, PHPStan, Pest).

**Non-Goals:**

- Copiar a página Inbox demo; ligar a Home a agregações fiscais reais; redesenhar fluxos de Onboarding, Convite, certificados ou manifestações.

## Decisions

- **Shell em `UDashboard*` via plugin Vue com roteador Inertia em vez de manter o shadcn**: o plugin e os componentes de Dashboard já estão instalados (`@nuxt/ui` v4.11.1 com `Dashboard*` no runtime) e o Vite já está configurado para Inertia, logo a fidelidade vem sem trocar de stack. Alternativa descartada: maquiagem do shadcn atual — nunca atinge fidelidade e mantém duas linguagens de UI.
- **Composable de shell compartilhado adaptado do `useDashboard`**: estado único para o slideover de notificações, atalhos (Home, Clients, Settings, notificações) ignorados com foco em campos e fechamento ao trocar de página lendo a página atual do Inertia em vez da rota do Nuxt. Alternativa descartada: copiar o composable do Nuxt verbatim — quebraria fora do Nuxt (`useRoute`, `useRouter`, `defineShortcuts`).
- **Navegação com componentes de link do Inertia e ícones Lucide locais**: substitui `NuxtLink`/`NuxtPage` e auto-imports do Nuxt por equivalentes explícitos, mantendo os mesmos destinos do template mapeados para as rotas existentes. Alternativa descartada: camada de compat Nuxt — acoplamento artificial a APIs inexistentes no produto.
- **TeamsMenu ligado ao Seletor de Accounts real e UserMenu ao usuário real**: o menu de equipes lista as Accounts visíveis ao super_admin (criar, gerenciar, retornar à Account A) e o menu do usuário concentra perfil, Plan, ajustes, seletores de cor primária/neutra, aparência e saída. Alternativa descartada: copiar os mocks do template (Nuxt/NuxtHub/NuxtLabs, Benjamin Canac) — violaria isolamento e auditoria.
- **Tema alinhado ao `app.config` do template (primary green, neutral zinc) com ajuste mínimo em `app.css`**: aplica a escala de verdes e o neutro do template e resolve o conflito com as variáveis legadas do shadcn removendo-as no lote do shell. Alternativa descartada: reescrever todo o CSS de uma vez — risco de regressão em auth e telas já migradas.
- **Tabela de Clients sobre o padrão Customers com paginação client-side inicial**: filtros, ordenação, seleção, visibilidade de colunas e modais vindos do template, operando sobre a coleção da Account carregada via props; paginação de servidor fica como evolução sem mudar o contrato visual. Alternativa descartada: paginação de servidor já neste change — acoplaria backend e atrasaria a fidelidade.
- **Dependências do template adicionadas (`date-fns`, `@internationalized/date`, `@unovis/vue`) com gráfico isolado**: o seletor de intervalo e o gráfico exigem essas libs; o gráfico nasce isolado com fallback sem dados para não travar o gate nem o bundle inicial. Alternativa descartada: reimplementar calendário e gráfico próprios — desvia do idêntico e cria manutenção paralela.

## Risks / Trade-offs

- [Risk] Componentes de Dashboard comportarem-se diferente fora do Nuxt (SSR, auto-imports, `resolveComponent`) → Mitigation: registro explícito de componentes, links via Inertia e verificação com `npm run build` + `types:check` no lote do shell.
- [Risk] Conflito entre tokens legados do shadcn e do Nuxt UI (sidebar, radius, dark mode) → Mitigation: limpeza do `app.css` restrita ao lote do shell, variante dark única e screenshots de Dashboard, Clients e Settings antes e depois.
- [Risk] Gráfico aumentar o bundle ou quebrar sem JS/SSR → Mitigation: carregamento isolado do bloco de gráfico com fallback estático e sem travar o restante da Home.
- [Risk] Atalhos dispararem durante digitação → Mitigation: atalhos inativos com foco em campos editáveis, cobertos por verificação manual no shell.
- [Risk] Regressão de isolamento por Account ou de papéis ao fundir os menus → Mitigation: checklist por papel (super_admin, admin, operador, user) mais suíte Pest de Accounts, Clients e Convites no lote final.
- [Trade-off] Conteúdo ilustrativo inicial na Home em troca de fidelidade imediata; a ligação fiscal real fica fora do escopo sem mudar os contratos visuais.

## Migration Plan

1. Lotes: (a) fundação (tema, libs, composable de shell), (b) shell global com busca, notificações, Teams/User e cookies, (c) Home, (d) Clients, (e) Settings, (f) remoção do shell legado e gate completo.
2. Cada lote: testes Pest das áreas tocadas, `npm run types:check`, `npm run check`, screenshots das telas do lote.
3. Rollback: reverter o lote (commits por lote, escopo só frontend salvo props ilustrativas da Home); nenhuma migration de banco envolvida.

## Open Questions

- Nenhuma que trave o plano; a fonte final (Public Sans do template vs. Instrument Sans atual) será decidida no lote de fundação sem alterar specs, abordagem ou tarefas.
