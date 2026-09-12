# Somente padrão nacional para NFS-e (ADN + Emissor Nacional)

A v1 busca NFS-e exclusivamente pelos canais nacionais: ADN (API com mTLS) como primário
e portal do Emissor Nacional (https://www.nfse.gov.br/EmissorNacional) como fallback,
sem nenhuma integração com provedores municipais. A alternativa de um conector por
prefeitura multiplicaria integrações por centenas de layouts e contradiz a regra já
consagrada no legado de nunca afirmar cobertura nacional via fallback municipal.
Município não aderente vira `coverage_limited` terminal com evidência registrada.
