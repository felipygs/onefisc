# Cliente HTTP próprio para NFS-e nacional

O pacote `sped-nfse-nacional` do nfephp-org está abandonado (último release em 2020,
README avisa remoção), então a v1 consulta a API REST do padrão nacional com cliente
HTTP próprio (DPS → NFS-e → eventos/DFe, mTLS com certificado do Client). A alternativa
de usar o pacote abandonado traria código sem manutenção para o coração fiscal, e a de
gateway pago criaria custo recorrente por CNPJ antes de validar a operação direta.
