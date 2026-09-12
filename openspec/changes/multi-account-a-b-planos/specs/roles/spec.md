# roles

## Purpose

Define os quatro níveis de usuário e suas fronteiras de permissão dentro de cada Account e na plataforma.

## ADDED Requirements

### Requirement: Níveis de usuário

O sistema SHALL suportar os níveis super_admin, admin, operador e user.

#### Scenario: super_admin só na A

WHEN um usuário com nível super_admin existe THEN ele SHALL pertencer à Account A.

#### Scenario: B sem super_admin

WHEN uma Account B é criada THEN ela SHALL conter apenas admin, operador e user, nunca super_admin.

### Requirement: Permissões do admin

O admin SHALL gerenciar tudo dentro da sua Account, exceto ações de plataforma (criar Accounts, gerenciar Plans).

#### Scenario: Admin gerencia usuários

WHEN um admin convida, altera papel ou remove um usuário da sua Account THEN a operação SHALL ser permitida.

#### Scenario: Admin sem plataforma

WHEN um admin de qualquer Account tenta criar uma Account ou editar o catálogo de Plans THEN a operação SHALL ser negada.

### Requirement: Permissões do operador

O operador SHALL atuar sobre todos os módulos de trabalho da sua Account e executar tarefas como um user, mas SHALL NOT gerenciar usuários, integrações ou configurações.

#### Scenario: Operador opera e executa

WHEN um operador cria, distribui ou executa tarefas, monitoramentos, atendimentos ou documentos THEN a operação SHALL ser permitida.

#### Scenario: Operador sem administração

WHEN um operador tenta convidar usuários ou alterar integrações e configurações THEN a operação SHALL ser negada.

### Requirement: Permissões do user

O user SHALL executar apenas o trabalho que lhe é atribuído, sem configurar nada.

#### Scenario: User executa atribuído

WHEN um user atua em tarefa, atendimento ou documento atribuído a ele THEN a operação SHALL ser permitida.

#### Scenario: User sem gestão

WHEN um user tenta criar tarefas, distribuir trabalho ou alterar configurações THEN a operação SHALL ser negada.

### Requirement: Promoção a super_admin

Somente um super_admin SHALL criar ou promover outro super_admin, sempre dentro da Account A.

#### Scenario: Promoção válida

WHEN um super_admin promove um usuário da Account A a super_admin THEN o nível SHALL ser atualizado.

#### Scenario: Promoção inválida

WHEN um admin tenta promover qualquer usuário a super_admin THEN a operação SHALL ser negada.
