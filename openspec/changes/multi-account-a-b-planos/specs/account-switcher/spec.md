# account-switcher

## Purpose

Define o seletor de Accounts do super_admin para configuração, suporte e manutenção nas demais Accounts.

## ADDED Requirements

### Requirement: Seletor exclusivo do super_admin

Somente usuários super_admin SHALL usar o seletor de Accounts.

#### Scenario: Acesso ao seletor

WHEN um super_admin lista as Accounts THEN ele SHALL poder selecionar qualquer uma para atuar.

#### Scenario: Seletor negado

WHEN um admin, operador ou user tenta usar o seletor THEN a operação SHALL ser negada.

### Requirement: Poderes via seletor

Atuando via seletor, o super_admin SHALL ter poderes de plataforma na Account alvo, identificado como super_admin.

#### Scenario: Ação via seletor

WHEN um super_admin via seletor cria usuário, troca Plan ou ajusta configuração na Account alvo THEN a ação SHALL ser executada em nome da Account alvo com identificação do ator.

#### Scenario: Retorno à origem

WHEN o super_admin encerra a sessão via seletor THEN ele SHALL voltar ao contexto da Account A.

### Requirement: Auditoria do seletor

Toda entrada, ação e saída via seletor SHALL ser registrada com ator, Account de origem e Account alvo.

#### Scenario: Trilha auditável

WHEN um super_admin age via seletor THEN cada evento SHALL conter ator, origem, alvo, ação e momento.
