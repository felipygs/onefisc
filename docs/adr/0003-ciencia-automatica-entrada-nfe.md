# Ciência automática na entrada de NF-e

A sincronização fiscal registra Ciência da Operação automaticamente em todo resumo (resNFe)
recebido no DistDFe, para liberar o XML completo. Ciência não é Confirmação da Operação:
não confirma recebimento nem pagamento, é reversível, e todo documento entrado por ciência
automática nasce marcado como pendente de conferência. A alternativa de ciência manual
travaria a automação 1x/hora, e a de confirmação automática criaria efeito fiscal indevido
sobre documentos não verificados.
