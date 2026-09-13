<?php

use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FiscalCoverageEvidence;
use App\Models\FiscalDocument;
use App\Models\FiscalSyncCursor;
use App\Models\FiscalSyncSubscription;
use App\Services\Fiscal\ChannelBatch;
use App\Services\Fiscal\DistributionChannel;
use App\Services\Fiscal\FiscalChannelFactory;
use App\Services\Fiscal\FiscalSyncRunner;
use App\Services\Fiscal\NfseAdnChannel;
use App\Services\Fiscal\NfseCoverageException;
use App\Services\Fiscal\NfsePortalChannel;
use App\Services\Fiscal\NfseTransportException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

// ---------------------------------------------------------------------------
// Fakes of the INTERNAL interface (never the network). The runner is wired
// with a stub factory; the channel classes below are tested with Http::fake.
// ---------------------------------------------------------------------------

final class NfseRecordingChannel implements DistributionChannel
{
    public int $fetchSinceCalls = 0;

    public ?Throwable $toThrow = null;

    public ChannelBatch $cannedBatch;

    /** @var array<int, ChannelBatch> */
    public array $queuedBatches = [];

    /** @var array<int, string> */
    public array $requestedMarkers = [];

    public ?string $passwordSeen = null;

    public function __construct()
    {
        $this->cannedBatch = new ChannelBatch(items: [], lastNsu: '0');
    }

    public function fetchSince(string $lastNsu): ChannelBatch
    {
        $this->fetchSinceCalls++;
        $this->requestedMarkers[] = $lastNsu;

        if ($this->toThrow !== null) {
            throw $this->toThrow;
        }

        if ($this->queuedBatches !== []) {
            return array_shift($this->queuedBatches);
        }

        return $this->cannedBatch;
    }

    public function fetchByKey(string $key): ?array
    {
        return null;
    }

    public function manifestScience(string $key): bool
    {
        throw new LogicException('Ciencia da operacao (210210) nao se aplica a NFS-e.');
    }
}

final class NfseStubFactory extends FiscalChannelFactory
{
    public function __construct(
        private readonly NfseRecordingChannel $adn,
        private readonly ?NfseRecordingChannel $portal = null,
    ) {}

    public function for(FiscalSyncSubscription $subscription): DistributionChannel
    {
        return $this->adn;
    }

    public function portalFor(FiscalSyncSubscription $subscription): DistributionChannel
    {
        if ($this->portal === null) {
            throw new RuntimeException('Senha do portal ausente para o Client.');
        }

        // Mirrors the production factory: the portal secret is decrypted from
        // the credential and handed to the channel in memory only.
        $credential = ClientCredential::withoutGlobalScopes()
            ->where('client_id', $subscription->client_id)
            ->firstOrFail();

        $this->portal->passwordSeen = Crypt::decryptString((string) $credential->getRawOriginal('portal_password'));

        return $this->portal;
    }
}

function nfseClientWithCredential(?string $portalPassword = null): Client
{
    $client = Client::factory()->create();

    ClientCredential::factory()->create([
        'client_id' => $client->id,
        'pfx_data' => 'encrypted-pfx-fixture',
        'pfx_password' => 'encrypted-password-fixture',
        'expires_at' => now()->addYear(),
        'portal_password' => $portalPassword !== null ? Crypt::encryptString($portalPassword) : null,
    ]);

    return $client;
}

function nfseSubscriptionFor(Client $client): FiscalSyncSubscription
{
    return FiscalSyncSubscription::factory()->create([
        'client_id' => $client->id,
        'family' => 'nfse',
        'environment' => 'production',
        'next_run_at' => now()->subMinutes(5),
        'blocked_until' => null,
    ]);
}

function nfseKey(string $seed = '1'): string
{
    return str_pad($seed, 44, '0', STR_PAD_RIGHT);
}

// ---------------------------------------------------------------------------
// 1. ADN 404 WITH proof of non-adherence -> evidence limited + terminal.
// ---------------------------------------------------------------------------

