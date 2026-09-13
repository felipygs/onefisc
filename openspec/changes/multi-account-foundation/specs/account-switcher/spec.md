## Purpose

Define o seletor de Accounts do super_admin para configuração, suporte e manutenção nas demais Accounts, sem trocar de credenciais.

## ADDED Requirements

### Requirement: Seletor exclusivo do super_admin

Somente usuários super_admin SHALL usar o seletor de Accounts.

#### Scenario: Acesso ao seletor

- **WHEN** um super_admin lista as Accounts no seletor
- **THEN** ele SHALL poder selecionar qualquer Account para atuar

#### Scenario: Seletor negado

- **WHEN** um admin, operador ou user tenta usar o seletor
- **THEN** a operação SHALL ser negada

#### Scenario: Seletor invisível

- **WHEN** um admin, operador ou user acessa o shell
- **THEN** o seletor SHALL NOT aparecer na interface

### Requirement: Atuação na Account alvo

Atuando via seletor, o super_admin SHALL ter poderes de plataforma na Account alvo, com as ações registradas em nome dessa Account.

#### Scenario: Ação via seletor

- **WHEN** o super_admin via seletor cria usuário, troca Plan ou ajusta configuração na Account alvo
- **THEN** a ação SHALL ser executada no contexto da Account alvo, constando o ator e a Account alvo na auditoria

#### Scenario: Dados escopados à alvo

- **WHEN** o super_admin atua via seletor
- **THEN** listagens e operações SHALL enxergar os dados da Account alvo, não os da Account A

#### Scenario: Retorno à origem

- **WHEN** o super_admin encerra a atuação via seletor
- **THEN** ele SHALL voltar ao contexto da Account A

### Requirement: Sinalização de atuação

Enquanto estiver atuando via seletor, o sistema SHALL exibir sinalização persistente identificando a Account alvo, com ação explícita de saída.

#### Scenario: Sinalização visível

- **WHEN** o super_admin está atuando em outra Account
- **THEN** um aviso persistente "atuando como {Account}" SHALL estar visível com botão de sair

### Requirement: Vínculo preservado

O uso do seletor SHALL NOT alterar o vínculo do usuário nem suas credenciais; o super_admin SHALL permanecer vinculado à Account A.

#### Scenario: Vínculo inalterado

- **WHEN** o super_admin usa o seletor
- **THEN** seu usuário SHALL continuar vinculado à Account A e autenticado com as mesmas credenciais

### Requirement: Auditoria do seletor

Toda entrada, ação e saída via seletor SHALL ser registrada com ator, Account de origem, Account alvo, ação e momento.

#### Scenario: Trilha auditável

- **WHEN** o super_admin entra, age e sai de uma Account alvo
- **THEN** cada evento SHALL constar na auditoria com ator, origem, alvo, ação e momento
