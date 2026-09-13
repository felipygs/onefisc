<?php

namespace App\Services\Fiscal;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use NFePHP\DA\Legacy\Pdf;

/**
 * Minimal honest DANFSe (task 4.1).
 *
 * Why this class exists: the render survey for this task confirmed that
 * `nfephp-org/sped-da` latest stable (v1.1.6) ships DANFE (NF-e) but NO
 * DANFSe — upstream `NFePHP\DA\NFSe\Danfse` only exists on dev-master
 * (unreleased), which this repo will not pin. Per the task brief ("sem
 * inventar layout mirabolante: o requisito é 'DANFSe disponível', não
 * pixel-perfect"), this renders a single-page auxiliary PDF on the very
 * same PDF engine sped-da itself bundles (`NFePHP\DA\Legacy\Pdf`/FPDF),
 * with the identifying fields of the national NFS-e and an explicit
 * "sem valor fiscal" notice. When sped-da releases DANFSe, renderDanfse()
 * should delegate to it and this class retires.
 */
final class MinimalDanfse
{
    private readonly DOMDocument $dom;

    private readonly DOMElement $infNFSe;

    private readonly DOMElement $infDPS;

    public function __construct(private readonly string $xml)
    {
        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = trim($xml) !== '' && $dom->loadXML($xml);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new InvalidArgumentException('O xml de NFS-e informado é inválido.');
        }

        $infNFSe = $dom->getElementsByTagName('infNFSe')->item(0);
        $infDPS = $infNFSe instanceof DOMElement
            ? $infNFSe->getElementsByTagName('infDPS')->item(0)
            : null;

        if (! $infNFSe instanceof DOMElement || ! $infDPS instanceof DOMElement) {
            throw new InvalidArgumentException('O xml informado não contém as tags infNFSe/infDPS da NFS-e.');
        }

        $this->dom = $dom;
        $this->infNFSe = $infNFSe;
        $this->infDPS = $infDPS;
    }

    /**
     * @return string Raw PDF bytes.
     */
    public function render(): string
    {
        $pdf = new Pdf('P', 'mm', 'A4');
        $pdf->setTitle('DANFSe - Documento Auxiliar da NFS-e');
        $pdf->addPage();
        $pdf->setMargins(15, 15);

        $this->title($pdf, 'DANFSe — Documento Auxiliar da NFS-e');
        $this->line($pdf, 'Documento sem valor fiscal — gerado a partir do XML armazenado.');

        $pdf->ln(4);
        $this->section($pdf, 'Identificação');
        $this->field($pdf, 'Número da NFS-e', $this->tag('nNFSe', $this->infNFSe));
        $this->field($pdf, 'Competência', $this->tag('dCompet', $this->infDPS));
        $this->field($pdf, 'Emissão da DPS', $this->tag('dhEmi', $this->infDPS));
        $this->field($pdf, 'DPS número/série', trim($this->tag('nDPS', $this->infDPS).' / '.$this->tag('serie', $this->infDPS), ' /'));
        $this->field($pdf, 'Chave de acesso', $this->accessKey());

        $pdf->ln(2);
        $this->section($pdf, 'Prestador');
        $this->party($pdf, $this->partyNode(['emit', 'prest']));

        $pdf->ln(2);
        $this->section($pdf, 'Tomador');
        $this->party($pdf, $this->partyNode(['toma', 'dest']));

        $pdf->ln(2);
        $this->section($pdf, 'Serviço');
        $this->field($pdf, 'Descrição', $this->firstTag(['xDescServ']));
        $this->field($pdf, 'Valor recebido', $this->firstTag(['vReceb', 'vServ']));

        return $pdf->getPdf();
    }

    private function title(Pdf $pdf, string $text): void
    {
        $pdf->setFont('Helvetica', 'B', 14);
        $pdf->cell(0, 9, $this->latin($text), 0, 1, 'C');
    }

    private function section(Pdf $pdf, string $text): void
    {
        $pdf->setFont('Helvetica', 'B', 11);
        $pdf->cell(0, 7, $this->latin($text), 0, 1, 'L');
    }

    private function line(Pdf $pdf, string $text): void
    {
        $pdf->setFont('Helvetica', '', 9);
        $pdf->cell(0, 6, $this->latin($text), 0, 1, 'C');
    }

    private function field(Pdf $pdf, string $label, string $value): void
    {
        $pdf->setFont('Helvetica', '', 10);
        $pdf->multicell(0, 6, $this->latin($label.': '.($value !== '' ? $value : '—')), 0, 'L');
    }

    private function party(Pdf $pdf, ?DOMElement $node): void
    {
        if ($node === null) {
            $this->field($pdf, 'Identificação', '');

            return;
        }

        $name = $this->tag('xNome', $node);
        $taxId = $this->tag('CNPJ', $node) !== '' ? $this->tag('CNPJ', $node) : $this->tag('CPF', $node);

        $this->field($pdf, 'Nome', $name);
        $this->field($pdf, 'CNPJ/CPF', $taxId);
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function partyNode(array $tags): ?DOMElement
    {
        foreach ([$this->infNFSe, $this->infDPS] as $scope) {
            foreach ($tags as $tag) {
                $node = $scope->getElementsByTagName($tag)->item(0);

                if ($node instanceof DOMElement) {
                    return $node;
                }
            }
        }

        return null;
    }

    private function accessKey(): string
    {
        $id = $this->infNFSe->getAttribute('Id');
        $suffix = substr(preg_replace('/\D/', '', $id) ?? '', -50);

        if (strlen($suffix) >= 44) {
            return $suffix;
        }

        return $this->firstTag(['chNFS', 'chaveAcesso', 'ChaveAcesso']);
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function firstTag(array $tags): string
    {
        foreach ($tags as $tag) {
            $node = $this->dom->getElementsByTagName($tag)->item(0);

            if ($node instanceof DOMElement && trim((string) $node->textContent) !== '') {
                return trim((string) $node->textContent);
            }
        }

        return '';
    }

    private function tag(string $tag, DOMElement $scope): string
    {
        $node = $scope->getElementsByTagName($tag)->item(0);

        if (! $node instanceof DOMElement) {
            return '';
        }

        return trim((string) $node->textContent);
    }

    private function latin(string $text): string
    {
        return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
    }
}
