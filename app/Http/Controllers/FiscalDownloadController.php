<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FiscalDocument;
use App\Services\Fiscal\FiscalStorageService;
use App\Support\CurrentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class FiscalDownloadController extends Controller
{
    /**
     * Signed-URL lifetime: short enough to stay opaque, long enough for
     * the slideover/modal round-trip (spec: 5-15 min).
     */
    protected const URL_TTL_MINUTES = 10;

    public function __construct(
        private readonly FiscalStorageService $storage = new FiscalStorageService,
    ) {}

    /**
     * Mint a short-lived opaque download URL for a stored XML.
     */
    public function show(int $document): JsonResponse
    {
        $document = $this->findDocument($document);
        $this->authorizeDocument($document);

        if (! $document->has_xml || ! $this->storage->existsXml($document)) {
            abort(404);
        }

        return response()->json([
            'download_url' => URL::temporarySignedRoute(
                'fiscal.download.file',
                now()->addMinutes(self::URL_TTL_MINUTES),
                ['document' => $document->id]
            ),
        ]);
    }

    /**
     * Stream stored XML bytes. The signature is the only auth here by
     * design (short-lived URL); the path carries ids only, never secrets.
     */
    public function streamXml(int $document): Response
    {
        $document = FiscalDocument::query()->findOrFail($document);
        $xml = $this->storage->getXml($document);

        abort_if($xml === null, 404);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="documento-'.$document->id.'.xml"',
        ]);
    }

    /**
     * Mint a short-lived opaque preview URL for the DANFE/DANFSe PDF.
     */
    public function pdf(int $document): JsonResponse
    {
        $document = $this->findDocument($document);
        $this->authorizeDocument($document);

        if (! $document->has_danfe || ! $this->storage->existsPdf($document)) {
            abort(404);
        }

        return response()->json([
            'pdf_url' => URL::temporarySignedRoute(
                'fiscal.danfe.file',
                now()->addMinutes(self::URL_TTL_MINUTES),
                ['document' => $document->id]
            ),
        ]);
    }

    /**
     * Serve the auxiliary PDF inline for the modal iframe preview.
     */
    public function streamPdf(int $document): Response
    {
        $document = FiscalDocument::query()->findOrFail($document);
        $pdf = $this->storage->getPdf($document);

        abort_if($pdf === null, 404);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="documento-'.$document->id.'.pdf"',
        ]);
    }

    /**
     * Account-scoped lookup: the global scope fails closed with 404 for
     * documents outside the current account. Explicit (not implicit
     * binding) because binding resolves before ResolveAccountContext runs.
     */
    protected function findDocument(int $id): FiscalDocument
    {
        return FiscalDocument::query()->findOrFail($id);
    }

    protected function authorizeDocument(FiscalDocument $document): Account
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null && request()->user()?->can('operate-clients', $account), 403);

        // Global scope already isolates by account; fail closed on mismatch.
        abort_if((int) $document->client?->account_id !== (int) $account->id, 404);

        return $account;
    }
}