it('records terminal limited coverage on ADN 404 with proof and never reconsults', function () {
    $client = nfseClientWithCredential();
    $subscription = nfseSubscriptionFor($client);

    $adn = new NfseRecordingChannel;
    $adn->toThrow = NfseCoverageException::limited('adn_coverage_unavailable', [
        'channel' => 'adn',
        'http_status' => 404,
    ]);

    $runner = new FiscalSyncRunner(new NfseStubFactory($adn));
    $result = $runner->run($subscription);

    expect($result->status)->toBe('limited');

    $evidence = FiscalCoverageEvidence::withoutGlobalScopes()
        ->where('client_id', $client->id)
        ->where('family', 'nfse')
        ->firstOrFail();

    expect($evidence->status)->toBe('limited')
        ->and($evidence->reason)->toBe('adn_coverage_unavailable')
        ->and($evidence->evidence)->toMatchArray(['channel' => 'adn', 'http_status' => 404]);

    // Second round: terminal, the channel is never touched again.
    $second = (new FiscalSyncRunner(new NfseStubFactory($adn)))->run($subscription->fresh());

    expect($second->status)->toBe('limited')
        ->and($adn->fetchSinceCalls)->toBe(1);
});

// ---------------------------------------------------------------------------
// 2. Ambiguous ADN 404 -> evidence unknown + normal retry next cycle.
// ---------------------------------------------------------------------------

it('records unknown coverage on ambiguous ADN 404 and retries normally', function () {
    $client = nfseClientWithCredential();
    $subscription = nfseSubscriptionFor($client);

    $adn = new NfseRecordingChannel;
    $adn->toThrow = NfseCoverageException::unknown('adn_endpoint_or_resource_not_found', [
        'channel' => 'adn',
        'http_status' => 404,
    ]);

    $runner = new FiscalSyncRunner(new NfseStubFactory($adn));

    expect($runner->run($subscription)->status)->toBe('unknown');

    $evidence = FiscalCoverageEvidence::withoutGlobalScopes()
        ->where('client_id', $client->id)
        ->firstOrFail();

    expect($evidence->status)->toBe('unknown')
        ->and($evidence->reason)->toBe('adn_endpoint_or_resource_not_found');

    // Unknown is NOT terminal: the next cycle consults ADN again.
    expect($runner->run($subscription->fresh())->status)->toBe('unknown')
        ->and($adn->fetchSinceCalls)->toBe(2);
});

// ---------------------------------------------------------------------------
// 3. ADN failure -> portal fallback is attempted (with password) and counts.
// ---------------------------------------------------------------------------

