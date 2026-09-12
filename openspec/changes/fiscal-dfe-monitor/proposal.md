## Why

Escritórios de contabilidade perdem horas por fechamento cobrando XML de clientes e correm risco de autuação por nota faltante. O buscador fiscal captura NF-e, CT-e e NFS-e automaticamente na SEFAZ, direto na carteira de cada Client, sem depender de envio manual.

## What Changes

- Certificado digital A1 por Client, subido pelo escritório (admin), com PFX e senha criptografados e validade visível.
- Sincronização fiscal automática 1x/hora por Client, retomando do `ultNSU` salvo, sem botão manual.
- Entrada de NF-e via DistDFe com Ciência da Operação automática (documento nasce pendente de conferência).
- Entrada de CT-e via distribuição nacional (fonte AN).
- NFS-e nacional em dois canais: ADN (API com mTLS) primário e portal do Emissor Nacional como fallback; município não aderente vira Cobertura limitada terminal, sem fallback municipal.
- Guarda de XML em disco privado com retenção permanente enquanto o Client ativo; cada Documento Fiscal persistido conta 1 no volume mensal do Plan.
- Aba Fiscal na tela do Client com tabela avançada, filtros, estado da última sincronização (leitura pura, sem botão manual), DANFE/DANFSe e certificado em rail + modais.
- Visão portfólio em `/documents` (painel com tabs Visão/Mercadorias/Serviços/Operação), tabela global em `/documents/all` e atenção da carteira em `/documents/clients`, no estilo do `_legacy` adaptado ao `AppSidebarLayout`.
- Auditoria de uploads de certificado, sincronizações e manifestações.

## Out-of-Scope

- Emissão de qualquer documento fiscal (só busca, consulta e guarda).
- Saída de NF-e/CT-e (standby para fase seguinte; DistDFe não entrega documento próprio do emitente).
- NFC-e (fora da v1; sem DistDFe nacional).
- DACTE, MDF-e, NFS-e municipal, eSocial, EFD.
- Gateway fiscal pago (operação direta na SEFAZ).

## Capabilities

### New Capabilities

- `fiscal-certificates`: upload, validade e guarda de certificado A1 por Client, e senha do portal NFS-e.
- `fiscal-sync`: agendamento 1x/hora por Client, cursor de NSU, respeito a bloqueio SEFAZ, contagem no volume do Plan.
- `fiscal-documents`: guarda e consulta de Documento Fiscal (NF-e, CT-e, NFS-e) isolada por Account, com Cobertura limitada e DANFE/DANFSe.

### Modified Capabilities

- `plans`: volume mensal passa a contar cada Documento Fiscal persistido (dimensão nova de consumo do limite).
- `clients`: tela do Client ganha aba Fiscal com documentos, estado de sincronização e certificado.
- `audit`: log passa a cobrir upload de certificado, sincronização fiscal e manifestação.

## Impact

- Backend: models ClientCredential, FiscalSyncSubscription, FiscalDocument, FiscalCoverageEvidence; jobs na fila `fiscal`; clients HTTP (sped-nfe, sped-cte, ADN); migrations novas; dependências `nfephp-org/sped-nfe`, `nfephp-org/sped-cte`, pacote de render DANFSe.
- Frontend: páginas `documents/Index|All|Clients.vue`, aba Fiscal em `clients/Show.vue`, componentes `documents/*` (tabela, filtros, slideover, modais, painel) sobre Inertia props, conforme catálogo abaixo.
- Banco: novas tabelas via migrations; XML em disco privado (não no banco).
- Compatibilidade: sem quebra; módulo novo atrás de credencial válida por Client.

## UI Catalog (Nuxt UI v4 + Inertia, estilo `_legacy` adaptado)

Shell `AppSidebarLayout` (sem `UDashboardGroup`, sem API JSON nova); breadcrumbs via layout; rotas Wayfinder, nunca hardcode; filtros/ordenação/paginação via `router.get` com query-string (padrão do `clients/Index` atual).

- `documents/Index.vue` (portfólio): `UTabs` (Visão, Mercadorias, Serviços, Operação) + `PanelCards` (`UPageCard`) + gráfico `@unovis/vue` + `PanelFamilies` + `PanelRank` (leaders/chart/detailed) + `PanelRecent` + `PanelAttention` (motivo→badge: falha/quarentena/cobertura limitada = error, sem-certificado/vencendo/XML pendente = warning).
- `documents/All.vue` + `documents/Clients.vue`: `FiltersToolbar` (`UInput` busca com atalho `/` + popover Tipo/Status/Origem + menu Exibição) + `DocumentsTable` (`UTable`: Documento com badge de família + número/série + chave mono, Emissão, Emitente/Prestador, Destinatário/Tomador, Status, Ações em dropdown) + `UPagination`.
- `clients/Show.vue` aba Fiscal: sub-tabs Documentos (reutiliza tabela + filtros + `DetailSlideover` + `DanfeModal` com preview em iframe), Sincronização (leitura pura: última execução, documentos novos, próximo ciclo, bloqueio SEFAZ, volume — sem botão manual, sem editor de assinatura) e Certificado (rail `CredentialsRailCard` + `ValidityBadge` + modais de upload PFX e senha do portal, só admin).
- Downloads (XML e DANFE/DANFSe) via URL assinada curta do backend, sem expor referência interna.
- Estados globais: `UEmpty` (sem documentos, orienta subir certificado), `USkeleton` (carregando), `UAlert` de cobertura limitada e de certificado expirado, `UToast` via `useToast`; tudo com `data-test`.
