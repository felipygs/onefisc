## 1. Fundação

- [x] 1.1 Aplicar a identidade visual do template ao shell e verificar via screenshots de Dashboard, Clients e Settings sem regressão nas telas de autenticação.
- [x] 1.2 Disponibilizar o estado compartilhado do shell (notificações e atalhos) e verificar abrindo e fechando notificações por botão e atalho e navegando por atalho fora de campos de texto.

## 2. Shell global

- [x] 2.1 Exibir a barra lateral idêntica com Home, Clients, Settings, Feedback e Help e verificar navegação entre seções com indicação atual e modo recolhido por ícones.
- [x] 2.2 Oferecer a busca global com destinos, Clients e ações e verificar indo a Clients pela busca com o teclado.
- [x] 2.3 Exibir as notificações em painel lateral e verificar abertura pelo sino e pelo atalho e navegação ao item relacionado sem perder o contexto.
- [x] 2.4 Fundir o Seletor de Accounts e o usuário real nos menus do shell e verificar atuação auditada do super_admin em outra Account e troca de tema com persistência.
- [x] 2.5 Exibir o aviso de limite de Plan e o consentimento de cookies e verificar bloqueio de criação no limite e lembrança da escolha de cookies.

## 3. Home

- [x] 3.1 Exibir a Home com estatísticas, gráfico, recentes, intervalo e período e verificar atualização conjunta dos três blocos ao trocar o intervalo.
- [x] 3.2 Oferecer a criação rápida de Client na Home e verificar abertura da criação na Account atual.

## 4. Clients

- [x] 4.1 Exibir a tabela de Clients com filtros, ordenação, seleção, paginação e colunas e verificar filtro por e-mail e por status restritos à Account atual.
- [x] 4.2 Criar e excluir Client com confirmação e ações por linha e verificar criação válida, cópia de identificador e exclusão com contagem e confirmação.

## 5. Settings

- [x] 5.1 Reorganizar os ajustes em General, Members, Notifications e Security e verificar navegação com contexto e atualização de perfil com confirmação.
- [x] 5.2 Gerir membros via Convite com expiração e verificar convite válido por 7 dias, aceite com definição de senha e recusa de token expirado.

## 6. Remoção e gate

- [x] 6.1 Remover o shell legado das rotas logadas preservando autenticação, Onboarding e Convite e verificar ausência de referências legadas e build verde.
- [x] 6.2 Rodar o gate completo e o checklist por papel e verificar tipos, lint, suíte Pest e isolamento da carteira por Account sem regressão.
