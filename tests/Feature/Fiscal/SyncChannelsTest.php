<?php

use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FiscalSyncCursor;
use App\Models\FiscalSyncSubscription;
use App\Services\Fiscal\ChannelBatch;
use App\Services\Fiscal\CTeDistChannel;
use App\Services\Fiscal\DistDfeParser;
use App\Services\Fiscal\DistributionChannel;
use App\Services\Fiscal\FiscalChannelFactory;
use App\Services\Fiscal\FiscalSyncRunner;
use App\Services\Fiscal\NFeDistChannel;
use Illuminate\Support\Carbon;
use NFePHP\CTe\Tools as CTeTools;
use NFePHP\NFe\Tools as NFeTools;

// ---------------------------------------------------------------------------
// Fakes of the INTERNAL interface (never the vendor, never real SEFAZ).
// ---------------------------------------------------------------------------

final class FakeDistChannel implements DistributionChannel
{
    /** @var array<int, ChannelBatch> */
    public array $queuedBatches = [];

    /** @var array<string, array<string, mixed>|null> */
    public array $byKey = [];

    public int $fetchSinceCalls = 0;

    /** @var array<int, string> */
    public array $requestedNsus = [];

    public function fetchSince(string $lastNsu): ChannelBatch
    {
        $this->fetchSinceCalls++;
        $this->requestedNsus[] = $lastNsu;

        return array_shift($this->queuedBatches) ?? new ChannelBatch(items: [], lastNsu: $lastNsu);
    }

    public function fetchByKey(string $key): ?array
    {
        return $this->byKey[$key] ?? null;
    }
}

function runnerWithFakeChannel(FakeDistChannel $fake): FiscalSyncRunner
{
    $factory = new class($fake) extends FiscalChannelFactory
    {
        public function __construct(private readonly DistributionChannel $channel) {}

        public function for(FiscalSyncSubscription $subscription): DistributionChannel
        {
            return $this->channel;
        }
    };

    return new FiscalSyncRunner($factory);
}

function syncClientWithCredential(array $credentialOverrides = []): Client
{
    $client = Client::factory()->create();

    ClientCredential::factory()->create(array_merge([
        'client_id' => $client->id,
        'pfx_data' => 'encrypted-pfx-fixture',
        'pfx_password' => 'encrypted-password-fixture',
        'expires_at' => now()->addYear(),
    ], $credentialOverrides));

    return $client;
}

function syncSubscriptionFor(Client $client, array $overrides = []): FiscalSyncSubscription
{
    return FiscalSyncSubscription::factory()->create(array_merge([
        'client_id' => $client->id,
        'family' => 'nfe',
        'environment' => 'production',
        'next_run_at' => now()->subMinutes(5),
        'blocked_until' => null,
    ], $overrides));
}

function distDfeSoap(string $innerXml): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">'
        .'<soap:Body><nfeResultMsg>'.$innerXml.'</nfeResultMsg></soap:Body></soap:Envelope>';
}

function distDfeRet(string $cStat, string $ultNsu, string $lote = ''): string
{
    return '<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
        ."<tpAmb>1</tpAmb><verAplic>AN_1.0</verAplic><cStat>{$cStat}</cStat>"
        .'<xMotivo>fixture</xMotivo><dhResp>2026-09-12T10:00:00-03:00</dhResp>'
        ."<ultNSU>{$ultNsu}</ultNSU><maxNSU>000000000000100</maxNSU>{$lote}</retDistDFeInt>";
}

function docZipItem(string $nsu, string $schema, string $payload): string
{
    return '<docZip NSU="'.$nsu.'" schema="'.$schema.'">'.base64_encode((string) gzencode($payload)).'</docZip>';
}

// ---------------------------------------------------------------------------
// Vendor Tools stubs (canned SOAP, never the network). The production
// channel wrappers are built with these injected, so fetchByKey delegation
// + family flag run for real.
// ---------------------------------------------------------------------------

final class StubNFeTools extends NFeTools
{
    public function __construct(private readonly string $canned) {}

    public function sefazConsultaChave(string $chave, ?int $tpAmb = null): string
    {
        return $this->canned;
    }
}

final class StubCTeTools extends CTeTools
{
    public function __construct(private readonly string $canned) {}

    public function sefazConsultaChave($chave, $tpAmb = null)
    {
        return $this->canned;
    }
}

