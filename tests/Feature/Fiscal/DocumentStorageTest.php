<?php

use App\Models\Account;
use App\Models\FiscalDocument;
use App\Services\Fiscal\ChannelBatch;
use App\Services\Fiscal\FiscalPdfService;
use App\Services\Fiscal\FiscalStorageService;
use App\Services\PlanLimitService;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\Storage;

// ---------------------------------------------------------------------------
// Task 4.1: private-disk XML storage + metadata with DANFE/DANFSe (no DACTE).
// RED first: FiscalStorageService / FiscalPdfService / completion step do not
// exist yet. Fakes of the INTERNAL channel interface only, canned XML
// fixtures, Storage::fake — never real SEFAZ.
// Reuses FakeDistChannel / runnerWithFakeChannel / syncClientWithCredential /
// syncSubscriptionFor / scienceKey from SyncChannelsTest + ScienceAutoTest.
// ---------------------------------------------------------------------------

if (! function_exists('storageKey50')) {
    function storageKey50(string $digit): string
    {
        return str_repeat($digit, 50);
    }
}

if (! function_exists('nfeFullXml')) {
    function nfeFullXml(string $key): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'
            .'<NFe><infNFe Id="NFe'.$key.'" versao="4.00">'
            .'<ide><cUF>35</cUF><cNF>12345678</cNF><natOp>VENDA</natOp><mod>55</mod>'
            .'<serie>1</serie><nNF>12345</nNF>'
            .'<dhEmi>2026-06-10T10:00:00-03:00</dhEmi><tpNF>1</tpNF><tpAmb>1</tpAmb>'
            .'<tpEmis>1</tpEmis><cDV>0</cDV><tpImp>1</tpImp><finNFe>1</finNFe><indFinal>1</indFinal></ide>'
            .'<emit><CNPJ>12345678000195</CNPJ><xNome>Emitente Completo LTDA</xNome>'
            .'<enderEmit><xLgr>Rua A</xLgr><nro>1</nro><xBairro>Centro</xBairro>'
            .'<cMun>3550308</cMun><xMun>Sao Paulo</xMun><UF>SP</UF><CEP>01000000</CEP></enderEmit></emit>'
            .'<dest><CNPJ>98765432000110</CNPJ><xNome>Destinatario Completo SA</xNome>'
            .'<enderDest><xLgr>Rua B</xLgr><nro>2</nro><xBairro>Centro</xBairro>'
            .'<cMun>3550308</cMun><xMun>Sao Paulo</xMun><UF>SP</UF><CEP>01000000</CEP></enderDest></dest>'
            .'<det nItem="1"><prod><cProd>1</cProd><xProd>Produto Teste</xProd>'
            .'<NCM>12345678</NCM><CFOP>5102</CFOP><uCom>UN</uCom><qCom>1.0000</qCom>'
            .'<vUnCom>10.00</vUnCom><vProd>10.00</vProd><uTrib>UN</uTrib><qTrib>1.0000</qTrib>'
            .'<vUnTrib>10.00</vUnTrib><indTot>1</indTot></prod>'
            .'<imposto><ICMS><ICMS00><orig>0</orig><CST>00</CST><modBC>0</modBC>'
            .'<vBC>10.00</vBC><pICMS>18.00</pICMS><vICMS>1.80</vICMS></ICMS00></ICMS>'
            .'<PIS><PISNT><CST>07</CST></PISNT></PIS>'
            .'<COFINS><COFINSNT><CST>07</CST></COFINSNT></COFINS></imposto></det>'
            .'<total><ICMSTot><vBC>10.00</vBC><vICMS>1.80</vICMS><vICMSDeson>0.00</vICMSDeson>'
            .'<vFCP>0.00</vFCP><vBCST>0.00</vBCST><vST>0.00</vST><vFCPST>0.00</vFCPST>'
            .'<vProd>10.00</vProd><vFrete>0.00</vFrete><vSeg>0.00</vSeg><vDesc>0.00</vDesc>'
            .'<vII>0.00</vII><vIPI>0.00</vIPI><vIPIDevol>0.00</vIPIDevol><vPIS>0.00</vPIS>'
            .'<vCOFINS>0.00</vCOFINS><vOutro>0.00</vOutro><vNF>10.00</vNF></ICMSTot></total>'
            .'<transp><modFrete>9</modFrete></transp>'
            .'<pag><detPag><tPag>01</tPag><vPag>10.00</vPag></detPag></pag>'
            .'</infNFe></NFe>'
            .'<protNFe versao="4.00"><infProt><tpAmb>1</tpAmb><verAplic>SP_1.0</verAplic>'
            .'<chNFe>'.$key.'</chNFe><dhRecbto>2026-06-10T10:05:00-03:00</dhRecbto>'
            .'<cStat>100</cStat><xMotivo>Autorizado</xMotivo></infProt></protNFe></nfeProc>';
    }
}

