## Purpose

Define a criação, os perfis e o vínculo de usuários das Accounts, garantindo isolamento de dados entre a operação principal e os escritórios.

## ADDED Requirements

### Requirement: Perfis de Account

O sistema SHALL suportar dois perfis de Account: A (principal) e B (escritório comum).

#### Scenario: Primeira Account é A

- **WHEN** a plataforma está vazia e o onboarding é concluído
- **THEN** a Account criada SHALL ter perfil A

#### Scenario: Novas Accounts são B

- **WHEN** a Account A cria uma nova Account
- **THEN** a Account criada SHALL ter perfil B

### Requirement: Vínculo usuário-Account

Cada usuário SHALL pertencer a exatamente uma Account.

#### Scenario: Usuário vinculado

- **WHEN** um usuário é criado pelo onboarding ou aceita um convite
- **THEN** ele SHALL estar vinculado a uma única Account

#### Scenario: Sem trânsito entre Accounts

- **WHEN** um usuário autenticado de uma Account tenta acessar dados de outra Account
- **THEN** o acesso SHALL ser negado, exceto via seletor do super_admin

### Requirement: Criação de Accounts pela A

Somente um super_admin da Account A SHALL criar novas Accounts, que nascem ativas no Plan básico e com um admin inicial convidado.

#### Scenario: Criação com admin inicial

- **WHEN** o super_admin informa o nome da nova Account e o e-mail do admin inicial
- **THEN** a Account SHALL nascer com perfil B, ativa no Plan padrão, e um convite de admin SHALL ser enviado ao e-mail informado

#### Scenario: Criação negada fora da A

- **WHEN** um usuário que não seja super_admin tenta criar uma Account
- **THEN** a operação SHALL ser negada

### Requirement: Listagem de Accounts

O super_admin SHALL listar as Accounts da plataforma com nome, perfil e Plan vigente.

#### Scenario: Listagem na área da A

- **WHEN** o super_admin acessa a gestão de Accounts
- **THEN** ele SHALL ver todas as Accounts com seus dados de perfil e Plan vigente

### Requirement: Isolamento de dados por Account

Toda entidade de negócio SHALL pertencer a uma Account, e leituras e escritas SHALL ser escopadas à Account efetiva do usuário.

#### Scenario: Leitura escopada

- **WHEN** um usuário lista Clients, usuários ou qualquer entidade da sua Account
- **THEN** ele SHALL enxergar apenas registros da Account efetiva

#### Scenario: Escrita escopada

- **WHEN** um usuário cria ou altera uma entidade
- **THEN** ela SHALL ser vinculada à Account efetiva, nunca a outra Account
