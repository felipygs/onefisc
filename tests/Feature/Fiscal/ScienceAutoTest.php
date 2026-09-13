<?php

use App\Models\AuditLog;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentAction;
use App\Models\FiscalSyncCursor;
use App\Services\Fiscal\ChannelBatch;
use App\Services\Fiscal\CTeDistChannel;
use App\Services\Fiscal\NFeDistChannel;
use NFePHP\NFe\Tools as NFeTools;

// ---------------------------------------------------------------------------
// Helpers local to task 3.3. The FakeDistChannel / runnerWithFakeChannel /
// syncClientWithCredential / syncSubscriptionFor helpers are reused from
// SyncChannelsTest (same suite, fakes of the INTERNAL interface only).
// ---------------------------------------------------------------------------

if (! function_exists('scienceKey')) {
    function scienceKey(string $digit): string
    {
        return str_repeat($digit, 44);
    }
}

if (! function_exists('scienceResumo')) {
    /**
     * @return array<string, mixed>
     */
    function scienceResumo(string $nsu, string $key, array $overrides = []): array
    {
        return array_merge([
            'nsu' => $nsu,
            'schema' => 'resNFe_v1.01.xsd',
            'key' => $key,
            'xml' => '<resNFe versao="1.01"><chNFe>'.$key.'</chNFe>'
                .'<xNome>Emitente Teste</xNome><CNPJ>12345678000195</CNPJ>'
                .'<dhEmi>2026-09-12T10:00:00-03:00</dhEmi></resNFe>',
        ], $overrides);
    }
}

if (! function_exists('scienceCursorFor')) {
    function scienceCursorFor(int $clientId, string $family = 'nfe'): string
    {
        return (string) FiscalSyncCursor::withoutGlobalScopes()
            ->where('client_id', $clientId)
            ->where('family', $family)
            ->where('environment', 'production')
            ->firstOrFail()
            ->getAttribute('last_nsu');
    }
}

if (! class_exists('StubNFeManifestTools')) {
    // Vendor transport stub (canned manifest SOAP, never the network).
    final class StubNFeManifestTools extends NFeTools
    {
        public function __construct(private readonly string $canned) {}

        public function sefazManifesta(
            string $chave,
            int $tpEvento,
            string $xJust = '',
            int $nSeqEvento = 1,
            ?DateTimeInterface $dhEvento = null,
            ?string $lote = null
        ): string {
            return $this->canned;
        }
    }
}

if (! function_exists('manifestRetEvento')) {
    function manifestRetEvento(string $cStat): string
    {
        return '<retEnvEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">'
            .'<tpAmb>1</tpAmb><verAplic>AN_1.0</verAplic><cOrgao>91</cOrgao>'
            .'<cStat>128</cStat><xMotivo>Lote processado</xMotivo>'
            .'<retEvento versao="1.00"><infEvento Id="ID210210'.scienceKey('1').'">'
            .'<tpAmb>1</tpAmb><verAplic>AN_1.0</verAplic><cOrgao>91</cOrgao>'
            ."<cStat>{$cStat}</cStat><xMotivo>fixture</xMotivo>"
            .'<chNFe>'.scienceKey('1').'</chNFe>'
            .'<dhRegEvento>2026-09-12T10:00:00-03:00</dhRegEvento>'
            .'</infEvento></retEvento></retEnvEvento>';
    }
}

if (! function_exists('scienceChannelWithManifest')) {
    function scienceChannelWithManifest(string $canned): NFeDistChannel
    {
        return new NFeDistChannel(
            pfxContents: 'unused',
            pfxPassword: 'unused',
            cnpj: '12345678000195',
            companyName: 'Cliente Teste',
            tools: new StubNFeManifestTools($canned),
        );
    }
}

// ---------------------------------------------------------------------------
// 1. Two NF-e summaries -> ciencia manifested, 2 pending documents, actions
//    with NULL (system) actor, audits carrying the key, cursor advanced.
// ---------------------------------------------------------------------------

