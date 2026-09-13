## Purpose

Define o log único de auditoria cobrindo ações de plataforma e da operação, com filtros por Account, ator e período.

## ADDED Requirements

### Requirement: Log único

Toda ação relevante de plataforma e da operação SHALL gerar um registro com ator, Account de origem, Account alvo quando aplicável, ação, alvo, momento e metadados.

#### Scenario: Registro de plataforma

- **WHEN** uma Account é criada, um Plan é trocado ou o seletor é usado
- **THEN** o evento SHALL constar no log com todos os campos

#### Scenario: Registro de operação

- **WHEN** um usuário é convidado, um papel é alterado ou um Client é criado, editado ou excluído
- **THEN** o evento SHALL constar no log com todos os campos

#### Scenario: Cobertura mínima desta fase

- **WHEN** o sistema registra eventos nesta fase
- **THEN** a cobertura SHALL incluir Accounts, usuários, convites, Plans, Clients e uso do seletor

### Requirement: Registro best-effort

A falha ao registrar um evento SHALL NOT impedir a operação principal.

#### Scenario: Falha de auditoria

- **WHEN** o registro de auditoria falha durante uma operação válida
- **THEN** a operação SHALL ser concluída e a falha SHALL ser registrada em log técnico

### Requirement: Consulta com filtros

Usuários autorizados SHALL consultar a auditoria filtrando por Account, ator e período, com resultado paginado.

#### Scenario: Filtro por Account

- **WHEN** o super_admin filtra por uma Account
- **THEN** apenas eventos daquela Account SHALL ser listados

#### Scenario: Filtro por ator e período

- **WHEN** o super_admin filtra por ator e intervalo de datas
- **THEN** apenas os eventos correspondentes SHALL ser listados

### Requirement: Visibilidade da auditoria

O super_admin SHALL ver eventos de todas as Accounts; o admin SHALL ver apenas eventos da sua Account; operador e user SHALL NOT acessar a auditoria.

#### Scenario: Visão do super_admin

- **WHEN** o super_admin acessa a auditoria
- **THEN** ele SHALL ver eventos de qualquer Account

#### Scenario: Visão restrita do admin

- **WHEN** um admin acessa a auditoria
- **THEN** ele SHALL ver apenas eventos da sua Account

#### Scenario: Acesso negado

- **WHEN** um operador ou user tenta acessar a auditoria
- **THEN** o acesso SHALL ser negado

### Requirement: Imutabilidade

O registro de auditoria SHALL NOT ser editável ou removível pela aplicação.

#### Scenario: Alteração bloqueada

- **WHEN** qualquer usuário tenta editar ou excluir um evento de auditoria
- **THEN** a operação SHALL ser negada
