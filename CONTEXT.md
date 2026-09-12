# App

Aplicação Laravel (Inertia + Vue + Nuxt UI) de gestão multi-account para escritórios de contabilidade.

## Language

### Accounts

**Account**:
Organização isolada dentro da plataforma. Pode ser a operação principal ou um escritório de contabilidade.
_Avoid_: Conta, Tenant, Empresa

**Account A**:
Primeira Account, criada no onboarding. É a única que possui usuário nível super_admin. Cria novas Accounts e gerencia os planos de assinatura das demais.

**Account B**:
Account comum de escritório de contabilidade. Mantém sua carteira de clientes, possui os papéis admin, operador e user, e não troca de Account.

**super_admin**:
Nível de usuário existente somente na Account A. Administra a plataforma e acessa outras Accounts via seletor para configuração, suporte e manutenção. Coexiste com o admin da operação da A, restrito ao seu próprio escopo. Nasce com o primeiro usuário do onboarding; depois, somente um super_admin cria ou promove outro, sempre dentro da A.

**Seletor de Accounts**:
Recurso exclusivo do super_admin para atuar em outras Accounts com poderes de plataforma, sob auditoria.
_Avoid_: Troca de conta, account switcher

### Papéis

**admin**:
Gerencia tudo dentro da sua Account.
_Avoid_: Administrador

**operador**:
Atua sobre toda a operação da sua Account e também executa trabalho como um user.
_Avoid_: Operador de conta

**user**:
Executa o trabalho que lhe é atribuído nos módulos da sua Account.
_Avoid_: Usuário final, colaborador

### Assinaturas e carteira

**Plan**:
Assinatura que define os limites e recursos de uma Account. O catálogo é gerenciado pela Account A e novas Accounts recebem o plano básico por padrão. Limita usuários, Clients, módulos liberados e volume de consultas. Somente a Account A troca o Plan de uma Account; ao estourar um limite, a criação é bloqueada com aviso.
_Avoid_: Plano, assinatura, subscription

**Client**:
Empresa (CNPJ) da carteira de uma Account, monitorada via integrações fiscais. A carteira é isolada por Account; o mesmo CNPJ pode existir em carteiras distintas. Guarda CNPJ, razão social, regime tributário e contador responsável, com monitoramento agendado por Client.
_Avoid_: Cliente, customer, empresa monitorada

### Entrada

**Onboarding**:
Criação da primeira Account (A) com seu usuário super_admin. Depois dele, novos usuários entram somente por convite.
_Avoid_: Cadastro inicial, setup

**Convite**:
Forma de entrada de novos usuários: o admin informa nome, e-mail e papel; o convite expira em 7 dias e o convidado define a senha no aceite. A Account A convida o admin inicial no ato de criação de cada Account.
_Avoid_: Convite por link público, auto-cadastro

### Fiscal

**Documento Fiscal**:
XML de NF-e (modelo 55), NFC-e (65) ou CT-e (57) guardado por Client, com direção de entrada (emitido contra o Client) ou saída (emitido pelo Client).
_Avoid_: Nota, XML solto

**Certificado digital**:
Arquivo PFX (padrão A1) mais senha, de cada Client, subido pelo escritório para autorizar as consultas à SEFAZ.
_Avoid_: Certificado do escritório, token

**DANFE**:
Representação PDF auxiliar do Documento Fiscal, gerada na v1 só para NF-e e NFC-e.
_Avoid_: Nota em PDF, impressão fiscal

**Manifestação do Destinatário**:
Gênero de eventos que informa à SEFAZ o status de uma nota de entrada: Ciência da Operação, Confirmação da Operação, Desconhecimento da Operação ou Operação não Realizada.
_Avoid_: Manifestar (sozinho, sem dizer qual espécie)

**Ciência da Operação**:
Espécie de manifestação que só informa que a nota foi emitida contra o CNPJ. Não confirma recebimento nem pagamento, é reversível e libera o XML no DistDFe.
_Avoid_: Confirmação, manifestação automática (sem especificar)

**Busca por chave**:
Obtenção do XML informando a chave de acesso (uma ou em lote), usada para saída e para NFC-e. Distinta de Descoberta automática e de Upload manual.
_Avoid_: Descobrir a chave, baixar XML (genérico)

**DPS**:
Declaração de Prestação de Serviços, documento que dá origem à NFS-e no padrão nacional.
_Avoid_: RPS (é o recibo provisório do modelo antigo municipal)

**NFS-e nacional**:
Nota de serviço no padrão nacional, buscada só pelos canais nacionais: ADN (API com mTLS) primeiro e portal do Emissor Nacional (login CNPJ+senha) como fallback. Sem integração municipal.
_Avoid_: NFS-e municipal, RPS, provedor de prefeitura

**Cobertura limitada**:
Resultado terminal quando o município não aderiu ao padrão nacional: registramos a evidência e encerramos, sem tentar provedor municipal.
_Avoid_: Erro de busca, município sem nota

**Sincronização fiscal**:
Execução automática 1x/hora por Client que busca documentos novos na SEFAZ. Sem ação manual: não há botão de sincronizar, o estado da última execução aparece na tela.
_Avoid_: Botão sincronizar, atualização manual
