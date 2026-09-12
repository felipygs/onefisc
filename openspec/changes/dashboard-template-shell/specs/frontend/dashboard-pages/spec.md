## Purpose

Define as páginas Dashboard (Home), Clients e Settings no padrão visual e de interação do template, sobre as rotas e os dados reais do produto e o vocabulário de Account, Plan e Client.

## ADDED Requirements

### Requirement: Home com visão do período

A Home SHALL exibir cartões de estatísticas com variação percentual, gráfico com total do período e lista de vendas ou movimentações recentes, com seletor de intervalo de datas e de período (diário, semanal, mensal); trocar intervalo ou período SHALL atualizar os três blocos juntos.

#### Scenario: Trocar o intervalo atualiza a Home

- **WHEN** o usuário altera o intervalo para os últimos 30 dias na Home
- **THEN** o sistema exibe estatísticas, gráfico e recentes recalculados para esse intervalo

#### Scenario: Atalhos de intervalo rápido

- **WHEN** o usuário escolhe um intervalo pronto como últimos 7 dias
- **THEN** o sistema aplica o intervalo e indica a seleção ativa

### Requirement: Ações rápidas da Home

A Home SHALL oferecer criação rápida de Client e atalho para a fila de trabalho, levando aos destinos corretos.

#### Scenario: Novo Client a partir da Home

- **WHEN** o usuário aciona Novo Client na Home
- **THEN** o sistema abre a criação de Client da Account atual

### Requirement: Tabela de Clients no padrão Customers

O índice de Clients SHALL exibir tabela com seleção por linha, coluna de identificação com avatar e razão social, CNPJ, regime da carteira com selo e contador responsável, além de menu de ações por linha, com contagem de selecionados, paginação e controle de colunas visíveis.

#### Scenario: Filtrar Clients por CNPJ ou razão social

- **WHEN** o usuário digita parte de um CNPJ, razão social ou contador no filtro da tabela de Clients
- **THEN** o sistema exibe somente os Clients da Account atual cuja correspondência existe

#### Scenario: Filtrar Clients por status

- **WHEN** o usuário filtra por um status da carteira
- **THEN** o sistema exibe somente os Clients da Account atual naquele status

#### Scenario: Exclusão em massa com confirmação

- **WHEN** o usuário seleciona Clients e confirma a exclusão no diálogo
- **THEN** o sistema exclui somente os selecionados da Account atual e exibe confirmação com a contagem

### Requirement: Adicionar e excluir Client

A criação de Client SHALL exigir CNPJ, razão social, regime tributário e contador responsável, com certificado digital quando exigido para consultas, e a exclusão SHALL exigir confirmação explícita.

#### Scenario: Criar Client válido

- **WHEN** o admin informa CNPJ, razão social, regime tributário e contador responsável válidos
- **THEN** o sistema inclui o Client na carteira da Account atual e exibe confirmação

#### Scenario: Excluir Client com confirmação

- **WHEN** o admin confirma a exclusão de um Client
- **THEN** o sistema remove o Client da carteira da Account atual e exibe confirmação

### Requirement: Ações por linha do Client

Cada linha de Client SHALL oferecer copiar o identificador, ver detalhes e excluir, com retorno perceptível de cada ação. Documentos e pagamentos por Client chegam com o monitoramento fiscal e NÃO fazem parte desta change.

#### Scenario: Copiar identificador do Client

- **WHEN** o usuário aciona copiar identificador na linha de um Client
- **THEN** o sistema copia o identificador e exibe confirmação

### Requirement: Isolamento da carteira por Account

A carteira de Clients SHALL ser isolada por Account, permitindo o mesmo CNPJ em Accounts distintas sem compartilhamento de dados ou documentos.

#### Scenario: Mesmo CNPJ em Accounts distintas

- **WHEN** duas Accounts cadastram o mesmo CNPJ
- **THEN** o sistema mantém dois Clients independentes, cada um visível somente em sua Account

### Requirement: Settings com sub-navegação do template

Os ajustes SHALL exibir sub-navegação General, Members, Notifications e Security, preservando o contexto ao navegar entre elas.

#### Scenario: Navegar entre ajustes sem perder contexto

- **WHEN** o usuário vai de General para Members nos ajustes
- **THEN** o sistema mantém o shell e indica Members como seção atual

### Requirement: General e Notifications do usuário

General SHALL permitir atualizar perfil e Security SHALL concentrar senha e sessão; Notifications SHALL permitir ativar ou silenciar categorias de aviso, valendo imediatamente.

#### Scenario: Atualizar perfil com sucesso

- **WHEN** o usuário salva nome válido em General
- **THEN** o sistema persiste, exibe confirmação e mantém o usuário nos ajustes

### Requirement: Members via Convite com expiração

Members SHALL listar usuários da Account com papéis e permitir convidar por nome, e-mail e papel; o Convite SHALL expirar em 7 dias e o convidado define a senha no aceite; somente a Account A convida o admin inicial de cada Account e gerencia o catálogo e a troca de Plan.

#### Scenario: Convidar operador com expiração de 7 dias

- **WHEN** o admin convida um operador por nome, e-mail e papel
- **THEN** o sistema registra o Convite válido por 7 dias e o aceite com token expirado é recusado
