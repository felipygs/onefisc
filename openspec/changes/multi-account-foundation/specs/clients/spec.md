## Purpose

Define a carteira de Clients (CNPJs) isolada por Account, seus dados cadastrais e o estado de monitoramento por Client.

## ADDED Requirements

### Requirement: Carteira isolada por Account

Cada Account SHALL possuir sua própria carteira de Clients; o mesmo CNPJ MAY existir em carteiras distintas sem compartilhar dados.

#### Scenario: CNPJ repetido entre Accounts

- **WHEN** duas Accounts cadastram o mesmo CNPJ
- **THEN** cada uma SHALL enxergar apenas o seu registro, sem compartilhamento

#### Scenario: Isolamento de acesso

- **WHEN** um usuário tenta visualizar, editar ou excluir um Client de outra Account
- **THEN** o acesso SHALL ser negado, exceto via seletor do super_admin

### Requirement: Dados do Client

Cada Client SHALL conter CNPJ válido, razão social, regime tributário e contador responsável.

#### Scenario: Cadastro completo

- **WHEN** um admin ou operador cadastra um Client com os quatro dados, sendo o CNPJ válido
- **THEN** ele SHALL ficar ativo na carteira da Account

#### Scenario: Cadastro incompleto ou CNPJ inválido

- **WHEN** falta qualquer um dos quatro dados ou o CNPJ é inválido
- **THEN** o cadastro SHALL ser rejeitado com indicação do campo

#### Scenario: CNPJ repetido na mesma Account

- **WHEN** a Account tenta cadastrar um CNPJ já existente na própria carteira
- **THEN** o cadastro SHALL ser negado

### Requirement: Gestão da carteira

O admin e o operador SHALL listar, buscar, cadastrar, editar e excluir Clients da sua Account, respeitando os limites do Plan.

#### Scenario: Busca e listagem

- **WHEN** um admin ou operador lista a carteira, podendo filtrar por busca, razão social ou regime
- **THEN** ele SHALL ver apenas Clients da Account efetiva, de forma paginada

#### Scenario: Edição

- **WHEN** um admin ou operador edita um Client da sua Account
- **THEN** as alterações SHALL ser persistidas

#### Scenario: Exclusão

- **WHEN** um admin ou operador exclui um Client da sua Account
- **THEN** o registro SHALL ser removido e o evento SHALL constar na auditoria

### Requirement: Estado de monitoramento por Client

Cada Client SHALL ter um estado de monitoramento configurável, exibido na carteira. A execução de consultas via SERPRO e o consumo de volume ficam para a integração fiscal de change futuro.

#### Scenario: Monitoramento ativado

- **WHEN** um admin ou operador ativa o monitoramento de um Client
- **THEN** o estado SHALL ser persistido e exibido como ativo na carteira

#### Scenario: Monitoramento desativado

- **WHEN** um admin ou operador desativa o monitoramento de um Client
- **THEN** o estado SHALL ser persistido e exibido como inativo na carteira
