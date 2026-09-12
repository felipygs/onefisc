## ADDED Requirements

### Requirement: Aba Fiscal no Client

A tela do Client SHALL ter uma aba Fiscal com documentos (tabela com filtros por modelo e direção, busca por chave), estado da última sincronização (hora, novidades, próximo ciclo) e estado do certificado; sem documentos, a aba SHALL exibir estado vazio orientando a subir o certificado.

#### Scenario: Aba com estado visível

WHEN o usuário abre a aba Fiscal THEN ele SHALL ver documentos, última sincronização e validade do certificado em um só lugar.

#### Scenario: Sem certificado

WHEN o Client não tem certificado THEN a aba SHALL exibir orientação para subir o certificado, sem erro.
