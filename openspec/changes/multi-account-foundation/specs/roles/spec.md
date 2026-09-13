## Purpose

Define os quatro níveis de usuário, suas fronteiras de permissão dentro de cada Account e a visibilidade correspondente na navegação.

## ADDED Requirements

### Requirement: Níveis de usuário

O sistema SHALL suportar os níveis super_admin, admin, operador e user.

#### Scenario: super_admin só na A

- **WHEN** um usuário com nível super_admin existe
- **THEN** ele SHALL pertencer à Account A

#### Scenario: B sem super_admin

- **WHEN** uma Account B é criada
- **THEN** ela SHALL conter apenas admin, operador e user, nunca super_admin

### Requirement: Permissões do admin

O admin SHALL gerenciar tudo dentro da sua Account, exceto ações de plataforma (criar Accounts, gerenciar Plans e usar o seletor).

#### Scenario: Admin gerencia a própria Account

- **WHEN** um admin convida, altera papel ou remove um usuário, ou administra a carteira de Clients da sua Account
- **THEN** a operação SHALL ser permitida

#### Scenario: Admin sem plataforma

- **WHEN** um admin tenta criar uma Account, editar o catálogo de Plans ou usar o seletor
- **THEN** a operação SHALL ser negada

### Requirement: Permissões do operador

O operador SHALL atuar sobre a operação da sua Account e executar trabalho como um user, mas SHALL NOT gerenciar usuários, Plans, integrações ou configurações.

#### Scenario: Operador opera

- **WHEN** um operador cria, edita ou consulta Clients e demais dados operacionais da sua Account
- **THEN** a operação SHALL ser permitida

#### Scenario: Operador sem administração

- **WHEN** um operador tenta convidar usuários, alterar papéis ou mudar configurações da Account
- **THEN** a operação SHALL ser negada

### Requirement: Permissões do user

O user SHALL executar apenas o trabalho que lhe é atribuído nos módulos liberados, sem configurar nada.

#### Scenario: User sem gestão

- **WHEN** um user tenta criar, editar ou excluir Clients, convidar usuários ou alterar configurações
- **THEN** a operação SHALL ser negada

#### Scenario: User restrito ao atribuído

- **WHEN** um user acessa um recurso que lhe foi atribuído
- **THEN** o acesso SHALL ser permitido apenas dentro do escopo atribuído

### Requirement: Promoção a super_admin

Somente um super_admin SHALL criar ou promover outro super_admin, sempre dentro da Account A.

#### Scenario: Promoção válida

- **WHEN** um super_admin promove um usuário da Account A a super_admin
- **THEN** o nível SHALL ser atualizado

#### Scenario: Promoção inválida

- **WHEN** um admin tenta promover qualquer usuário a super_admin, ou alguém tenta promover um usuário de uma Account B
- **THEN** a operação SHALL ser negada

### Requirement: Navegação por nível

A navegação SHALL exibir apenas os itens e ações acessíveis ao nível do usuário autenticado.

#### Scenario: Navegação da A

- **WHEN** um super_admin acessa o shell
- **THEN** ele SHALL ver os grupos de plataforma, incluindo a gestão de Accounts e de Plans

#### Scenario: Navegação sem plataforma

- **WHEN** um admin, operador ou user acessa o shell
- **THEN** a gestão de Accounts, de Plans e o seletor SHALL NOT aparecer

#### Scenario: Navegação mínima do user

- **WHEN** um user acessa o shell
- **THEN** ele SHALL ver apenas os itens correspondentes ao trabalho atribuído