function consSitFound(string $family, string $key): string
{
    $ret = $family === 'cte' ? 'retConsSitCTe' : 'retConsSitNFe';
    $prot = $family === 'cte' ? 'protCTe' : 'protNFe';
    $keyTag = $family === 'cte' ? 'chCTe' : 'chNFe';

    return "<{$ret} xmlns=\"http://www.portalfiscal.inf.br/nfe\" versao=\"4.00\">"
        .'<tpAmb>1</tpAmb><verAplic>SP_1.0</verAplic><cStat>100</cStat><xMotivo>Autorizado</xMotivo>'
        ."<{$prot} versao=\"4.00\"><infProt><tpAmb>1</tpAmb><verAplic>SP_1.0</verAplic>"
        ."<{$keyTag}>{$key}</{$keyTag}><dhRecbto>2026-09-12T10:00:00-03:00</dhRecbto><cStat>100</cStat>"
        ."</infProt></{$prot}></{$ret}>";
}

function consSitMissing(string $family): string
{
    $ret = $family === 'cte' ? 'retConsSitCTe' : 'retConsSitNFe';

    return "<{$ret} xmlns=\"http://www.portalfiscal.inf.br/nfe\" versao=\"4.00\">"
        .'<tpAmb>1</tpAmb><verAplic>SP_1.0</verAplic><cStat>217</cStat><xMotivo>Documento nao consta</xMotivo>'
        ."</{$ret}>";
}

function channelWithCannedConsult(string $family, string $canned): DistributionChannel
{
    if ($family === 'cte') {
        return new CTeDistChannel(
            pfxContents: 'unused',
            pfxPassword: 'unused',
            cnpj: '12345678000195',
            companyName: 'Cliente Teste',
            tools: new StubCTeTools($canned),
        );
    }

    return new NFeDistChannel(
        pfxContents: 'unused',
        pfxPassword: 'unused',
        cnpj: '12345678000195',
        companyName: 'Cliente Teste',
        tools: new StubNFeTools($canned),
    );
}

// ---------------------------------------------------------------------------
// 1. Chained batches advance the cursor; an empty round keeps it.
// ---------------------------------------------------------------------------

it('advances the cursor through chained batches and holds it when there is nothing new', function () {
    $client = syncClientWithCredential();
    $subscription = syncSubscriptionFor($client);

    $fake = new FakeDistChannel;
    $fake->queuedBatches = [
        new ChannelBatch(items: [['nsu' => '000000000000001'], ['nsu' => '000000000000002']], lastNsu: '000000000000002'),
        new ChannelBatch(items: [['nsu' => '000000000000003']], lastNsu: '000000000000003'),
    ];

    $result = runnerWithFakeChannel($fake)->run($subscription);

    expect($result->status)->toBe('synced')
        ->and($result->fetched)->toBe(3)
        ->and($result->lastNsu)->toBe('000000000000003');

    expect(FiscalSyncCursor::withoutGlobalScopes()->where('client_id', $client->id)->firstOrFail()->last_nsu)
        ->toBe('000000000000003');

    // Second round: SEFAZ has nothing new, cursor must not move.
    $second = runnerWithFakeChannel($fake)->run($subscription->fresh());

    expect($second->status)->toBe('empty')
        ->and($second->fetched)->toBe(0)
        ->and(FiscalSyncCursor::withoutGlobalScopes()->where('client_id', $client->id)->firstOrFail()->last_nsu)
        ->toBe('000000000000003');
});

// ---------------------------------------------------------------------------
// 2. SEFAZ pause codes (137 / 656) block until the next window, keep cursor.
// ---------------------------------------------------------------------------

it('blocks until the next window on SEFAZ pause without moving the cursor', function (string $pause) {
    Carbon::setTestNow('2026-09-12 10:23:00');

    try {
        $client = syncClientWithCredential();
        $subscription = syncSubscriptionFor($client);

        FiscalSyncCursor::factory()->create([
            'client_id' => $client->id,
            'family' => 'nfe',
            'environment' => 'production',
            'last_nsu' => '000000000000005',
        ]);

        $fake = new FakeDistChannel;
        $fake->queuedBatches = [new ChannelBatch(items: [], lastNsu: '000000000000005', pause: $pause)];

        $result = runnerWithFakeChannel($fake)->run($subscription);

        expect($result->status)->toBe('paused')
            ->and($result->pause)->toBe($pause);

        $fresh = $subscription->fresh();
        expect($fresh->blocked_until)->not->toBeNull()
            ->and($fresh->blocked_until->equalTo(Carbon::parse('2026-09-12 11:00:00')))->toBeTrue();

        expect(FiscalSyncCursor::withoutGlobalScopes()->where('client_id', $client->id)->firstOrFail()->last_nsu)
            ->toBe('000000000000005');
    } finally {
        Carbon::setTestNow();
    }
})->with(['sefaz_no_documents', 'sefaz_overuse']);

