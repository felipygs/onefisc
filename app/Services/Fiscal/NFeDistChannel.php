<?php

namespace App\Services\Fiscal;

use DOMDocument;
use DOMElement;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Tools as NFeTools;
use RuntimeException;

/**
 * Thin NF-e wrapper around nfephp-org/sped-nfe.
 *
 * Only fetches raw SOAP XML and delegates parsing to DistDfeParser, so no
 * vendor type ever leaks through the DistributionChannel interface.
 *
 * stateUf stays 'SP': DistribuicaoDFe resolves the national AN endpoint
 * whatever the UF (vendor sefazDistDFe passes fonte='AN'; consulta derives
 * UF from the key itself, manifesta posts to 'AN'), so UF never selects the
 * URL — it only fills the informational cUFAutor/timezone, and Client has no
 * UF column to derive it from (adding one is out of scope).
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

    /**
     * Manifest Ciencia da Operacao (evento 210210, seq 1) via the vendor
     * event service. Accepted (135/136) and already-manifested duplicate
     * (573) both count as success so retries stay idempotent; any other
     * SEFAZ answer is a refusal (false). Transport errors bubble to the
     * caller (ScienceService skips the item without fatal error).
     */
    public function manifestScience(string $key): bool
    {
        return self::scienceAccepted($this->tools()->sefazManifesta($key, NFeTools::EVT_CIENCIA));
    }

    private static function scienceAccepted(string $soapXml): bool
    {
        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            if (! $dom->loadXML($soapXml)) {
                return false;
            }
        } finally {
            libxml_use_internal_errors($previous);
        }

        foreach ($dom->getElementsByTagName('infEvento') as $event) {
            $cStat = $event->getElementsByTagName('cStat')->item(0);

            if ($cStat instanceof DOMElement && in_array(trim((string) $cStat->textContent), ['135', '136', '573'], true)) {
                return true;
            }
        }

        return false;
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
