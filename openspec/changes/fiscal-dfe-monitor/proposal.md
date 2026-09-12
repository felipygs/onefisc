## Why

Escritórios de contabilidade perdem horas por fechamento cobrando XML de clientes e correm risco de autuação por nota faltante. O buscador fiscal captura NF-e, CT-e e NFS-e automaticamente na SEFAZ, direto na carteira de cada Client, sem depender de envio manual.

## What Changes

- Certificado digital A1 por Client, subido pelo escritório (admin), com PFX e senha criptografados e validade visível.
- Sincronização fiscal automática 1x/hora por Client, retomando do `ultNSU` salvo, sem botão manual.
- Entrada de NF-e via DistDFe com Ciência da Operação automática (documento nasce pendente de conferência).
- Entrada de CT-e via distribuição nacional (fonte AN).
- NFS-e nacional em dois canais: ADN (API com mTLS) primário e portal do Emissor Nacional como fallback; município não aderente vira Cobertura limitada terminal, sem fallback municipal.
- Guarda de XML em disco privado com retenção permanente enquanto o Client ativo; cada Documento Fiscal persistido conta 1 no volume mensal do Plan.
- Aba Fiscal na tela do Client com tabela, filtros, estado da última sincronização e DANFE/DANFSe.
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
- Frontend: aba Fiscal em `clients/Show.vue`, telas de certificado, componentes Nuxt UI conforme catálogo abaixo.
- Banco: novas tabelas via migrations; XML em disco privado (não no banco).
- Compatibilidade: sem quebra; módulo novo atrás de credencial válida por Client.

## UI Catalog (Nuxt UI v4)

Shell padrão `UApp → UDashboardGroup → UDashboardSidebar → UDashboardPanel`.

- `clients/Show.vue` aba Fiscal: `UTabs` (Documentos, Sincronização, Certificado) + `UTable` (chave, modelo `UBadge`, direção, emitente, valor, data) + filtros (`UInput` busca + `USelect` modelo/direção) + `UPagination` + `UAlert` de Cobertura limitada + `UAlert` de certificado expirado.
- `fiscal/Certificates/Create.vue`: `UForm` (`UInput` arquivo PFX + `UInput` senha + `UButton` validar e salvar) + `UAlert` de erro de leitura.
- Estados globais: `UEmpty` (sem documentos), `USkeleton` (carregando), `UBadge` de validade do certificado, `UToast` via `useToast`.
