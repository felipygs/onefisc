<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fiscal HTTP timeout
    |--------------------------------------------------------------------------
    |
    | Seconds for a single provider round-trip (ADN distribution, Emissor
    | Nacional portal). Tests fake the transport, so this only binds prod.
    |
    */
    'timeout_seconds' => (int) env('FISCAL_TIMEOUT_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | NFS-e ADN (primary channel)
    |--------------------------------------------------------------------------
    |
    | Base URL of the Ambiente de Dados Nacional distribution
    | (`GET {base}/contribuintes/DFe/{nsu}` with mTLS). HTTPS only; an empty
    | or non-HTTPS value makes the factory fail closed instead of probing.
    |
    */
    'adn' => [
        'producao' => ['base_url' => env('FISCAL_ADN_PRODUCTION_URL')],
        'homologacao' => ['base_url' => env('FISCAL_ADN_HOMOLOGATION_URL')],
    ],

    /*
    |--------------------------------------------------------------------------
    | NFS-e Emissor Nacional portal (fallback channel, v1 without solver)
    |--------------------------------------------------------------------------
    */
    'nfse_portal' => [
        'producao' => ['base_url' => env('FISCAL_NFSE_PORTAL_PRODUCTION_URL', 'https://www.nfse.gov.br')],
        'homologacao' => ['base_url' => env('FISCAL_NFSE_PORTAL_HOMOLOGATION_URL')],
    ],

];
