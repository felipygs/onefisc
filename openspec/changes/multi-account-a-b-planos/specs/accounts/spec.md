# accounts

## Purpose

Define a criação, os perfis e o vínculo de usuários das Accounts, garantindo isolamento de dados entre a operação principal e os escritórios.

## ADDED Requirements

### Requirement: Perfis de Account

O sistema SHALL suportar dois perfis de Account: A (principal) e B (escritório comum).

#### Scenario: Primeira Account é A

WHEN a plataforma está vazia e o onboarding é concluído THEN a Account criada SHALL ter perfil A.

#### Scenario: Novas Accounts são B

WHEN a Account A cria uma nova Account THEN a Account criada SHALL ter perfil B.

### Requirement: Vínculo usuário-Account

Cada usuário SHALL pertencer a exatamente uma Account.

#### Scenario: Usuário vinculado

WHEN um usuário é criado ou aceita um convite THEN ele SHALL estar vinculado a uma única Account.

#### Scenario: Sem trânsito entre Accounts

WHEN um usuário autenticado de uma Account B tenta acessar dados de outra Account THEN o acesso SHALL ser negado, exceto via seletor do super_admin.

### Requirement: Criação de Accounts pela A

Somente a Account A SHALL criar novas Accounts, que nascem com o Plan básico e um admin inicial convidado.

#### Scenario: Criação com admin inicial

WHEN a Account A cria uma Account informando nome, e-mail do admin inicial THEN a Account SHALL nascer ativa no Plan básico e o convite SHALL ser enviado ao e-mail informado.

#### Scenario: Criação negada fora da A

WHEN um usuário que não seja super_admin tenta criar uma Account THEN a operação SHALL ser negada.
