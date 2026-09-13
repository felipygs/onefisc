## 1. Base de dados

- [x] 1.1 Criar migrations de `client_credentials`, `fiscal_sync_subscriptions` e `fiscal_sync_cursors`, e verificar com `php artisan migrate`
- [x] 1.2 Criar migrations de `fiscal_documents`, `fiscal_coverage_evidence` e `fiscal_document_actions`, e verificar com `php artisan migrate`
- [x] 1.3 Criar models com global scope por Account e factories, e verificar com teste de isolamento fiscal entre Accounts

## 2. Certificados

- [x] 2.1 Implementar upload de A1 por Client (validação PKCS#12, thumbprint, segredo criptografado, só admin), e verificar com testes de válido/expirado/negado por papel
- [x] 2.2 Implementar senha do portal NFS-e por Client (guardada, nunca reexibida), e verificar com teste de guarda e uso
- [x] 2.3 Criar certificado em rail + modais com Nuxt UI (rail com badge de validade, UploadModal PFX+senha, PortalPasswordModal, alertas de erro junto da ação, só admin), e verificar com `npm run build` + smoke manual

## 3. Sincronização

- [x] 3.1 Implementar assinaturas + dispatcher (vencidos com lock, fila `fiscal`, 1x/hora com jitter), e verificar com teste de enfileiramento sem duplicar
- [x] 3.2 Implementar clients NF-e/CT-e (DistDFe incremental, consulta por chave, respeito a 137/656), e verificar com testes de cursor e bloqueio
- [x] 3.3 Implementar ciência automática 210210 + download no canal NF-e, e verificar com teste de documento pendente de conferência
- [x] 3.4 Implementar canais NFS-e (ADN primário + portal fallback, cobertura limitada honesta), e verificar com testes de 404 com prova vs ambíguo
- [x] 3.5 Contar cada documento persistido no volume do Plan com suspensão preservando cursor, e verificar com teste de esgotamento

## 4. Documentos e telas

- [x] 4.1 Implementar guarda de XML em disco privado + metadados com DANFE/DANFSe (sem DACTE), e verificar com testes de retenção e isolamento
- [x] 4.2 Criar páginas do portfólio com Nuxt UI (`documents/Index` com tabs Visão/Mercadorias/Serviços/Operação + cards + gráfico + famílias + rankings + recentes + atenção; `documents/All` global; `documents/Clients` atenção da carteira), e verificar com `npm run build` + `npm run types:check` + smoke manual
- [x] 4.3 Criar tabela avançada + filtros (`DocumentsTable` em `UTable` com ordenação e ações por linha, `FiltersToolbar` com busca `/` + popover Tipo/Status/Origem + Exibição, `UPagination` via query-string, sem seleção em massa), e verificar com `npm run types:check` + smoke manual
- [x] 4.4 Criar overlays de documento (`DetailSlideover` com seções/copiar chave + `DanfeModal` com preview via URL assinada + download XML), e verificar com `npm run build` + smoke manual
- [x] 4.5 Criar aba Fiscal no Client (`clients/Show.vue` com sub-tabs Documentos/Sincronização leitura pura/Certificado em rail), e verificar com `npm run build` + `npm run types:check` + smoke manual

## 5. Auditoria e validação

- [x] 5.1 Registrar auditoria de certificado, ciclo e manifestação (sem segredos), e verificar com testes de trilha por Client
- [x] 5.2 Rodar suíte completa (`php artisan test`, `npm run types:check`, `npm run build`, `npm run check`) e corrigir falhas
- [x] 5.3 Executar smoke fim a fim: subir A1 → ciclo encontra resumo → ciência auto → XML guardado → DANFE → volume contou → cobertura limitada em município não aderente
