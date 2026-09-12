# onboarding-invites

## Purpose

Define a entrada na plataforma: onboarding da primeira Account, fechamento do registro público e convites de usuários.

## ADDED Requirements

### Requirement: Onboarding da primeira Account

Com a base vazia, o primeiro cadastro SHALL criar a Account A com seu usuário super_admin.

#### Scenario: Base vazia vira onboarding

WHEN a plataforma não possui nenhuma Account e alguém conclui o cadastro inicial THEN uma Account A SHALL ser criada com o cadastrante como super_admin.

#### Scenario: Sem onboarding com base populada

WHEN já existe ao menos uma Account THEN o fluxo de onboarding SHALL estar indisponível.

### Requirement: Registro público fechado

O auto-cadastro público SHALL estar desabilitado após o onboarding.

#### Scenario: Registro bloqueado

WHEN um visitante tenta acessar o registro público com Accounts existentes THEN ele SHALL ser impedido, com orientação a solicitar convite.

### Requirement: Convites

Novos usuários SHALL entrar por convite do admin, com nome, e-mail e papel, expiração em 7 dias e definição de senha no aceite.

#### Scenario: Aceite válido

WHEN um convidado com convite válido define sua senha THEN a conta SHALL ser ativada vinculada à Account do convite com o papel informado.

#### Scenario: Convite expirado

WHEN um convidado tenta aceitar um convite com mais de 7 dias THEN o aceite SHALL ser negado e um novo convite SHALL ser necessário.
