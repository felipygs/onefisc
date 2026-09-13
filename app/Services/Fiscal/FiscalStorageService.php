<?php

namespace App\Services\Fiscal;

use App\Models\Client;
use App\Models\FiscalDocument;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Private-disk storage for fiscal XML/PDF bytes (task 4.1).
 *
 * Everything lives on the `local` disk (storage/app/private, never
 * web-exposed and never behind a public route; signed-URL serving arrives
 * in task 4.4). Paths carry ids only — `fiscal/{account_id}/{client_id}/
 * {document_id}.xml|pdf` — so no CNPJ or access key ever leaks into a file
 * name, and two Accounts can never collide on the same path.
 *
 * Retention: files are written here and never deleted by the app. When a
 * Client is destroyed its document ROWS cascade away, but the bytes stay on
 * disk for audit (deliberate safe-retention decision: storage is cheap,
 * fiscal liability is not).
 */
final class FiscalStorageService
{
    public const DISK = 'local';

    public function putXml(FiscalDocument $document, string $xml): void
    {
        if (trim($xml) === '') {
            throw new InvalidArgumentException('XML vazio nao pode ser guardado.');
        }

        $path = $this->xmlPathFor($document);

        Storage::disk(self::DISK)->put($path, $xml);

        $document->forceFill(['has_xml' => true, 'xml_path' => $path])->save();
    }

    /**
     * Missing path or missing file both read as null — never throws — so
     * the ?string contract holds whatever the disk `throw` config is.
     */
    public function getXml(FiscalDocument $document): ?string
    {
        $path = $document->xml_path;

        if (! self::isFiscalPath($path) || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $contents = Storage::disk(self::DISK)->get($path);

        return is_string($contents) ? $contents : null;
    }

    public function existsXml(FiscalDocument $document): bool
    {
        $path = $document->xml_path;

        return self::isFiscalPath($path) && Storage::disk(self::DISK)->exists($path);
    }

    public function putPdf(FiscalDocument $document, string $pdf): void
    {
        if (trim($pdf) === '') {
            throw new InvalidArgumentException('PDF vazio nao pode ser guardado.');
        }

        $path = $this->pdfPathFor($document);

        Storage::disk(self::DISK)->put($path, $pdf);

        $document->forceFill(['has_danfe' => true, 'pdf_path' => $path])->save();
    }

    /**
     * Missing path or missing file both read as null — never throws — so
     * the ?string contract holds whatever the disk `throw` config is.
     */
    public function getPdf(FiscalDocument $document): ?string
    {
        $path = $document->pdf_path;

        if (! self::isFiscalPath($path) || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $contents = Storage::disk(self::DISK)->get($path);

        return is_string($contents) ? $contents : null;
    }

    public function existsPdf(FiscalDocument $document): bool
    {
        $path = $document->pdf_path;

        return self::isFiscalPath($path) && Storage::disk(self::DISK)->exists($path);
    }

    public function xmlPathFor(FiscalDocument $document): string
    {
        return "fiscal/{$this->accountIdFor($document)}/{$document->client_id}/{$document->id}.xml";
    }

    public function pdfPathFor(FiscalDocument $document): string
    {
        return "fiscal/{$this->accountIdFor($document)}/{$document->client_id}/{$document->id}.pdf";
    }

    /**
     * Fail-closed path guard: only paths the service itself mints
     * (`fiscal/{account}/{client}/{document}.xml|pdf`) are ever read.
     * Anything else (tampered row, traversal, absolute path) reads as
     * missing so callers answer 404.
     */
    private static function isFiscalPath(mixed $path): bool
    {
        return is_string($path) && str_starts_with($path, 'fiscal/');
    }

    private function accountIdFor(FiscalDocument $document): int
    {
        return (int) Client::withoutGlobalScopes()->findOrFail($document->client_id)->account_id;
    }
}
