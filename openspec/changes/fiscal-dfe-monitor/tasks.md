## 1. Base de dados

- [ ] 1.1 Criar migrations de `client_credentials`, `fiscal_sync_subscriptions` e `fiscal_sync_cursors`, e verificar com `php artisan migrate`
- [ ] 1.2 Criar migrations de `fiscal_documents`, `fiscal_coverage_evidence` e `fiscal_document_actions`, e verificar com `php artisan migrate`
- [ ] 1.3 Criar models com global scope por Account e factories, e verificar com teste de isolamento fiscal entre Accounts

## 2. Certificados

- [ ] 2.1 Implementar upload de A1 por Client (validação PKCS#12, thumbprint, segredo criptografado, só admin), e verificar com testes de válido/expirado/negado por papel
- [ ] 2.2 Implementar senha do portal NFS-e por Client (guardada, nunca reexibida), e verificar com teste de guarda e uso
- [ ] 2.3 Criar telas de certificado com Nuxt UI (upload, validade, avisos), e verificar com `npm run build` + smoke manual

## 3. Sincronização

- [ ] 3.1 Implementar assinaturas + dispatcher (vencidos com lock, fila `fiscal`, 1x/hora com jitter), e verificar com teste de enfileiramento sem duplicar
- [ ] 3.2 Implementar clients NF-e/CT-e (DistDFe incremental, consulta por chave, respeito a 137/656), e verificar com testes de cursor e bloqueio
- [ ] 3.3 Implementar ciência automática 210210 + download no canal NF-e, e verificar com teste de documento pendente de conferência
- [ ] 3.4 Implementar canais NFS-e (ADN primário + portal fallback, cobertura limitada honesta), e verificar com testes de 404 com prova vs ambíguo
- [ ] 3.5 Contar cada documento persistido no volume do Plan com suspensão preservando cursor, e verificar com teste de esgotamento

## 4. Documentos e telas

- [ ] 4.1 Implementar guarda de XML em disco privado + metadados com DANFE/DANFSe (sem DACTE), e verificar com testes de retenção e isolamento
- [ ] 4.2 Criar aba Fiscal no Client com Nuxt UI (tabela, filtros, estado de sincronização, avisos), e verificar com `npm run build` + `npm run types:check` + smoke manual

## 5. Auditoria e validação

- [ ] 5.1 Registrar auditoria de certificado, ciclo e manifestação (sem segredos), e verificar com testes de trilha por Client
- [ ] 5.2 Rodar suíte completa (`php artisan test`, `npm run types:check`, `npm run build`, `npm run check`) e corrigir falhas
- [ ] 5.3 Executar smoke fim a fim: subir A1 → ciclo encontra resumo → ciência auto → XML guardado → DANFE → volume contou → cobertura limitada em município não aderente
