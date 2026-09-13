## Purpose

Define a entrada na plataforma: onboarding da primeira Account e convites de usuários com expiração e senha no aceite.

## ADDED Requirements

### Requirement: Onboarding da primeira Account

Com a base vazia, o cadastro inicial SHALL criar a Account A com seu usuário super_admin e autenticá-lo.

#### Scenario: Base vazia vira onboarding

- **WHEN** a plataforma não possui nenhuma Account e alguém conclui o cadastro inicial com nome, e-mail e senha
- **THEN** uma Account A SHALL ser criada com o cadastrante como super_admin autenticado

#### Scenario: Sem onboarding com base populada

- **WHEN** já existe ao menos uma Account e um visitante tenta acessar o onboarding
- **THEN** o fluxo SHALL estar indisponível e o sistema SHALL orientar a entrada por convite

### Requirement: Convite de usuário

Um admin SHALL convidar usuários informando nome, e-mail e papel, com expiração em 7 dias e definição de senha no aceite. O convite SHALL registrar quem convidou e a qual Account pertence.

#### Scenario: Convite criado

- **WHEN** um admin convida alguém com nome, e-mail e papel válidos dentro dos limites do Plan
- **THEN** um convite pendente SHALL ser criado com token de uso único e expiração em 7 dias, e o e-mail SHALL ser enviado

#### Scenario: Convite duplicado

- **WHEN** já existe convite pendente para o mesmo e-mail na Account
- **THEN** um novo convite SHALL ser negado

#### Scenario: E-mail já pertencente à plataforma

- **WHEN** o e-mail informado já pertence a um usuário de qualquer Account
- **THEN** o convite SHALL ser negado

#### Scenario: Papel inválido

- **WHEN** um admin tenta convidar para super_admin, ou alguém tenta convidar para papel fora de admin, operador e user
- **THEN** o convite SHALL ser negado

### Requirement: Aceite de convite

O aceite SHALL criar o usuário vinculado à Account do convite com o papel informado, definir a senha e invalidar o convite.

#### Scenario: Aceite válido

- **WHEN** um convidado com convite válido define uma senha conforme a política
- **THEN** sua conta SHALL ser ativada vinculada à Account do convite com o papel informado, e o convite SHALL ser invalidado

#### Scenario: Convite expirado

- **WHEN** um convidado tenta aceitar um convite com mais de 7 dias
- **THEN** o aceite SHALL ser negado e um novo convite SHALL ser necessário

#### Scenario: Convite já usado

- **WHEN** um convidado tenta aceitar um convite já aceito ou revogado
- **THEN** o aceite SHALL ser negado

### Requirement: Gestão de convites pendentes

O admin SHALL listar e revogar convites pendentes da sua Account.

#### Scenario: Revogação

- **WHEN** um admin revoga um convite pendente
- **THEN** o convite SHALL deixar de ser aceitável

#### Scenario: Convites de outra Account

- **WHEN** um admin lista convites
- **THEN** ele SHALL ver apenas convites da sua Account
