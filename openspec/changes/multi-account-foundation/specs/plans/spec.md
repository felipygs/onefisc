## Purpose

Define o catálogo de Plans de assinatura, seus limites e as regras de troca e de estouro para as Accounts.

## ADDED Requirements

### Requirement: Catálogo de Plans

A Account A SHALL gerenciar o catálogo de Plans, que SHALL iniciar com 3 planos: Básico marcado como padrão, Intermediário e Avançado.

#### Scenario: Catálogo inicial

- **WHEN** o sistema é instalado
- **THEN** o catálogo SHALL conter os 3 planos com o Básico marcado como padrão

#### Scenario: Novo Plan

- **WHEN** o super_admin cria um Plan com nome, preço e limites
- **THEN** ele SHALL ficar disponível para atribuição às Accounts

#### Scenario: Plan padrão

- **WHEN** uma nova Account é criada
- **THEN** ela SHALL receber o Plan marcado como padrão

### Requirement: Dimensões limitadas

Cada Plan SHALL limitar número de usuários, número de Clients, módulos liberados e volume mensal de consultas.

#### Scenario: Limite respeitado

- **WHEN** uma Account dentro dos limites cria usuário, Client ou usa módulo liberado
- **THEN** a operação SHALL ser permitida

#### Scenario: Convites pendentes contam como usuários

- **WHEN** a soma de usuários ativos e convites pendentes válidos atinge o limite de usuários do Plan
- **THEN** novos convites SHALL ser bloqueados

#### Scenario: Convites expirados não contam

- **WHEN** existem convites expirados na Account
- **THEN** eles SHALL NOT contar para o limite de usuários

#### Scenario: Aceite no limite exato

- **WHEN** o aceite de um convite leva a Account ao limite de usuários, excluindo o próprio convite da contagem
- **THEN** o aceite SHALL ser permitido

### Requirement: Estouro de limite

Quando uma operação excederia qualquer dimensão do Plan, ela SHALL ser bloqueada com aviso indicando a necessidade de upgrade.

#### Scenario: Limite estourado

- **WHEN** criar usuário, Client ou usar módulo excederia o Plan vigente
- **THEN** a operação SHALL ser bloqueada com aviso de upgrade

#### Scenario: Verificação no backend

- **WHEN** a operação é solicitada diretamente ao backend, ignorando o frontend
- **THEN** o bloqueio SHALL ocorrer da mesma forma

### Requirement: Módulos liberados

O acesso a um módulo SHALL depender de o Plan vigente liberá-lo.

#### Scenario: Módulo liberado

- **WHEN** um usuário acessa um módulo presente no Plan da sua Account
- **THEN** o acesso SHALL ser permitido conforme seu papel

#### Scenario: Módulo bloqueado

- **WHEN** um usuário acessa um módulo ausente do Plan da sua Account
- **THEN** o acesso SHALL ser negado com aviso de upgrade

### Requirement: Troca de Plan pela A

Somente um super_admin da Account A SHALL trocar o Plan de uma Account, com efeito imediato.

#### Scenario: Troca pela A

- **WHEN** o super_admin atribui um Plan a uma Account
- **THEN** os novos limites SHALL valer imediatamente para a Account

#### Scenario: Troca negada fora da A

- **WHEN** um admin de Account B tenta trocar o próprio Plan
- **THEN** a operação SHALL ser negada
