# clients

## Purpose

Define a carteira de Clients (CNPJs) isolada por Account e o monitoramento fiscal agendado por Client.

## ADDED Requirements

### Requirement: Carteira isolada por Account

Cada Account SHALL possuir sua própria carteira de Clients; o mesmo CNPJ MAY existir em carteiras distintas sem compartilhar dados.

#### Scenario: CNPJ repetido entre Accounts

WHEN duas Accounts cadastram o mesmo CNPJ THEN cada uma SHALL enxergar apenas o seu registro, sem compartilhamento.

#### Scenario: Isolamento de acesso

WHEN um usuário tenta visualizar um Client de outra Account THEN o acesso SHALL ser negado, exceto via seletor do super_admin.

### Requirement: Dados do Client

Cada Client SHALL conter CNPJ, razão social, regime tributário e contador responsável.

#### Scenario: Cadastro completo

WHEN um Client é cadastrado com os quatro dados THEN ele SHALL ficar ativo na carteira.

#### Scenario: Cadastro incompleto

WHEN falta qualquer um dos quatro dados THEN o cadastro SHALL ser rejeitado com indicação do campo.

### Requirement: Monitoramento agendado

Cada Client SHALL possuir monitoramento fiscal agendado, e as consultas SHALL contar no volume do Plan da Account.

#### Scenario: Consulta dentro do volume

WHEN uma consulta agendada executa dentro do volume do Plan THEN o resultado SHALL ser registrado no histórico do Client.

#### Scenario: Volume esgotado

WHEN o volume do Plan está esgotado THEN novas consultas SHALL ser suspensas com aviso até upgrade ou renovação.
