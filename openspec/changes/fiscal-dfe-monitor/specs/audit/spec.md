## ADDED Requirements

### Requirement: Auditoria fiscal

O log único SHALL registrar upload, troca e remoção de certificado, cada ciclo de sincronização (com resultado) e cada manifestação (ciência automática ou manual), sempre com ator, Account de origem e Client alvo.

#### Scenario: Ciência automática auditada

WHEN a ciência automática é registrada THEN o log SHALL conter ator do sistema, Account, Client e chave do documento.

#### Scenario: Certificado auditado

WHEN um certificado é trocado THEN o log SHALL conter quem trocou, quando e para qual Client, sem o conteúdo do PFX ou senha.
