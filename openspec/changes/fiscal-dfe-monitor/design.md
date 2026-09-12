## Context

Ver proposal.md (Why). Pré-requisito: change `multi-account-a-b-planos` com tasks 1.2, 1.3, 2.1 e 2.2 prontas (vínculo usuário-Account, tabelas clients/invitations/audit, isolamento por `account_id`, volume do Plan). Referência de arquitetura: `_legacy/apps/api/app/Contexts/Documents/` (cadeia scheduler→job→dispatcher→clients→storage, tabelas `fiscal_*`, contratos em `_legacy/contracts/fiscal/v1/`). Ver specs em `specs/` para os requisitos.

## Goals / Non-Goals

**Goals:**

- Reaproveitar o padrão do legado (assinaturas por família, cursor de NSU, evidência de cobertura) sem copiar scraping de portal na v1 além do fallback documentado.
- Isolar cada Client fiscalmente: cursor, credencial e documentos nunca cruzam Account.
- Fazer cada documento contar no volume do Plan sem acoplar o fiscal ao faturamento.

**Non-Goals:**

- Emissão, saída automática nacional, NFC-e, DACTE, MDF-e, gateway pago (ver proposal Out-of-Scope).
- Scraper do portal com captcha automático na v1 (fallback portal é manual-assistido ou via sessão válida existente; solver fica para fase seguinte).

## Decisions

**Jobs Laravel por Client e família, fila `fiscal`.** Uma assinatura (`account, client, family, environment`) com `next_run_at` e lock `FOR UPDATE SKIP LOCKED`; dispatcher a cada minuto enfileira o vencido. Alternativa (worker separado) descartada: volume inicial não justifica segunda infra.

**Cursor de NSU por (client, family, environment).** Retomada incremental; 137/656 viram bloqueio até a próxima janela. Alternativa (full scan) descartada: estouraria o throttling SEFAZ.

**Credencial por Client, segredo em vault por Account.** PFX validado na hora (PKCS#12 abre, dentro da validade, thumbprint SHA-256); senha nunca logada nem reexibida. Alternativa (certificado do escritório + procuração) descartada: decisão do grill foi certificado do Client.

**ADN primário + portal fallback para NFS-e, sem municipal.** Segue o legado e a spec: 404 com prova vira `coverage_limited` terminal com evidência; 404 ambíguo vira `unknown`. Alternativa (conector por prefeitura) descartada: centenas de layouts, contra a regra de cobertura honesta.

**Ciência automática 210210 + download, resto manual.** Confirma ADR 0003. Alternativa (tudo manual) travaria a automação; (confirmação auto) criaria efeito fiscal indevido.

**XML em disco privado, metadados no banco.** Retenção permanente enquanto Client ativo. Alternativa (blob no banco) descartada: cresce tabela e backup sem necessidade.

**DANFE via pacote de render, nunca para consulta.** Confirma ADR 0004: pacote de terceiros só gera PDF do XML já guardado.

**Contagem de volume na persistência.** Cada documento persistido soma 1; cursor preservado com volume esgotado. Alternativa (contar consultas) descartada: puniria retry e janela vazia.

## Risks / Trade-offs

- [SEFAZ bloqueia por excesso (656)] → Respeito estrito a 137/656 + jitter entre Clients + limite de páginas por ciclo.
- [Certificado expira e pausa a carteira] → Badge de vencimento + aviso antes de expirar + suspensão só do canal afetado.
- [Portal exige captcha e trava o fallback] → Estado `coverage_limited` com motivo `portal_captcha`, sem retry cego; solver em fase seguinte.
- [XML com dados sensíveis em disco] → Disco privado, sem rota pública, nomes sem CNPJ; auditoria sem conteúdo.
- [Pacote nfephp muda webservice] → Clients finos isolam o pacote; contrato interno estável.

## Migration Plan

1. Migrations: `client_credentials`, `fiscal_sync_subscriptions`, `fiscal_sync_cursors`, `fiscal_documents`, `fiscal_coverage_evidence`, `fiscal_document_actions`, `sync_runs`/`sync_run_items` (ou reaproveitar nomes do legado).
2. Dependências composer + `ext-soap`/`ext-openssl` confirmadas no ambiente.
3. Deploy: migrate, seed de assinaturas só para Clients com credencial, scheduler ativo; rollback por down das migrations (documentos já guardados preservados em disco).

## Open Questions

- Solver de captcha do portal (NoneCap ou similar) entra em qual fase?
- Limite de páginas por ciclo e tamanho do jitter: calibrar com as primeiras carteiras reais.
