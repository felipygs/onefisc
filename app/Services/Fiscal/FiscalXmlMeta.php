<?php

namespace App\Services\Fiscal;

use DOMDocument;
use DOMElement;

/**
 * Tolerant metadata extraction from a full fiscal XML (task 4.1).
 *
 * Covers the NF-e (`infNFe`), CT-e (`infCTe`) and national NFS-e
 * (`infNFSe`/`infDPS`) shapes with first-match tag lookups: anything the
 * XML does not carry comes back null so the caller only fills what the
 * summary row was missing. Never throws on malformed XML — returns an
 * all-null map instead, and the completion step retries next cycle.
 *
 * @return array{number: string|null, series: string|null, emission_at: string|null, issuer_name: string|null, issuer_tax_id: string|null, recipient_name: string|null, recipient_tax_id: string|null}
 */
final class FiscalXmlMeta
{
    /**
     * @return array{number: string|null, series: string|null, emission_at: string|null, issuer_name: string|null, issuer_tax_id: string|null, recipient_name: string|null, recipient_tax_id: string|null}
     */
    public static function extract(string $xml, string $family): array
    {
        $empty = [
            'number' => null,
            'series' => null,
            'emission_at' => null,
            'issuer_name' => null,
            'issuer_tax_id' => null,
            'recipient_name' => null,
            'recipient_tax_id' => null,
        ];

        $dom = self::loadXml($xml);

        if ($dom === null) {
            return $empty;
        }

        $numberTags = match (strtolower($family)) {
            'cte' => ['nCT'],
            'nfse' => ['nNFSe', 'nDPS'],
            default => ['nNF'],
        };

        $issuer = self::firstNode($dom, ['emit', 'prest']);
        $recipient = self::firstNode($dom, ['dest', 'toma']);

        return [
            'number' => self::firstText($dom, $numberTags),
            'series' => self::firstText($dom, ['serie']),
            'emission_at' => self::asDatetime(self::firstText($dom, ['dhEmi', 'dhProc', 'dEmi'])),
            'issuer_name' => $issuer !== null ? self::childText($issuer, 'xNome') : null,
            'issuer_tax_id' => $issuer !== null ? self::childText($issuer, ['CNPJ', 'CPF']) : null,
            'recipient_name' => $recipient !== null ? self::childText($recipient, 'xNome') : null,
            'recipient_tax_id' => $recipient !== null ? self::childText($recipient, ['CNPJ', 'CPF']) : null,
        ];
    }

    private static function loadXml(string $xml): ?DOMDocument
    {
        if (trim($xml) === '') {
            return null;
        }

        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            if (! $dom->loadXML($xml)) {
                return null;
            }
        } finally {
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }

    /**
     * @param  array<int, string>  $tags
     */
    private static function firstNode(DOMDocument $dom, array $tags): ?DOMElement
    {
        foreach ($tags as $tag) {
            $node = $dom->getElementsByTagName($tag)->item(0);

            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $tags
     */
    private static function firstText(DOMDocument $dom, array $tags): ?string
    {
        foreach ($tags as $tag) {
            $node = $dom->getElementsByTagName($tag)->item(0);

            if ($node instanceof DOMElement) {
                $text = trim((string) $node->textContent);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * @param  string|array<int, string>  $tags
     */
    private static function childText(DOMElement $element, string|array $tags): ?string
    {
        foreach ((array) $tags as $tag) {
            $node = $element->getElementsByTagName($tag)->item(0);

            if ($node instanceof DOMElement) {
                $text = trim((string) $node->textContent);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    private static function asDatetime(?string $value): ?string
    {
        if ($value === null || trim($value) === '' || strtotime($value) === false) {
            return null;
        }

        return trim($value);
    }
}
