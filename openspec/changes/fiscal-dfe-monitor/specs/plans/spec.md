## ADDED Requirements

### Requirement: Volume conta Documentos Fiscais

O volume mensal do Plan SHALL contar cada Documento Fiscal persistido como 1 unidade de consumo, somado às consultas de monitoramento já previstas; com volume esgotado, novas persistências fiscais SHALL ser suspensas com aviso de upgrade.

#### Scenario: Documento consome volume

WHEN um Documento Fiscal é persistido THEN o consumo mensal da Account SHALL aumentar em 1.

#### Scenario: Suspensão com aviso

WHEN o volume esgota THEN a persistência fiscal SHALL pausar com aviso acionável de upgrade, sem perder cursor nem documentos já guardados.
