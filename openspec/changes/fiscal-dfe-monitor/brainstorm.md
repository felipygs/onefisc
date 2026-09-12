# Brainstorm — Módulo fiscal com nfephp-org (buscador de XML)

Data: 2026-09-12
Origem: pedido de análise de como implementar os outros módulos + integração com https://github.com/nfephp-org
Destino oficial: `openspec-propose` gera proposal/specs/design/tasks. Este arquivo é só descoberta.

## Ideia inicial

Buscador de XML de NF-e, NFC-e e CT-e, tanto entrada como saída, por Client da carteira,
com certificado digital do Client subido pelo escritório e sincronização agendada 1x por hora por Client.

## Perguntas feitas e decisões registradas

1. **Escopo inicial?**
   - Opções: (a) só NFe DistDFe, (b) NFe + manifestação + DANFE, (c) NFe + CTe + MDFe.
   - Decisão do usuário: buscador de XML NF-e + CT-e + NFC-e, entrada e saída.

2. **Como cada Client autoriza a consulta SEFAZ?**
   - Opções: (a) Client sobe A1 próprio, (b) certificado do escritório + procuração, (c) começar mockado.
   - Decisão do usuário: escritório sobe os certificados dos Clients da carteira dele.

3. **Como o monitoramento roda?**
   - Opções: (a) agendado 1x/hora por Client, (b) sob demanda na tela, (c) híbrido.
   - Decisão do usuário: agendado 1x por hora por Client.

## Descobertas técnicas (evidência)

- Org `nfephp-org` tem pacotes separados:
  - `sped-nfe` (1,5k stars, ativo, commit ~20h atrás): NFe modelo 55 + NFC-e 65, geração e comunicação SEFAZ.
  - `sped-cte` (123 stars, ativo): CT-e modelo 57, API própria com distribuição própria e NSU separado.
  - `sped-mdfe`, `sped-da` (DANFE), `sped-common`, `sped-esocial` existem mas ficam fora do escopo inicial.
- `sped-nfe/composer.json` exige: PHP >= 7.4 (ok, projeto usa 8.3), `ext-soap`, `ext-openssl`,
  `ext-dom`, `ext-zlib`, `ext-json`, `ext-simplexml`, `ext-libxml`. Confirmar no Dockerfile de produção.
- `DistDFe` (doc `docs/metodos/DistDFe.md`):
  - Só devolve documentos onde o CNPJ consultado é destinatário, transportador ou terceiro (autXML).
  - Emitente NÃO recebe os próprios documentos por esse serviço.
  - Só modelo 55, só produção, sem contingência.
  - Resumo (resNFe) vem antes; XML completo exige manifestação de ciência/operação.
  - Loop com máx 50 docs por resposta, `sleep` 2s entre chamadas, limite de iterações (~12-20),
    parada obrigatória em cStat 137 (nada localizado) e 656 (consumo indevido), intervalo mínimo 1h entre buscas.
  - `ultNSU` deve persistir por CNPJ para retomada incremental.
- Consequência direta: "entrada" sai do DistDFe; "saída" precisa de outro caminho
  (consulta por chave NFeConsulta/CTeConsulta + upload manual de XML). Não prometer saída via DistDFe.
- Ritmo 1x/hora por Client casa com a regra SEFAZ de intervalo mínimo de 1h. Bom encaixe.

## Dependência da change multi-account-a-b-planos

O módulo fiscal nasce em cima de: `clients.account_id`, global scope por Account,
volume mensal do Plan, papéis (só admin gerencia certificado/integração),
seletor do super_admin e auditoria. Hoje só a task 1.1 (catálogo de Plans) está pronta.
Recomendação: fechar tasks 1.2, 1.3, 2.1, 2.2 antes do fiscal, senão o buscador nasce
vazando documentos entre escritórios.

## Abordagens consideradas

### A. Jobs Laravel por Client (recomendada)

Tabelas `client_certificates`, `fiscal_sync_states` (ultNSU por Client e modelo) e
`fiscal_documents`; XML em disco privado; Scheduler horário com lock anti-sobreposição
e jitter para não alinhar todos os CNPJs no mesmo minuto; services finos embrulhando `Tools`;
cada documento persistido conta 1 no volume mensal do Plan.
Prós: simples de operar, reaproveita fila/auditoria/isolamento existentes.
Contras: pico horário cresce com a carteira (mitigável com stagger + limite de loop).

### B. Worker separado só para SEFAZ

Isola `ext-soap` e certificados num serviço à parte.
Prós: escala e falha isoladas. Contras: segunda infra, deploy e auth entre serviços
para um volume que ainda não justifica.

### C. Gateway pago no lugar da SEFAZ direta

Prós: sem gerenciar certificado nem throttling. Contras: custo recorrente por CNPJ,
menos controle, e o caso de uso justifica integração direta.

## Desenho candidato (para virar proposal/specs)

- **Certificados:** upload de PFX + senha por Client, só admin (operador não gerencia
  integrações, pela spec de papéis). Validar na hora com leitura do PFX, extrair validade,
  guardar PFX e senha criptografados, nunca logar conteúdo. Auditar upload/troca/remoção.
- **Sincronização:** um job por Client por modelo (55, 65, 57), 1x/hora com jitter.
  Retoma do `ultNSU` salvo, respeita 137/656 com bloqueio até a próxima janela.
- **Documentos:** `fiscal_documents` com `account_id`, `client_id`, modelo, chave, NSU,
  direção (entrada/saída), emitente, valor, datas e referência ao XML. Mesmo global scope
  da base multi-account. Entrada via DistDFe, saída via consulta por chave + upload manual.