// ---------------------------------------------------------------------------
// 3. Key lookup through the PRODUCTION wrappers (stubbed vendor transport,
//    canned SOAP — never the network, never the fake).
// ---------------------------------------------------------------------------

it('resolves key lookup through the production channel wrappers', function (string $family) {
    $key = str_repeat('5', 44);

    $found = channelWithCannedConsult($family, distDfeSoap(consSitFound($family, $key)));
    $missing = channelWithCannedConsult($family, distDfeSoap(consSitMissing($family)));

    expect($found->fetchByKey($key))->toMatchArray(['key' => $key])
        ->and($missing->fetchByKey(str_repeat('9', 44)))->toBeNull();
})->with(['nfe', 'cte']);

// ---------------------------------------------------------------------------
// 4. Missing / expired credential suspends without touching SEFAZ.
// ---------------------------------------------------------------------------

it('suspends without calling SEFAZ when the credential is missing', function () {
    $client = Client::factory()->create();
    $subscription = syncSubscriptionFor($client);

    $fake = new FakeDistChannel;

    $result = runnerWithFakeChannel($fake)->run($subscription);

    expect($result->status)->toBe('suspended')
        ->and($fake->fetchSinceCalls)->toBe(0)
        ->and(FiscalSyncCursor::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(0);
});

it('suspends without calling SEFAZ when the credential is expired', function () {
    $client = syncClientWithCredential(['expires_at' => now()->subDay()]);
    $subscription = syncSubscriptionFor($client);

    $fake = new FakeDistChannel;

    $result = runnerWithFakeChannel($fake)->run($subscription);

    expect($result->status)->toBe('suspended')
        ->and($fake->fetchSinceCalls)->toBe(0);
});

it('suspends without calling SEFAZ when the certificate password is blank', function () {
    $client = syncClientWithCredential(['pfx_password' => '']);
    $subscription = syncSubscriptionFor($client);

    $fake = new FakeDistChannel;

    $result = runnerWithFakeChannel($fake)->run($subscription);

    expect($result->status)->toBe('suspended')
        ->and($fake->fetchSinceCalls)->toBe(0)
        ->and(FiscalSyncCursor::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 5. Real response parsing (canned XML, no network): batch + pause + consult.
// ---------------------------------------------------------------------------

it('parses a 138 distribution batch into items with nsu and lastNsu', function () {
    $lote = '<loteDistDFeInt>'
        .docZipItem('000000000000002', 'resNFe_v1.01.xsd', '<resNFe>one</resNFe>')
        .docZipItem('000000000000003', 'resNFe_v1.01.xsd', '<resNFe>two</resNFe>')
        .'</loteDistDFeInt>';

    $batch = DistDfeParser::parseBatch(distDfeSoap(distDfeRet('138', '000000000000003', $lote)));

    expect($batch->pause)->toBeNull()
        ->and($batch->lastNsu)->toBe('000000000000003')
        ->and($batch->items)->toHaveCount(2)
        ->and($batch->items[0]['nsu'])->toBe('000000000000002')
        ->and($batch->items[0]['xml'])->toContain('one');
});

it('translates 137 and 656 into pause flags', function (string $cStat, string $pause) {
    $batch = DistDfeParser::parseBatch(distDfeSoap(distDfeRet($cStat, '000000000000005')));

    expect($batch->pause)->toBe($pause)
        ->and($batch->items)->toBe([]);
})->with([
    'nada localizado (137)' => ['137', 'sefaz_no_documents'],
    'consumo indevido (656)' => ['656', 'sefaz_overuse'],
]);

it('parses a key consult into a document array and null when absent', function () {
    $key = str_repeat('3', 44);

    expect(DistDfeParser::parseConsult(distDfeSoap(consSitFound('nfe', $key)), 'nfe'))->toMatchArray(['key' => $key])
        ->and(DistDfeParser::parseConsult(distDfeSoap(consSitMissing('nfe')), 'nfe'))->toBeNull();
});

it('rejects the nfse family until its channel lands in task 3.4', function () {
    $client = syncClientWithCredential();
    $subscription = syncSubscriptionFor($client, ['family' => 'nfse']);

    expect(fn () => (new FiscalChannelFactory)->for($subscription))
        ->toThrow(InvalidArgumentException::class);
});