if (! function_exists('nfseFullXml')) {
    function nfseFullXml(string $key): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse">'
            .'<infNFSe Id="NFSe'.$key.'" versao="1.00">'
            .'<nNFSe>98765</nNFSe><cStat>100</cStat>'
            .'<dhProc>2026-06-10T10:05:00-03:00</dhProc><ambGer>1</ambGer>'
            .'<emit><CNPJ>12345678000195</CNPJ><xNome>Prestador Nacional LTDA</xNome>'
            .'<enderNac><cMun>3550308</cMun><CEP>01000000</CEP></enderNac></emit>'
            .'<infDPS Id="DPS'.$key.'" versao="1.00">'
            .'<tpAmb>1</tpAmb><dCompet>2026-06-10</dCompet><tpEmit>1</tpEmit><finNFSe>1</finNFSe>'
            .'<nDPS>54321</nDPS><serie>1</serie><dhEmi>2026-06-10T10:00:00-03:00</dhEmi>'
            .'<cTribNac>010101</cTribNac>'
            .'<prest><CNPJ>12345678000195</CNPJ><xNome>Prestador Nacional LTDA</xNome></prest>'
            .'<toma><CNPJ>98765432000110</CNPJ><xNome>Tomador Completo SA</xNome></toma>'
            .'<serv><cServ><cTribNac>010101</cTribNac><xDescServ>Servico de teste</xDescServ></cServ></serv>'
            .'<valores><vServPrest><vReceb>100.00</vReceb></vServPrest></valores>'
            .'</infDPS></infNFSe></NFSe>';
    }
}

if (! function_exists('pendingDocForCompletion')) {
    function pendingDocForCompletion(object $client, string $key, array $overrides = []): FiscalDocument
    {
        return FiscalDocument::factory()->create(array_merge([
            'client_id' => $client->id,
            'family' => 'nfe',
            'doc_type' => 'nfe',
            'number' => null,
            'series' => null,
            'key' => $key,
            'issuer_name' => null,
            'issuer_tax_id' => null,
            'recipient_name' => null,
            'recipient_tax_id' => null,
            'emission_at' => null,
            'status' => 'pending',
            'has_xml' => false,
            'has_danfe' => false,
            'xml_path' => null,
            'pdf_path' => null,
        ], $overrides));
    }
}

// ---------------------------------------------------------------------------
// 1. Pending + fake channel delivers the full XML -> has_xml=true, intact
//    bytes on disk, enriched metadata, volume counted once (not twice).
// ---------------------------------------------------------------------------

it('completes a pending document with the channel xml without double-counting volume', function () {
    Storage::fake('local');

    $client = syncClientWithCredential();
    $subscription = syncSubscriptionFor($client);
    $key = scienceKey('1');
    $xml = nfeFullXml($key);

    pendingDocForCompletion($client, $key);

    $fake = new FakeDistChannel;
    $fake->queuedBatches = [new ChannelBatch(items: [], lastNsu: '0')];
    $fake->byKey[$key] = ['key' => $key, 'xml' => $xml];

    $result = runnerWithFakeChannel($fake)->run($subscription);

    expect($result->status)->toBe('empty');

    $doc = FiscalDocument::withoutGlobalScopes()->where('client_id', $client->id)->firstOrFail();

    expect($doc->has_xml)->toBeTrue()
        ->and($doc->xml_path)->toBe("fiscal/{$client->account_id}/{$client->id}/{$doc->id}.xml")
        ->and(Storage::disk('local')->get($doc->xml_path))->toBe($xml)
        ->and($doc->issuer_name)->toBe('Emitente Completo LTDA')
        ->and($doc->issuer_tax_id)->toBe('12345678000195')
        ->and($doc->recipient_name)->toBe('Destinatario Completo SA')
        ->and($doc->recipient_tax_id)->toBe('98765432000110')
        ->and($doc->number)->toBe('12345')
        ->and($doc->series)->toBe('1')
        ->and($doc->emission_at)->not->toBeNull();

    // The download updates the existing row: volume still counts exactly the
    // one persisted document (row counting happened at persistence time).
    $account = Account::withoutGlobalScopes()->findOrFail($client->account_id);

    expect(app(PlanLimitService::class)->volumeConsumed($account))->toBe(1);
});

// ---------------------------------------------------------------------------
// 2. Retention: a document older than the 3-month SEFAZ window stays
//    accessible while the Client is active.
// ---------------------------------------------------------------------------