it('falls back to the portal channel with the stored password when ADN fails', function () {
    $client = nfseClientWithCredential('portal-secret-1');
    $subscription = nfseSubscriptionFor($client);

    $adn = new NfseRecordingChannel;
    $adn->toThrow = new NfseTransportException('adn_unavailable');

    $portal = new NfseRecordingChannel;
    $portal->cannedBatch = new ChannelBatch(
        items: [
            ['key' => nfseKey('11'), 'nsu' => nfseKey('11')],
            ['key' => nfseKey('22'), 'nsu' => nfseKey('22')],
        ],
        lastNsu: nfseKey('22'),
    );

    $result = (new FiscalSyncRunner(new NfseStubFactory($adn, $portal)))->run($subscription);

    expect($result->status)->toBe('synced')
        ->and($result->fetched)->toBe(2)
        ->and($portal->fetchSinceCalls)->toBe(1)
        ->and($portal->passwordSeen)->toBe('portal-secret-1');

    // Fallback result counts (fetched), but row persistence is 4.1 work.
    expect(FiscalDocument::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 4. Captcha on the portal -> limited portal_captcha, no blind retry.
// ---------------------------------------------------------------------------

it('records terminal portal_captcha coverage without blind retries', function () {
    $client = nfseClientWithCredential('portal-secret-2');
    $subscription = nfseSubscriptionFor($client);

    $adn = new NfseRecordingChannel;
    $adn->toThrow = new NfseTransportException('adn_unavailable');

    $portal = new NfseRecordingChannel;
    $portal->toThrow = NfseCoverageException::limited('portal_captcha', [
        'channel' => 'portal',
        'phase' => 'download',
    ]);

    $factory = new NfseStubFactory($adn, $portal);
    $runner = new FiscalSyncRunner($factory);

    expect($runner->run($subscription)->status)->toBe('limited');

    $evidence = FiscalCoverageEvidence::withoutGlobalScopes()
        ->where('client_id', $client->id)
        ->firstOrFail();

    expect($evidence->status)->toBe('limited')
        ->and($evidence->reason)->toBe('portal_captcha');

    // Terminal: no blind retry of either channel on the next cycle.
    $runner->run($subscription->fresh());

    expect($adn->fetchSinceCalls)->toBe(1)
        ->and($portal->fetchSinceCalls)->toBe(1);
});

// ---------------------------------------------------------------------------
// 5. Adherent municipality happy path -> documents flow, no evidence.
//
// Scope boundary (documented decision): the batch items (keys) flow through
// the channel into the runner (fetched + cursor advance), but NO
// FiscalDocument rows are persisted here. Real NFS-e access keys are 50
// digits while fiscal_documents.key is string(44) and ScienceService pins
// 44-digit keys: widening that column + the key validation + the 44-digit UI
// assumptions is 4.1 work (guarda de XML + metadados), which persists BEFORE
// the cursor advances. Pinning count 0 below guards that boundary.
// ---------------------------------------------------------------------------

it('flows documents for an adherent municipality without coverage evidence', function () {
    $client = nfseClientWithCredential();
    $subscription = nfseSubscriptionFor($client);

    $adn = new NfseRecordingChannel;
    $adn->queuedBatches = [
        new ChannelBatch(
            items: [
                ['key' => nfseKey('31'), 'nsu' => '33'],
                ['key' => nfseKey('32'), 'nsu' => '34'],
            ],
            lastNsu: '34',
        ),
        new ChannelBatch(items: [], lastNsu: '34'),
    ];

    $result = (new FiscalSyncRunner(new NfseStubFactory($adn)))->run($subscription);

    expect($result->status)->toBe('synced')
        ->and($result->fetched)->toBe(2)
        ->and($result->lastNsu)->toBe('34');

    expect(FiscalCoverageEvidence::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(0)
        ->and(FiscalDocument::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(0)
        ->and(FiscalSyncCursor::withoutGlobalScopes()->where('client_id', $client->id)->firstOrFail()->last_nsu)
        ->toBe('34');
});

// ---------------------------------------------------------------------------
// Channel level (Http::fake, never the real network): ADN classification.
// ---------------------------------------------------------------------------

it('fetches ADN documents from the official LoteDFe shape over the NSU path', function () {
    config(['fiscal.adn.producao.base_url' => 'https://adn.test']);
    $chave = nfseKey('7');
    $payload = base64_encode((string) gzencode('<NFSe><infNFSe Id="NFS'.$chave.'"/></NFSe>'));

    Http::fake([
        'https://adn.test/*' => Http::response([
            'StatusProcessamento' => 'DOCUMENTOS_LOCALIZADOS',
            'LoteDFe' => [[
                'NSU' => 33,
                'ChaveAcesso' => $chave,
                'TipoDocumento' => 'NFSE',
                'ArquivoXml' => $payload,
            ]],
            'TipoAmbiente' => 'PRODUCAO',
        ], 200),
    ]);

    $batch = (new NfseAdnChannel('https://adn.test'))->fetchSince('0');

    expect($batch->lastNsu)->toBe('33')
        ->and($batch->items)->toHaveCount(1)
        ->and($batch->items[0]['key'])->toBe($chave);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://adn.test/contribuintes/DFe/0');
});

it('treats official ADN 404 NENHUM_DOCUMENTO_LOCALIZADO as covered emptiness', function () {
    $batch = (function (): ChannelBatch {
        Http::fake([
            'https://adn.test/*' => Http::response([
                'StatusProcessamento' => 'NENHUM_DOCUMENTO_LOCALIZADO',
                'LoteDFe' => [],
            ], 404),
        ]);

        return (new NfseAdnChannel('https://adn.test'))->fetchSince('33');
    })();

    expect($batch->items)->toBe([])
        ->and($batch->lastNsu)->toBe('33');
});

it('maps ADN 404 with coverage proof to limited and bare 404 to unknown', function (array $body, array $headers, string $status) {
    Http::fake([
        'https://adn.test/*' => Http::response($body, 404, $headers),
    ]);

    $channel = new NfseAdnChannel('https://adn.test');

    try {
        $channel->fetchSince('0');
        $this->fail('Expected an NfseCoverageException.');
    } catch (NfseCoverageException $e) {
        expect($e->status)->toBe($status);
    }
})->with([
    'body proof' => [['coverage_status' => 'municipality_not_participating'], [], 'limited'],
    'header proof' => [[], ['x-adn-coverage' => 'coverage_limited'], 'limited'],
    'bare 404' => [[], [], 'unknown'],
]);

it('maps ADN transport failures to the portal-fallback exception without secrets', function () {
    Http::fake(['https://adn.test/*' => Http::response('boom', 500)]);

    $channel = new NfseAdnChannel('https://adn.test', 'PFX-SENTINEL-BYTES', 'pfx-sentinel-pass');

    try {
        $channel->fetchSince('0');
        $this->fail('Expected an NfseTransportException.');
    } catch (NfseTransportException $e) {
        expect($e->getMessage())->not->toContain('PFX-SENTINEL-BYTES')
            ->and($e->getMessage())->not->toContain('pfx-sentinel-pass')
            ->and((string) json_encode($e->evidence))->not->toContain('PFX-SENTINEL-BYTES');
    }
});

// ---------------------------------------------------------------------------
// Channel level: portal login + listing + download, captcha -> limited.
// ---------------------------------------------------------------------------

function nfsePortalHappyFakes(string $first, string $second, string $signed): void
{
    Http::fake([
        'https://portal.test/EmissorNacional/Login' => Http::response('<html>Painel Notas/Emitidas</html>', 200),
        'https://portal.test/EmissorNacional/Notas/Emitidas*' => Http::response(
            '<a href="/EmissorNacional/Notas/Download/NFSe/'.$first.'">xml</a>'
            .'<a href="/EmissorNacional/Notas/Download/NFSe/'.$second.'">xml</a>', 200,
        ),
        'https://portal.test/EmissorNacional/Notas/Recebidas*' => Http::response('<html></html>', 200),
        'https://portal.test/EmissorNacional/Notas/Download/NFSe/*' => Http::response($signed, 200, [
            'Content-Type' => 'application/xml',
        ]),
    ]);
}

it('fetches portal documents with the stored password and never a municipal provider', function () {
    $first = nfseKey('41');
    $second = nfseKey('42');
    nfsePortalHappyFakes($first, $second, '<NFSe><Signature/></NFSe>');

    $batch = (new NfsePortalChannel('https://portal.test', '12345678000195', 'portal-secret-3'))->fetchSince('');

    expect($batch->items)->toHaveCount(2)
        ->and([$batch->items[0]['key'], $batch->items[1]['key']])->toContain($first, $second);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/EmissorNacional/Login')
            && str_contains((string) $request->body(), 'portal-secret-3');
    });

    foreach (Http::recorded() as [$request]) {
        expect($request->url())->not->toContain('prefeitura')
            ->and($request->url())->not->toContain('municip');
    }
});

it('maps a portal captcha challenge to terminal portal_captcha coverage', function () {
    $chave = nfseKey('43');

    Http::fake([
        'https://portal.test/EmissorNacional/Login' => Http::response('<html>Painel Notas/Emitidas</html>', 200),
        'https://portal.test/EmissorNacional/Notas/Emitidas*' => Http::response(
            '<a href="/EmissorNacional/Notas/Download/NFSe/'.$chave.'">xml</a>', 200,
        ),
        'https://portal.test/EmissorNacional/Notas/Recebidas*' => Http::response('<html></html>', 200),
        'https://portal.test/EmissorNacional/Notas/Download/NFSe/*' => Http::response('<div class="h-captcha"></div>', 403),
    ]);

    try {
        (new NfsePortalChannel('https://portal.test', '12345678000195', 'portal-secret-4'))->fetchSince('');
        $this->fail('Expected an NfseCoverageException.');
    } catch (NfseCoverageException $e) {
        expect($e->status)->toBe('limited')
            ->and($e->reason)->toBe('portal_captcha');
    }
});

it('resolves the nfse family through the production channel factory', function () {
    config(['fiscal.adn.producao.base_url' => 'https://adn.test']);
    $client = nfseClientWithCredential('portal-secret-5');

    $subscription = FiscalSyncSubscription::factory()->create([
        'client_id' => $client->id,
        'family' => 'nfse',
        'environment' => 'production',
    ]);

    $factory = new FiscalChannelFactory;

    // PFX fixture bytes are not a real PKCS#12 archive: encrypt valid-shaped
    // ciphertext is enough here because the factory must hand secrets to the
    // channel without ever touching the network.
    $credential = ClientCredential::withoutGlobalScopes()->where('client_id', $client->id)->firstOrFail();
    $credential->forceFill([
        'pfx_data' => Crypt::encryptString('pfx-bytes-fixture'),
        'pfx_password' => Crypt::encryptString('pfx-pass-fixture'),
    ])->save();

    expect($factory->for($subscription))->toBeInstanceOf(NfseAdnChannel::class)
        ->and($factory->portalFor($subscription))->toBeInstanceOf(NfsePortalChannel::class);
});
