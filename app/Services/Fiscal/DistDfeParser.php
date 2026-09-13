<?php

namespace App\Services\Fiscal;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Pure parsing of SEFAZ distribution/consult responses.
 *
 * Kept vendor-free and network-free on purpose: the channel wrappers only
 * fetch raw SOAP XML and delegate here, so canned fixtures cover cStat
 * 138 (documents), 137 (nothing found) and 656 (undue consumption)
 * without any real SEFAZ call.
 */
final class DistDfeParser
{
    public const PAUSE_NO_DOCUMENTS = 'sefaz_no_documents';

    public const PAUSE_OVERUSE = 'sefaz_overuse';

    /**
     * Parse a sefazDistDFe SOAP response into a ChannelBatch.
     *
     * @throws RuntimeException on missing blocks or unexpected cStat codes.
     */
    public static function parseBatch(string $soapXml): ChannelBatch
    {
        $ret = self::findNode($soapXml, 'retDistDFeInt');

        if (! $ret instanceof DOMElement) {
            throw new RuntimeException('Resposta da SEFAZ sem bloco retDistDFeInt.');
        }

        $cStat = trim(self::text($ret, 'cStat'));
        $ultNsu = trim(self::text($ret, 'ultNSU'));

        $pause = match ($cStat) {
            '138' => null,
            '137' => self::PAUSE_NO_DOCUMENTS,
            '656' => self::PAUSE_OVERUSE,
            default => throw new RuntimeException("Retorno DistDFe inesperado da SEFAZ (cStat {$cStat})."),
        };

        $items = [];

        foreach ($ret->getElementsByTagName('docZip') as $doc) {
            $items[] = [
                'nsu' => $doc->getAttribute('NSU'),
                'schema' => $doc->getAttribute('schema'),
                'xml' => self::decodeDocZip(trim($doc->textContent)),
            ];
        }

        return new ChannelBatch(items: $items, lastNsu: $ultNsu === '' ? '0' : $ultNsu, pause: $pause);
    }

    /**
     * Parse a sefazConsultaChave SOAP response.
     *
     * @param  'nfe'|'cte'  $family
     * @return array<string, mixed>|null Null when the key is unknown at SEFAZ.
     *
     * @throws RuntimeException on missing blocks.
     */
    public static function parseConsult(string $soapXml, string $family): ?array
    {
        $retTag = $family === 'cte' ? 'retConsSitCTe' : 'retConsSitNFe';
        $protTag = $family === 'cte' ? 'protCTe' : 'protNFe';
        $keyTag = $family === 'cte' ? 'chCTe' : 'chNFe';

        $ret = self::findNode($soapXml, $retTag);

        if (! $ret instanceof DOMElement) {
            throw new RuntimeException("Resposta da SEFAZ sem bloco {$retTag}.");
        }

        $prot = $ret->getElementsByTagName($protTag)->item(0);

        if (! $prot instanceof DOMElement) {
            return null;
        }

        $key = trim(self::text($prot, $keyTag));

        if ($key === '') {
            return null;
        }

        return [
            'key' => $key,
            'protocol_status' => trim(self::text($prot, 'cStat')),
            'received_at' => trim(self::text($prot, 'dhRecbto')),
            'issuer_document' => self::issuerDocument($prot),
            'motive' => trim(self::text($ret, 'xMotivo')),
        ];
    }

    private static function findNode(string $xml, string $tag): ?DOMElement
    {
        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            if (! $dom->loadXML($xml)) {
                return null;
            }
        } finally {
            libxml_use_internal_errors($previous);
        }

        $node = $dom->getElementsByTagName($tag)->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private static function text(DOMElement $element, string $tag): string
    {
        $node = $element->getElementsByTagName($tag)->item(0);

        return $node instanceof DOMElement ? (string) $node->textContent : '';
    }

    private static function issuerDocument(DOMElement $prot): ?string
    {
        $emit = $prot->getElementsByTagName('emit')->item(0);

        if (! $emit instanceof DOMElement) {
            return null;
        }

        foreach (['CNPJ', 'CPF'] as $tag) {
            $node = $emit->getElementsByTagName($tag)->item(0);

            if ($node instanceof DOMElement && trim((string) $node->textContent) !== '') {
                return trim((string) $node->textContent);
            }
        }

        return null;
    }

    private static function decodeDocZip(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);

        if (! is_string($decoded)) {
            return $encoded;
        }

        if (str_starts_with($decoded, "\x1f\x8b")) {
            $gunzipped = gzdecode($decoded);

            return is_string($gunzipped) ? $gunzipped : $decoded;
        }

        return $decoded;
    }
}