it('keeps old documents accessible beyond the SEFAZ window', function () {
    Storage::fake('local');

    $client = syncClientWithCredential();
    $key = scienceKey('2');
    $xml = nfeFullXml($key);

    $doc = pendingDocForCompletion($client, $key, ['emission_at' => now()->subMonths(4)]);
    app(FiscalStorageService::class)->putXml($doc, $xml);

    $stored = FiscalDocument::withoutGlobalScopes()->findOrFail($doc->id);

    expect($stored->has_xml)->toBeTrue()
        ->and(app(FiscalStorageService::class)->existsXml($stored))->toBeTrue()
        ->and(app(FiscalStorageService::class)->getXml($stored))->toBe($xml);
});

// ---------------------------------------------------------------------------
// 3. Isolation: storage paths carry ids only (never CNPJ/key), and a Client
//    from another Account cannot read the document (scope + path).
// ---------------------------------------------------------------------------

it('isolates stored xml by account ids without taxpayer identifiers', function () {
    Storage::fake('local');

    $clientA = syncClientWithCredential();
    $clientB = syncClientWithCredential();

    expect($clientB->account_id)->not->toBe($clientA->account_id);

    $key = scienceKey('3');

    $docA = pendingDocForCompletion($clientA, $key);
    app(FiscalStorageService::class)->putXml($docA, nfeFullXml($key));

    $path = FiscalDocument::withoutGlobalScopes()->findOrFail($docA->id)->xml_path;

    expect($path)->toContain((string) $clientA->account_id)
        ->and($path)->toContain((string) $clientA->id)
        ->and($path)->not->toContain($clientA->cnpj)
        ->and($path)->not->toContain($key);

    CurrentAccount::set(Account::withoutGlobalScopes()->findOrFail($clientB->account_id));

    try {
        expect(FiscalDocument::find($docA->id))->toBeNull();

        $docB = pendingDocForCompletion($clientB, scienceKey('4'));
        app(FiscalStorageService::class)->putXml($docB, nfeFullXml(scienceKey('4')));

        expect(FiscalDocument::withoutGlobalScopes()->findOrFail($docB->id)->xml_path)
            ->not->toBe($path);
    } finally {
        CurrentAccount::clear();
    }
});

// ---------------------------------------------------------------------------
// 4. DANFE for nfe with XML -> valid PDF (%PDF); DANFSe for nfse likewise;
//    CT-e -> honest exception / absence (has_danfe=false, no DACTE in v1).
// ---------------------------------------------------------------------------

it('renders DANFE and DANFSe pdfs and honestly refuses DACTE for CT-e', function () {
    Storage::fake('local');

    $client = syncClientWithCredential();

    $nfeKey = scienceKey('5');
    $nfe = pendingDocForCompletion($client, $nfeKey);
    app(FiscalStorageService::class)->putXml($nfe, nfeFullXml($nfeKey));

    $pdf = app(FiscalPdfService::class)->renderDanfe($nfe->fresh());

    expect($pdf)->toStartWith('%PDF');

    $nfeStored = FiscalDocument::withoutGlobalScopes()->findOrFail($nfe->id);

    expect($nfeStored->has_danfe)->toBeTrue()
        ->and($nfeStored->pdf_path)->toBe("fiscal/{$client->account_id}/{$client->id}/{$nfe->id}.pdf")
        ->and(Storage::disk('local')->get($nfeStored->pdf_path))->toStartWith('%PDF');

    $nfseKey = storageKey50('7');
    $nfse = pendingDocForCompletion($client, $nfseKey, ['family' => 'nfse', 'doc_type' => 'nfse']);
    app(FiscalStorageService::class)->putXml($nfse, nfseFullXml($nfseKey));

    $sePdf = app(FiscalPdfService::class)->renderDanfse($nfse->fresh());

    expect($sePdf)->toStartWith('%PDF');

    expect(FiscalDocument::withoutGlobalScopes()->findOrFail($nfse->id)->has_danfe)->toBeTrue();

    $cte = pendingDocForCompletion($client, scienceKey('6'), ['family' => 'cte', 'doc_type' => 'cte']);

    expect(fn () => app(FiscalPdfService::class)->renderDanfe($cte))->toThrow(LogicException::class);
    expect(fn () => app(FiscalPdfService::class)->renderDanfse($cte))->toThrow(LogicException::class);

    expect(FiscalDocument::withoutGlobalScopes()->findOrFail($cte->id)->has_danfe)->toBeFalse();
});

// ---------------------------------------------------------------------------
// 5. Migration key-50: a 50-digit national NFS-e key persists.
// ---------------------------------------------------------------------------

it('persists a 50-digit national NFS-e access key', function () {
    $client = syncClientWithCredential();
    $key = storageKey50('9');

    $doc = FiscalDocument::factory()->create([
        'client_id' => $client->id,
        'family' => 'nfse',
        'doc_type' => 'nfse',
        'key' => $key,
    ]);

    expect(FiscalDocument::withoutGlobalScopes()->findOrFail($doc->id)->key)->toBe($key);
});
