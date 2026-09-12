# audit

## Purpose

Define o log único de auditoria cobrindo ações de plataforma e da operação, com filtros por Account e ator.

## ADDED Requirements

### Requirement: Log único

Toda ação relevante de plataforma (criar Account, trocar Plan, gerenciar Plans, uso do seletor) e da operação (usuários, tarefas, documentos, monitoramentos) SHALL gerar um registro com ator, Account de origem, Account alvo quando aplicável, ação e momento.

#### Scenario: Registro de plataforma

WHEN uma Account é criada ou um Plan é trocado THEN o evento SHALL constar no log com todos os campos.

#### Scenario: Registro de operação

WHEN um usuário é convidado ou uma tarefa é concluída THEN o evento SHALL constar no log com todos os campos.

### Requirement: Consulta com filtros

Usuários autorizados SHALL consultar a auditoria filtrando por Account e por ator.

#### Scenario: Filtro por Account

WHEN um super_admin filtra por uma Account THEN apenas eventos daquela Account SHALL ser listados.

#### Scenario: Restrição por papel

WHEN um admin consulta a auditoria THEN ele SHALL ver apenas eventos da sua Account.