it('manifests science for new NF-e summaries and persists them as pending', function () {
    $client = syncClientWithCredential();
    $subscription = syncSubscriptionFor($client);
    $keyA = scienceKey('1');
    $keyB = scienceKey('2');

    $fake = new FakeDistChannel;
    $fake->queuedBatches = [new ChannelBatch(
        items: [
            scienceResumo('000000000000001', $keyA),
            scienceResumo('000000000000002', $keyB),
        ],
        lastNsu: '000000000000002',
    )];

    $result = runnerWithFakeChannel($fake)->run($subscription);

    expect($fake->manifestCalls)->toBe([$keyA, $keyB])
        ->and($result->fetched)->toBe(2);

    $docs = FiscalDocument::withoutGlobalScopes()
        ->where('client_id', $client->id)->orderBy('key')->get();

    expect($docs)->toHaveCount(2);

    foreach ($docs as $doc) {
        expect($doc->status)->toBe('pending')
            ->and($doc->has_xml)->toBeFalse()
            ->and($doc->xml_path)->toBeNull();
    }

    $actions = FiscalDocumentAction::withoutGlobalScopes()
        ->whereIn('fiscal_document_id', $docs->pluck('id'))->get();

    expect($actions)->toHaveCount(2);

    foreach ($actions as $action) {
        expect($action->type)->toBe('ciencia_210210_auto')
            ->and($action->actor_user_id)->toBeNull()
            ->and($action->metadata['key'] ?? null)->toBeIn([$keyA, $keyB]);
    }

    $audits = AuditLog::where('action', 'fiscal.science.auto')->get();

    expect($audits)->toHaveCount(2);

    foreach ($audits as $audit) {
        expect($audit->actor_user_id)->toBeNull()
            ->and($audit->origin_account_id)->toBe($client->account_id)
            ->and($audit->target_account_id)->toBe($client->account_id)
            ->and($audit->metadata['client_id'] ?? null)->toBe($client->id)
            ->and($audit->metadata['key'] ?? null)->toBeIn([$keyA, $keyB]);
    }

    expect(scienceCursorFor($client->id))->toBe('000000000000002');
});

// ---------------------------------------------------------------------------
// 2. Re-running the same batch duplicates nothing (and re-manifests nothing).
// ---------------------------------------------------------------------------

it('does not duplicate documents, actions or audits when the batch re-runs', function () {
    $client = syncClientWithCredential();
    $subscription = syncSubscriptionFor($client);
    $keyA = scienceKey('3');
    $keyB = scienceKey('4');

    $batch = fn () => new ChannelBatch(
        items: [
            scienceResumo('000000000000001', $keyA),
            scienceResumo('000000000000002', $keyB),
        ],
        lastNsu: '000000000000002',
    );

    $fake = new FakeDistChannel;
    $fake->queuedBatches = [$batch()];

    runnerWithFakeChannel($fake)->run($subscription);

    $counts = fn (): array => [
        FiscalDocument::withoutGlobalScopes()->where('client_id', $client->id)->count(),
        FiscalDocumentAction::withoutGlobalScopes()->count(),
        AuditLog::where('action', 'fiscal.science.auto')->count(),
    ];

    expect($counts())->toBe([2, 2, 2]);

    $rerun = new FakeDistChannel;
    $rerun->queuedBatches = [$batch()];

    runnerWithFakeChannel($rerun)->run($subscription->fresh());

    expect($counts())->toBe([2, 2, 2])
        ->and($rerun->manifestCalls)->toBe([]);
});

// ---------------------------------------------------------------------------
// 2b. An already-pending document left without science records (interrupted
//     earlier run) recovers them without re-manifesting, and never duplicates.
// ---------------------------------------------------------------------------

