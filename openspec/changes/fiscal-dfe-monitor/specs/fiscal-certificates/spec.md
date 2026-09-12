## Purpose

Permite ao escritório autorizar as consultas SEFAZ de cada Client com o certificado digital próprio dele, guardado com segurança e com validade sempre visível.

## ADDED Requirements

### Requirement: Certificado A1 por Client

Cada Client SHALL ter no máximo um certificado digital A1 ativo, subido pelo escritório, com arquivo e senha guardados de forma criptografada e nunca expostos em log ou tela.

#### Scenario: Upload válido

WHEN um admin sobe um PFX com senha correta e dentro da validade THEN o certificado SHALL ficar ativo no Client com validade visível.

#### Scenario: Upload inválido

WHEN o arquivo não abre com a senha ou está expirado THEN o upload SHALL ser rejeitado com o motivo, sem salvar nada.

#### Scenario: Troca de certificado

WHEN um admin sobe um novo PFX para um Client que já tem certificado THEN o novo SHALL substituir o anterior, com auditoria da troca.

### Requirement: Permissão de certificado

Somente admin (ou super_admin via Seletor de Accounts) SHALL subir, trocar ou remover certificado; operador e user SHALL NOT ter acesso a essas ações nem ao conteúdo do PFX/senha.

#### Scenario: Operador sem acesso

WHEN um operador tenta subir certificado THEN a operação SHALL ser negada.

### Requirement: Senha do portal NFS-e

Cada Client MAY ter uma senha do portal do Emissor Nacional guardada, como alternativa ao certificado para o canal portal; a senha SHALL ser guardada criptografada e nunca exibida após salva.

#### Scenario: Senha salva

WHEN um admin salva a senha do portal THEN ela SHALL ficar disponível para a sincronização do canal portal sem jamais ser exibida de volta.

### Requirement: Validade visível

A tela do Client SHALL exibir o estado do certificado (válido, vence em breve, expirado, ausente); com certificado expirado ou ausente, a sincronização daquele canal SHALL ficar suspensa com aviso acionável.

#### Scenario: Certificado expirado

WHEN o certificado do Client expira THEN novas sincronizações daquele canal SHALL ser suspensas com aviso pedindo a troca, sem apagar os documentos já guardados.
