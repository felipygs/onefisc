<?php

namespace App\Services\Fiscal;

use App\Models\FiscalDocument;
use LogicException;
use NFePHP\DA\NFe\Danfe;
use Throwable;

/**
 * Auxiliary PDF rendering for fiscal documents (task 4.1).
 *
 * DANFE for NF-e (`family=nfe`) via `nfephp-org/sped-da` Danfe. DANFSe for
 * the national NFS-e (`family=nfse`) via the minimal in-house renderer
 * (MinimalDanfse, same bundled FPDF engine): the render survey proved
 * sped-da stable (v1.1.6) ships no DANFSe — upstream Danfse only exists on
 * unreleased dev-master, which this repo does not pin. There is
 * deliberately NO DACTE in v1: CT-e raises an honest LogicException and
 * keeps `has_danfe=false`.
 *
 * Rendered bytes are persisted to the private disk through
 * FiscalStorageService (same id-based naming scheme as the XML) and the
 * document flags (`has_danfe`/`pdf_path`) are set here, so callers get a
 * single render-and-store step.
 */
final class FiscalPdfService
{
    public function __construct(
        private readonly FiscalStorageService $storage = new FiscalStorageService,
    ) {}

    /**
     * Render the DANFE for an NF-e document with stored XML.
     *
     * @return string Raw PDF bytes.
     *
     * @throws LogicException when the document is not an NF-e or has no XML.
     */
    public function renderDanfe(FiscalDocument $document): string
    {
        $this->assertFamily($document, 'nfe');

        $pdf = (new Danfe($this->storedXml($document)))->render();

        $this->storage->putPdf($document, $pdf);

        return $pdf;
    }

    /**
     * Render the DANFSe for a national NFS-e document with stored XML.
     *
     * @return string Raw PDF bytes.
     *
     * @throws LogicException when the document is not an NFS-e or has no XML.
     */
    public function renderDanfse(FiscalDocument $document): string
    {
        $this->assertFamily($document, 'nfse');

        $pdf = (new MinimalDanfse($this->storedXml($document)))->render();

        $this->storage->putPdf($document, $pdf);

        return $pdf;
    }

    /**
     * Family-dispatched render: DANFE for NF-e, DANFSe for NFS-e.
     *
     * @return string Raw PDF bytes.
     *
     * @throws LogicException for CT-e (no DACTE in v1) or missing XML.
     */
    public function renderFor(FiscalDocument $document): string
    {
        return match (strtolower((string) $document->family)) {
            'nfe' => $this->renderDanfe($document),
            'nfse' => $this->renderDanfse($document),
            default => throw new LogicException(
                "Documento auxiliar indisponivel para a familia '{$document->family}' (sem DACTE na v1)."
            ),
        };
    }

    /**
     * Best-effort render used by the completion step: CT-e and render
     * failures resolve to null (document keeps `has_danfe=false`) instead
     * of failing the sync cycle.
     */
    public function tryRender(FiscalDocument $document): ?string
    {
        try {
            return $this->renderFor($document);
        } catch (Throwable) {
            return null;
        }
    }

    private function assertFamily(FiscalDocument $document, string $expected): void
    {
        if (strtolower((string) $document->family) !== $expected) {
            throw new LogicException(
                "Renderizacao {$expected} indisponivel para a familia '{$document->family}'."
            );
        }
    }

    private function storedXml(FiscalDocument $document): string
    {
        $xml = $this->storage->getXml($document);

        if (! is_string($xml) || trim($xml) === '') {
            throw new LogicException('Documento sem XML guardado para renderizar o auxiliar.');
        }

        return $xml;
    }
}