it('recovers missing science records for an already-pending document', function () {
    $client = syncClientWithCredential();
    $subscription = syncSubscriptionFor($client);
    $key = scienceKey('1');

    // Simulate the interrupted write: document persisted, action+audit lost.
    FiscalDocument::factory()->create([
        'client_id' => $client->id,
        'family' => 'nfe',
        'doc_type' => 'nfe',
        'key' => $key,
        'status' => 'pending',
        'has_xml' => false,
    ]);

    expect(FiscalDocumentAction::withoutGlobalScopes()->count())->toBe(0)
        ->and(AuditLog::where('action', 'fiscal.science.auto')->count())->toBe(0);

    $batch = fn () => new ChannelBatch(
        items: [scienceResumo('000000000000001', $key)],
        lastNsu: '000000000000001',
    );

    $fake = new FakeDistChannel;
    $fake->queuedBatches = [$batch()];

    $result = runnerWithFakeChannel($fake)->run($subscription);

    expect($fake->manifestCalls)->toBe([])
        ->and($result->fetched)->toBe(1);

    expect(FiscalDocument::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(1);

    $action = FiscalDocumentAction::withoutGlobalScopes()->firstOrFail();

    expect($action->type)->toBe('ciencia_210210_auto')
        ->and($action->actor_user_id)->toBeNull()
        ->and($action->metadata['key'] ?? null)->toBe($key);

    $audit = AuditLog::where('action', 'fiscal.science.auto')->firstOrFail();

    expect($audit->actor_user_id)->toBeNull()
        ->and($audit->metadata['client_id'] ?? null)->toBe($client->id)
        ->and($audit->metadata['key'] ?? null)->toBe($key)
        ->and(scienceCursorFor($client->id))->toBe('000000000000001');

    // A further re-run with the records present duplicates nothing.
    $rerun = new FakeDistChannel;
    $rerun->queuedBatches = [$batch()];

    runnerWithFakeChannel($rerun)->run($subscription->fresh());

    expect(FiscalDocumentAction::withoutGlobalScopes()->count())->toBe(1)
        ->and(AuditLog::where('action', 'fiscal.science.auto')->count())->toBe(1)
        ->and($rerun->manifestCalls)->toBe([]);
});

// ---------------------------------------------------------------------------
// 3. A SEFAZ science failure skips the item without fatal error and the
//    cursor never moves past it.
// ---------------------------------------------------------------------------

it('holds the cursor on science failure without raising', function () {
    $client = syncClientWithCredential();
    $subscription = syncSubscriptionFor($client);
    $keyA = scienceKey('5');
    $keyB = scienceKey('6');

    $fake = new FakeDistChannel;
    $fake->manifestFailures = [$keyB];
    $fake->queuedBatches = [new ChannelBatch(
        items: [
            scienceResumo('000000000000001', $keyA),
            scienceResumo('000000000000002', $keyB),
        ],
        lastNsu: '000000000000002',
    )];

    $result = runnerWithFakeChannel($fake)->run($subscription);

    expect($result->status)->toBe('synced')
        ->and($result->fetched)->toBe(1)
        ->and($fake->manifestCalls)->toBe([$keyA, $keyB]);

    expect(FiscalDocument::withoutGlobalScopes()->where('client_id', $client->id)->pluck('key')->all())
        ->toBe([$keyA]);

    expect(FiscalDocumentAction::withoutGlobalScopes()->count())->toBe(1)
        ->and(AuditLog::where('action', 'fiscal.science.auto')->count())->toBe(1)
        ->and(scienceCursorFor($client->id))->toBe('000000000000001');
});

// ---------------------------------------------------------------------------
// 4. CT-e summaries never trigger ciencia: persisted pending, no manifest.
// ---------------------------------------------------------------------------

it('persists CT-e summaries as pending without manifesting science', function () {
    $client = syncClientWithCredential();
    $subscription = syncSubscriptionFor($client, ['family' => 'cte']);
    $key = scienceKey('7');

    $fake = new FakeDistChannel;
    $fake->queuedBatches = [new ChannelBatch(
        items: [scienceResumo('000000000000001', $key, ['schema' => 'resCTe_v1.01.xsd'])],
        lastNsu: '000000000000001',
    )];

    $result = runnerWithFakeChannel($fake)->run($subscription);

    expect($fake->manifestCalls)->toBe([])
        ->and($result->fetched)->toBe(1);

    $doc = FiscalDocument::withoutGlobalScopes()->where('client_id', $client->id)->firstOrFail();

    expect($doc->family)->toBe('cte')
        ->and($doc->key)->toBe($key)
        ->and($doc->status)->toBe('pending')
        ->and($doc->has_xml)->toBeFalse();

    expect(FiscalDocumentAction::withoutGlobalScopes()->count())->toBe(0)
        ->and(AuditLog::where('action', 'fiscal.science.auto')->count())->toBe(0)
        ->and(scienceCursorFor($client->id, 'cte'))->toBe('000000000000001');
});

// ---------------------------------------------------------------------------
// 5. Production NF-e wrapper: evento 210210 accepted (135/136), duplicate
//    (573) counts as success, any other cStat is a refusal (false).
// ---------------------------------------------------------------------------

it('manifests ciencia 210210 through the production NF-e wrapper', function (string $cStat, bool $accepted) {
    $channel = scienceChannelWithManifest(manifestRetEvento($cStat));

    expect($channel->manifestScience(scienceKey('8')))->toBe($accepted);
})->with([
    'registrado (135)' => ['135', true],
    'registrado sem vinculo (136)' => ['136', true],
    'duplicidade (573, ja manifestada)' => ['573', true],
    'recusado (999)' => ['999', false],
]);

// ---------------------------------------------------------------------------
// 6. CT-e has no ciencia event: the wrapper refuses loudly by contract.
// ---------------------------------------------------------------------------

it('refuses ciencia on the CT-e channel because the event does not exist', function () {
    $channel = new CTeDistChannel(
        pfxContents: 'unused',
        pfxPassword: 'unused',
        cnpj: '12345678000195',
        companyName: 'Cliente Teste',
    );

    expect(fn () => $channel->manifestScience(scienceKey('9')))->toThrow(LogicException::class);
});
