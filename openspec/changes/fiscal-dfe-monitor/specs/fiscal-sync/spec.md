## Purpose

Mantém a carteira fiscal de cada Client atualizada sozinha, buscando documentos novos na SEFAZ a cada hora sem nenhuma ação manual, respeitando os limites da SEFAZ e do Plan.

## ADDED Requirements

### Requirement: Sincronização agendada por Client

Cada Client com credencial válida SHALL ser sincronizado automaticamente 1x por hora, retomando do último NSU guardado; não há botão de sincronização manual, e a tela SHALL exibir o estado da última execução (hora, documentos novos, próximo ciclo).

#### Scenario: Ciclo com documentos novos

WHEN o ciclo horário encontra documentos novos THEN eles SHALL ser persistidos e o cursor de NSU SHALL avançar.

#### Scenario: Ciclo sem novidade

WHEN o ciclo horário não encontra nada novo THEN o estado SHALL registrar a execução sem erro e manter o cursor.

### Requirement: Sincronização como leitura pura na tela

A tela SHALL exibir capacidade por família, última execução (hora, documentos novos, próximo ciclo), bloqueio SEFAZ e volume esgotado com CTA de upgrade; não há botão de sincronização manual, editor de intervalo/assinatura nem campo de chave em tela na v1.

#### Scenario: Tela sem ação manual

WHEN o usuário abre a sub-tab Sincronização THEN ele SHALL ver somente estado e avisos, sem nenhum botão que dispare ciclo.

### Requirement: Respeito ao bloqueio SEFAZ

WHEN a SEFAZ responde pedindo pausa (nada localizado ou consumo indevido) THEN a sincronização daquele Client SHALL ficar bloqueada até a próxima janela, sem novas tentativas no intervalo.

#### Scenario: Bloqueio respeitado

WHEN ocorre resposta de pausa THEN o estado SHALL mostrar bloqueado até a próxima janela e nenhum novo ciclo SHALL disparar antes disso.

### Requirement: Contagem no volume do Plan

Cada Documento Fiscal persistido SHALL contar 1 no volume mensal do Plan da Account; com volume esgotado, novas persistências SHALL ser suspensas com aviso de upgrade, sem perder o cursor.

#### Scenario: Volume esgotado

WHEN o volume mensal esgota THEN documentos novos SHALL ficar pendentes com aviso até upgrade ou renovação, e o cursor SHALL ser preservado.

### Requirement: Auditoria da sincronização

Cada ciclo SHALL gerar registro de auditoria com Client, canal, documentos novos e resultado; falhas SHALL ser registradas como estado visível, nunca como exceção silenciosa.

#### Scenario: Falha visível

WHEN a SEFAZ está fora do ar THEN a tela SHALL exibir a falha com a hora e a próxima tentativa, com registro em auditoria.
