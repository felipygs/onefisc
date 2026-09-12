# plans

## Purpose

Define o catálogo de Plans de assinatura, seus limites e as regras de troca e estouro.

## ADDED Requirements

### Requirement: Catálogo de Plans

A Account A SHALL gerenciar o catálogo de Plans, com um Plan básico marcado como padrão.

#### Scenario: Novo Plan

WHEN a Account A cria um Plan com nome, preço e limites THEN ele SHALL ficar disponível para atribuição.

#### Scenario: Plan padrão

WHEN uma nova Account é criada THEN ela SHALL receber o Plan marcado como padrão (básico).

### Requirement: Dimensões limitadas

Cada Plan SHALL limitar número de usuários, número de Clients, módulos liberados e volume de consultas.

#### Scenario: Limite respeitado

WHEN uma Account dentro dos limites cria usuário, Client ou usa módulo liberado THEN a operação SHALL ser permitida.

#### Scenario: Limite estourado

WHEN uma operação excederia qualquer limite do Plan THEN ela SHALL ser bloqueada com aviso indicando a necessidade de upgrade.

### Requirement: Troca de Plan pela A

Somente a Account A SHALL trocar o Plan de uma Account.

#### Scenario: Upgrade pela A

WHEN a Account A atribui um Plan superior a uma Account THEN os novos limites SHALL valer imediatamente.

#### Scenario: Troca negada fora da A

WHEN um admin de Account B tenta trocar o próprio Plan THEN a operação SHALL ser negada.
