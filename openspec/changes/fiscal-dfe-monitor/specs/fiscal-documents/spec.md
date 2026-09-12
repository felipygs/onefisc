## Purpose

Guarda e apresenta os Documentos Fiscais de cada Client (NF-e, CT-e, NFS-e nacional) com isolamento total por Account, cobertura nacional honesta e PDF auxiliar quando disponível.

## ADDED Requirements

### Requirement: Guarda isolada por Account

Cada Documento Fiscal SHALL pertencer a um Client de uma Account e ser visível somente dentro dela (exceto super_admin via Seletor de Accounts); o mesmo documento em Accounts distintas SHALL NOT compartilhar dados.

#### Scenario: Isolamento entre escritórios

WHEN um usuário tenta ver documento de outra Account THEN o acesso SHALL ser negado, exceto via Seletor de Accounts.

### Requirement: Entrada de NF-e com ciência automática

Todo resumo de NF-e recebido SHALL gerar Ciência da Operação automática para liberar o XML completo; o documento SHALL nascer marcado como pendente de conferência; Confirmação da Operação, Desconhecimento e Operação não Realizada SHALL permanecer manuais.

#### Scenario: Ciência libera XML

WHEN um resumo chega THEN a ciência SHALL ser registrada e o XML completo SHALL ser buscado no ciclo seguinte.

#### Scenario: Sem confirmação automática

WHEN um documento entra por ciência automática THEN ele SHALL NOT ser confirmado fiscalmente sem ação humana.

### Requirement: Entrada de CT-e

Cada Client SHALL ter seus CT-e de entrada buscados na distribuição nacional, retomando do último NSU próprio do canal CT-e.

#### Scenario: CT-e novo

WHEN um CT-e novo aparece na distribuição THEN ele SHALL ser persistido no Client com chave, emitente, valor e data.

### Requirement: NFS-e nacional em dois canais

A busca de NFS-e SHALL usar o ADN como canal primário e o portal do Emissor Nacional como fallback; município não aderente SHALL gerar Cobertura limitada terminal com evidência registrada, sem tentar provedor municipal.

#### Scenario: Cobertura limitada honesta

WHEN o município não aderiu ao padrão nacional THEN a tela SHALL exibir Cobertura limitada com o motivo, a evidência SHALL ser registrada, e nenhuma integração municipal SHALL ser tentada.

#### Scenario: Resposta ambígua

WHEN a resposta não prova falta de cobertura THEN o estado SHALL ser desconhecido, sem afirmar que o município não é suportado.

### Requirement: Retenção e DANFE

O XML SHALL ser guardado enquanto o Client estiver ativo; DANFE SHALL estar disponível para NF-e e DANFSe para NFS-e, sem DACTE na v1.

#### Scenario: Documento antigo acessível

WHEN um documento saiu da janela de 3 meses da SEFAZ THEN ele SHALL continuar acessível no sistema enquanto o Client estiver ativo.

### Requirement: Consulta com filtros

Usuários autorizados SHALL listar documentos do Client filtrando por modelo e direção, com busca por chave de acesso.

#### Scenario: Filtro por modelo

WHEN o usuário filtra por CT-e THEN somente CT-e do Client SHALL ser listado.