- **Tela:** aba Fiscal dentro de `clients/Show`: tabela com filtros por direção/modelo,
  status da última sincronização, badge de validade do certificado, botão Sincronizar agora
  (respeita bloqueio SEFAZ).
- **Erros como estado visível:** SEFAZ fora do ar, certificado expirado e volume esgotado
  viram aviso com ação (trocar certificado, pedir upgrade), nunca exceção silenciosa.

## O que fica de fora nesta fase (candidato a Non-Goals)

- Emissão de NF-e/CT-e (só busca/consulta e armazenamento).
- MDF-e, NFS-e, eSocial, EFD.
- DANFE impresso (só se o usuário pedir na proposal; pacote `sped-da` existe).
- Manifestação automática (só manual ou em change seguinte, por risco fiscal).

## Perguntas em aberto para o propose

- ~~Saída cobre só consulta por chave + upload manual, ou há emissor próprio para integrar?~~
  Decidido: saída em standby na v1.
- ~~Precisa de DANFE já na v1?~~ Decidido: sim, DANFE NF-e/NFS-e, sem DACTE.
- ~~Onde guardar PFX/senha: disco criptografado + coluna criptografada, ou cofre externo?~~
  Decidido: coluna criptografada + disco privado.
- ~~Botão "Sincronizar agora" na v1 ou só agendado?~~ Decidido: só agendado 1x/hora, sem botão.
- ~~Regra de retenção dos XMLs (espaço em disco por Account conta em algum limite do Plan?).~~
  Decidido: permanente enquanto Client ativo; documento persistido conta 1 no volume mensal.

## Achados do _legacy (2026-09-12, levantamento somente-leitura)

O legado confirma quase todo o desenho e corrige dois pontos:

1. **NFS-e: existem DOIS canais nacionais, não um.** O legado modela 5 famílias fiscais:
   `nfe`, `cte`, `nfce`, `nfse_adn`, `nfse_portal` (FiscalCapabilityResolver.php:11).
   - `nfse_adn`: Ambiente de Dados Nacional, API oficial com mTLS e `GET /contribuintes/DFe/{nsu|chave}`
     (NfseAdnClient.php:23,28), resposta em envelope com `docZip` base64+gzip como o DistDFe.
     É o canal primário de NFS-e.
   - `nfse_portal`: scraper do Emissor Nacional (nfse.gov.br) com login CNPJ+senha, captcha
     e reconstrução de XML (NfsePortalClient.php, 1198 linhas). É o fallback para o que o ADN não cobre.
   - "Municipal" aparece só como dado normalizado do XML, nunca como provedor HTTP. A spec legada
     PROÍBE fallback para provedores municipais arbitrários
     (dfe-unified-ingestion/spec.md: "MUST NOT fall back to arbitrary municipal providers").
   - Ou seja: "cliente HTTP próprio" continua valendo, mas contra o ADN primeiro, não contra a API
     genérica do padrão nacional. A API REST DPS → NFS-e documentada nos manuais serve para emissão;
     para busca de tomados/emitidos o ADN é o caminho.
2. **Município não aderente = resultado terminal `coverage_limited`, sem fallback.**
   O `NfseAdnClient` (linhas 86-96) trata HTTP 404 lendo `coverage_status` do corpo: se for
   `coverage_limited|not_participating|municipality_not_participating|outside_coverage`, registra
   evidência de cobertura e encerra; 404 ambíguo NÃO afirma falta de cobertura (vira `unknown`).
   Evidência persiste em `fiscal_coverage_evidence` (family, environment, coverage_status, provider,
   municipality_code, observed_at). Specs legadas confirmam: sem fallback municipal.
   Decisão para a v1 nova: mesmo comportamento, reaproveitar o padrão.
3. **Pacotes confirmados:** `nfephp-org/sped-nfe ^5.0` + `nfephp-org/sped-cte ^5.0`
   (composer.json:14-16 do legado). NFS-e nacional usa `mendesalexandre/php-nfse-nacional ^0.45`
   SÓ para render de DANFSe (`DanfseService::gerarDoXml`), nunca para emissão/consulta —
   confirma o ADR 0004 (cliente HTTP próprio para consulta, pacote de terceiros só para PDF).
4. **Agendamento confirmado:** `fiscal_sync_subscriptions` com `interval_minutes 60-1440`,
   `next_run_at`, `SELECT ... FOR UPDATE SKIP LOCKED`, fila `fiscal`, `fiscal:dispatch-due` a cada
   minuto. NFC-e só `query_only`, nunca agenda. Ciência automática NF-e (210210) + download,
   com tabela `fiscal_document_actions` e reconcile — confirma ADR 0003.
5. **Credenciais confirmadas:** A1 por Client (PKCS#12 validado, thumbprint SHA-256, segredo em vault
   por account) + senha de portal NFS-e por Client (sem certificado). Elegibilidade por família
   exige credencial válida. Reaproveitar o padrão na v1 nova.
6. **CT-e DistDFe nacional fonte AN**, `cUFAutor` = UF do Client. NF-e: DistDFe incremental por NSU
   + consulta por chave de 44 dígitos validada por regex.

Arquitetura de referência completa em `_legacy/apps/api/app/Contexts/Documents/`,
migrations em `_legacy/apps/api/database/migrations/2026_08_2*` e `2026_09_01_*`,
contratos em `_legacy/contracts/fiscal/v1/`, specs em `_legacy/openspec/specs/` (dfe-unified-ingestion,
nfse-adn-history-monitoring, nfse-national-search, fiscal-sync-subscriptions, nfe-automatic-manifestation).
