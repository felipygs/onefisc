## ADDED Requirements

### Requirement: Aba Fiscal no Client

A tela do Client SHALL ter uma aba Fiscal com sub-tabs Documentos, Sincronização e Certificado, dentro do `AppSidebarLayout` (sem shell novo). Documentos reutiliza a tabela avançada e os filtros globais; Sincronização é leitura pura do estado da última execução (hora, novidades, próximo ciclo, bloqueio, volume); Certificado usa rail com badge de validade + modais de upload (sem página separada). Sem documentos, a aba SHALL exibir estado vazio orientando a subir o certificado.

#### Scenario: Aba com estado visível

WHEN o usuário abre a aba Fiscal THEN ele SHALL ver documentos, última sincronização e validade do certificado em um só lugar.

#### Scenario: Sem certificado

WHEN o Client não tem certificado THEN a aba SHALL exibir orientação para subir o certificado, sem erro.
