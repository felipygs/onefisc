<?php

namespace App\Services\Fiscal;

use NFePHP\Common\Certificate;
use NFePHP\CTe\Tools as CTeTools;
use RuntimeException;

/**
 * Thin CT-e wrapper around nfephp-org/sped-cte.
 *
 * Only fetches raw SOAP XML and delegates parsing to DistDfeParser, so no
 * vendor type ever leaks through the DistributionChannel interface.
 */
final class CTeDistChannel implements DistributionChannel
{
    private ?CTeTools $tools = null;

    public function __construct(
        private readonly string $pfxContents,
        private readonly string $pfxPassword,
        private readonly string $cnpj,
        private readonly string $companyName,
        private readonly int $environment = 1,
        private readonly string $stateUf = 'SP',
        ?CTeTools $tools = null,
    ) {
        $this->tools = $tools;
    }

    public function fetchSince(string $lastNsu): ChannelBatch
    {
        return DistDfeParser::parseBatch($this->tools()->sefazDistDFe((int) $lastNsu));
    }

    public function fetchByKey(string $key): ?array
    {
        return DistDfeParser::parseConsult($this->tools()->sefazConsultaChave($key), 'cte');
    }

    /**
     * Ciencia da Operacao (210210) does not exist for CT-e: the sync persists
     * CT-e summaries as pending without manifesting, so this always throws
     * and the runner only manifests the nfe family.
     */
    public function manifestScience(string $key): bool
    {
        throw new \LogicException('Ciencia da operacao (210210) nao se aplica a CT-e.');
    }

    private function tools(): CTeTools
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
                throw new RuntimeException('Nao foi possivel montar a configuracao do canal CT-e.');
            }

            $this->tools = new CTeTools($config, Certificate::readPfx($this->pfxContents, $this->pfxPassword));
        }

        return $this->tools;
    }
}
