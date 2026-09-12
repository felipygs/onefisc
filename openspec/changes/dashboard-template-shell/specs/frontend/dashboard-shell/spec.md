## Purpose

Define o shell idêntico ao template para toda a área logada: navegação lateral, busca global, notificações e identidade da Account e do usuário, preservando rotas, papéis e o Seletor de Accounts do super_admin.

## ADDED Requirements

### Requirement: Navegação lateral idêntica ao template

O sistema SHALL exibir em todas as telas logadas uma barra lateral com as seções Home, Clients e Settings (com filhas General, Members, Notifications e Security) mais Feedback e Help, indicando a seção atual e permitindo recolher e redimensionar a barra sem perder a navegação.

#### Scenario: Navegar entre seções pela barra lateral

- **WHEN** o usuário autenticado aciona Clients na barra lateral
- **THEN** o sistema exibe a carteira de Clients da Account atual com a seção Clients indicada como atual

#### Scenario: Recolher a barra lateral

- **WHEN** o usuário recolhe a barra lateral
- **THEN** o sistema mantém a navegação acessível por ícones com dicas e preserva o estado ao trocar de seção

### Requirement: Busca global por paleta

O sistema SHALL oferecer uma busca global acionável por botão e teclado que sugere destinos (Go to), Clients da carteira e ações de código/documentação, levando ao destino escolhido ao confirmar.

#### Scenario: Ir para Clients pela busca

- **WHEN** o usuário abre a busca, digita "Clients" e confirma o destino
- **THEN** o sistema exibe a carteira de Clients da Account atual

### Requirement: Central de notificações em slideover

O sistema SHALL exibir as notificações num painel lateral aberto pelo botão de sino ou atalho, listando remetente, texto e tempo relativo, permitindo abrir o item relacionado e fechar sem perder o contexto da página.

#### Scenario: Abrir notificação relacionada

- **WHEN** o usuário abre o painel de notificações e aciona uma notificação
- **THEN** o sistema exibe o item relacionado e marca o contexto corretamente

### Requirement: Atalhos de teclado do shell

O sistema SHALL suportar atalhos para ir a Home, Clients e Settings e para abrir ou fechar as notificações, sem conflitar com a digitação em campos.

#### Scenario: Atalho para Home

- **WHEN** o usuário pressiona o atalho da Home fora de um campo de texto
- **THEN** o sistema exibe a Home da Account atual

### Requirement: Seletor de Accounts fundido ao menu de equipes

O sistema SHALL exibir no topo da barra lateral a Account atual e, para o super_admin, a lista de Accounts para atuação com poderes de plataforma sob auditoria, permitindo criar Account, gerenciar Accounts e retornar à Account A.

#### Scenario: super_admin atua em outra Account

- **WHEN** o super_admin seleciona uma Account B no menu de equipes
- **THEN** o sistema passa a exibir os dados isolados daquela Account com indicação de atuação auditada até o retorno à Account A

### Requirement: Menu do usuário com tema e sessão

O sistema SHALL exibir no rodapé da barra lateral o usuário autenticado com acesso a perfil, cobrança (Plan), ajustes, escolha de cor primária e neutra, aparência clara ou escura, documentação, repositório e saída da sessão.

#### Scenario: Trocar aparência para escura

- **WHEN** o usuário escolhe a aparência escura no menu
- **THEN** o sistema aplica o tema escuro em todo o shell e o mantém na navegação seguinte

### Requirement: Aviso de limite de Plan no painel

O sistema SHALL exibir no topo do conteúdo o aviso de limite de Plan estourado (usuários, Clients, módulos ou volume), bloqueando a criação correspondente até a troca de Plan pela Account A.

#### Scenario: Carteira no limite bloqueia novo Client

- **WHEN** a Account atinge o limite de Clients do Plan e tenta criar outro
- **THEN** o sistema bloqueia a criação e exibe o aviso de limite com orientação de troca de Plan

### Requirement: Consentimento de cookies lembrado

O sistema SHALL exibir o aviso de cookies de primeira parte até o aceite ou dispensa, lembrando a escolha nas visitas seguintes.

#### Scenario: Aceite de cookies dispensa o aviso

- **WHEN** o visitante aceita os cookies
- **THEN** o sistema oculta o aviso e não o reexibe nas visitas seguintes do mesmo navegador

### Requirement: Permissões e isolamento preservados no shell

O sistema SHALL exigir autenticação para o shell, isolar a carteira de Clients por Account e respeitar os papéis admin, operador, user e o nível super_admin restrito à Account A, sem expor dados entre Accounts.

#### Scenario: Visitante não autenticado não acessa o shell

- **WHEN** um visitante não autenticado tenta abrir o Dashboard
- **THEN** o sistema redireciona para a autenticação em vez de exibir o shell
