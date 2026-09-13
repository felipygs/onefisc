<?php

namespace App\Services\Fiscal;

use NFePHP\Common\Certificate;
use NFePHP\NFe\Tools as NFeTools;
use RuntimeException;

/**
 * Thin NF-e wrapper around nfephp-org/sped-nfe.
 *
 * Only fetches raw SOAP XML and delegates parsing to DistDfeParser, so no
 * vendor type ever leaks through the DistributionChannel interface.
 */
final class NFeDistChannel implements DistributionChannel
{
    private ?NFeTools $tools = null;

    public function __construct(
        private readonly string $pfxContents,
        private readonly string $pfxPassword,
        private readonly string $cnpj,
        private readonly string $companyName,
        private readonly int $environment = 1,
        private readonly string $stateUf = 'SP',
        ?NFeTools $tools = null,
    ) {
        $this->tools = $tools;
    }

    public function fetchSince(string $lastNsu): ChannelBatch
    {
        return DistDfeParser::parseBatch($this->tools()->sefazDistDFe((int) $lastNsu));
    }

    public function fetchByKey(string $key): ?array
    {
        return DistDfeParser::parseConsult($this->tools()->sefazConsultaChave($key), 'nfe');
    }

    private function tools(): NFeTools
    {
        if ($this->tools === null) {
            $config = json_encode([
                'atualizacao' => now()->format('Y-m-d H:i:s'),
                'tpAmb' => $this->environment,
                'razaosocial' => $this->companyName,
                'cnpj' => $this->cnpj,
                'siglaUF' => $this->stateUf,
                'versao' => '4.00',
            ]);

            if (! is_string($config)) {
                throw new RuntimeException('Nao foi possivel montar a configuracao do canal NF-e.');
            }

            $this->tools = new NFeTools($config, Certificate::readPfx($this->pfxContents, $this->pfxPassword));
        }

        return $this->tools;
    }
}
