<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Services\Fiscal\FiscalStorageService;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

// Task 4.4: document overlays need signed XML/PDF downloads — JSON
// endpoints mint short-lived opaque URLs, signed file routes stream bytes.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('downloadAccountUser')) {
    function downloadAccountUser(string $role = 'admin'): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

it('mints a signed xml url that streams intact bytes without storage references', function () {
    [$account, $admin] = downloadAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $document = FiscalDocument::factory()->create(['client_id' => $client->id, 'family' => 'nfe']);

    $xml = '<nfeProc><NFe><infNFe Id="NFe35260112345678000190550010000012341000012340"/></NFe></nfeProc>';
    (new FiscalStorageService)->putXml($document, $xml);

    actingAs($admin);

    $response = getJson(route('fiscal.download', $document))->assertOk();

    $downloadUrl = $response->json('download_url');

    expect($downloadUrl)->toBeString()->toContain('signature=');
    // The opaque URL never carries the internal storage reference.
    expect($downloadUrl)->not->toContain((string) $document->xml_path)
        ->not->toContain('storage');

    get($downloadUrl)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertHeader('Content-Disposition', 'attachment; filename="documento-'.$document->id.'.xml"');

    expect(get($downloadUrl)->getContent())->toBe($xml);

    // The JSON envelope leaks no path or tax id either.
    expect($response->getContent())->not->toContain((string) $document->xml_path);
});

it('rejects expired or tampered signed xml urls with 403', function () {
    [$account, $admin] = downloadAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $document = FiscalDocument::factory()->create(['client_id' => $client->id, 'family' => 'nfe']);
    (new FiscalStorageService)->putXml($document, '<nfeProc/>');

    actingAs($admin);

    $downloadUrl = getJson(route('fiscal.download', $document))->json('download_url');

    $tampered = (string) preg_replace('/signature=[^&]+/', 'signature=tampered', (string) $downloadUrl);
    get($tampered)->assertForbidden();

    $expired = URL::temporarySignedRoute('fiscal.download.file', now()->subMinutes(5), ['document' => $document->id]);
    get($expired)->assertForbidden();
});

it('isolates downloads by account and denies the user role', function () {
    [$accountA, $adminA] = downloadAccountUser('admin');
    [$accountB, $adminB] = downloadAccountUser('admin');
    $userA = User::factory()->create(['account_id' => $accountA->id, 'role' => 'user']);

    $clientA = Client::factory()->create(['account_id' => $accountA->id]);
    $document = FiscalDocument::factory()->create(['client_id' => $clientA->id, 'family' => 'nfe']);
    (new FiscalStorageService)->putXml($document, '<nfeProc/>');

    // Another account fails closed with 404, never 403 (no existence oracle).
    actingAs($adminB);
    getJson(route('fiscal.download', $document))->assertNotFound();
    getJson(route('fiscal.danfe', $document))->assertNotFound();

    // Same-account user role cannot operate documents.
    actingAs($userA);
    getJson(route('fiscal.download', $document))->assertForbidden();

    // Sanity: the owner account still mints.
    actingAs($adminA);
    getJson(route('fiscal.download', $document))->assertOk();
});

it('serves the danfe pdf for nfe and answers an honest 404 otherwise', function () {
    [$account, $admin] = downloadAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);

    $nfe = FiscalDocument::factory()->create(['client_id' => $client->id, 'family' => 'nfe']);
    $pdf = "%PDF-1.4\n%DANFE faux bytes\n";
    (new FiscalStorageService)->putXml($nfe, '<nfeProc/>');
    (new FiscalStorageService)->putPdf($nfe, $pdf);

    // CT-e never has a DACTE in v1: honest 404 even with stored XML.
    $cte = FiscalDocument::factory()->create(['client_id' => $client->id, 'family' => 'cte']);
    (new FiscalStorageService)->putXml($cte, '<cteProc/>');

    // NF-e without a rendered auxiliary: honest 404.
    $bare = FiscalDocument::factory()->create(['client_id' => $client->id, 'family' => 'nfe']);
    (new FiscalStorageService)->putXml($bare, '<nfeProc/>');

    actingAs($admin);

    $pdfUrl = getJson(route('fiscal.danfe', $nfe))->assertOk()->json('pdf_url');

    expect($pdfUrl)->toBeString()->toContain('signature=');
    expect($pdfUrl)->not->toContain((string) $nfe->pdf_path);

    get($pdfUrl)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="documento-'.$nfe->id.'.pdf"');

    expect(get($pdfUrl)->getContent())->toBe($pdf);

    getJson(route('fiscal.danfe', $cte))->assertNotFound();
    getJson(route('fiscal.danfe', $bare))->assertNotFound();

    // Missing XML also 404s honestly on the download endpoint.
    $empty = FiscalDocument::factory()->create(['client_id' => $client->id, 'family' => 'nfe', 'has_xml' => false]);
    getJson(route('fiscal.download', $empty))->assertNotFound();
});
