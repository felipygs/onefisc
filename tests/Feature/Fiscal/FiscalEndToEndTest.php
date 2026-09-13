<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FiscalCoverageEvidence;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentAction;
use App\Models\FiscalSyncCursor;
use App\Models\FiscalSyncSubscription;
use App\Models\User;
use App\Services\Fiscal\ChannelBatch;
use App\Services\Fiscal\DistributionChannel;
use App\Services\Fiscal\FiscalChannelFactory;
use App\Services\Fiscal\FiscalPdfService;
use App\Services\Fiscal\FiscalSyncRunner;
use App\Services\Fiscal\NfseCoverageException;
use App\Services\PlanLimitService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Task 5.3 (fiscal-dfe-monitor): end-to-end composition smoke with faked
// channels. Walks the whole chain in one scenario: openssl PFX -> upload via
// endpoint -> nfe subscription -> real dispatcher + real job (sync queue,
// NO Queue::fake) with a routing fake factory injected through the existing
// seam (FiscalSyncRunner resolved via app() inside FiscalSyncJob, bound here
// as a singleton) -> resumo pending with ciencia+audit -> completion stores
// XML on Storage::fake -> DANFE renders -> volume +1 -> second Client hits
// fake ADN 404-with-proof -> evidence limited. Assertions on every link plus
// the matching audit rows. Never real SEFAZ.
// ---------------------------------------------------------------------------

if (! class_exists('E2EFakeChannel')) {
    final class E2EFakeChannel implements DistributionChannel
    {
        /** @var array<int, ChannelBatch> */
        public array $queuedBatches = [];

        /** @var array<string, array<string, mixed>|null> */
        public array $byKey = [];

        public int $fetchSinceCalls = 0;

        /** @var array<int, string> */
        public array $manifestCalls = [];

        public ?Throwable $fetchThrows = null;

        public function fetchSince(string $lastNsu): ChannelBatch
        {
            $this->fetchSinceCalls++;

            if ($this->fetchThrows !== null) {
                throw $this->fetchThrows;
            }

            return array_shift($this->queuedBatches) ?? new ChannelBatch(items: [], lastNsu: $lastNsu);
        }

        public function fetchByKey(string $key): ?array
        {
            return $this->byKey[$key] ?? null;
        }

        public function manifestScience(string $key): bool
        {
            $this->manifestCalls[] = $key;

            return true;
        }
    }
}

if (! class_exists('E2ERoutingFactory')) {
    final class E2ERoutingFactory extends FiscalChannelFactory
    {
        public function __construct(
            private readonly DistributionChannel $nfe,
            private readonly DistributionChannel $nfse,
        ) {}

        public function for(FiscalSyncSubscription $subscription): DistributionChannel
        {
            return strtolower($subscription->family) === 'nfse' ? $this->nfse : $this->nfe;
        }
    }
}

if (! function_exists('e2eOpensslConfig')) {
    function e2eOpensslConfig(): ?string
    {
        $configured = getenv('OPENSSL_CONF');

        if (is_string($configured) && is_file($configured)) {
            return $configured;
        }

        foreach (['/usr/lib/ssl/openssl.cnf', '/etc/ssl/openssl.cnf'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

if (! function_exists('e2eMakePfx')) {
    function e2eMakePfx(string $password): string
    {
        $options = ['digest_alg' => 'sha256'];

        if (is_string($config = e2eOpensslConfig())) {
            $options['config'] = $config;
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ...$options,
        ]);

        if ($key === false) {
            throw new RuntimeException('openssl_pkey_new failed');
        }

        $csr = openssl_csr_new(['CN' => 'Cliente Teste'], $key, $options);

        if ($csr === false) {
            throw new RuntimeException('openssl_csr_new failed');
        }

        $cert = openssl_csr_sign($csr, null, $key, 365, $options);

        if ($cert === false) {
            throw new RuntimeException('openssl_csr_sign failed');
        }

        $pfx = '';

        if (! openssl_pkcs12_export($cert, $pfx, $key, $password)) {
            throw new RuntimeException('openssl_pkcs12_export failed');
        }

        return $pfx;
    }
}

if (! function_exists('e2eResumo')) {
    /**
     * @return array<string, mixed>
     */
    function e2eResumo(string $nsu, string $key): array
    {
        return [
            'nsu' => $nsu,
            'schema' => 'resNFe_v1.01.xsd',
            'key' => $key,
            'xml' => '<resNFe versao="1.01"><chNFe>'.$key.'</chNFe>'
                .'<xNome>Emitente Teste</xNome><CNPJ>12345678000195</CNPJ>'
                .'<dhEmi>2026-09-12T10:00:00-03:00</dhEmi></resNFe>',
        ];
    }
}

if (! function_exists('e2eNfeXml')) {
    function e2eNfeXml(string $key): string
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

it('walks the full fiscal chain with faked channels and honest limited coverage', function () {
    Storage::fake('local');

    // -- Elo 1: conta + admin + dois Clients (A: NF-e feliz, B: NFS-e nao aderente).
    $account = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);
    $this->actingAs($admin);

    $clientA = Client::factory()->create(['account_id' => $account->id]);
    $clientB = Client::factory()->create(['account_id' => $account->id]);

    // -- Elo 2: upload do A1 via endpoint (certificado real gerado via openssl).
    $pfxPassword = 'e2e-'.Str::random(10);
    $this->post(route('certificates.store', $clientA), [
        'pfx' => UploadedFile::fake()->createWithContent('certificado.pfx', e2eMakePfx($pfxPassword)),
        'password' => $pfxPassword,
    ])->assertRedirect()->assertSessionHas('status');

    $credentialA = ClientCredential::withoutGlobalScopes()->where('client_id', $clientA->id)->firstOrFail();

    expect($credentialA->expires_at)->not->toBeNull()
        ->and($credentialA->expires_at->isFuture())->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'certificates.store',
        'actor_user_id' => $admin->id,
        'origin_account_id' => $account->id,
    ]);

    // Client B: credencial valida via factory (o foco aqui e a cobertura, nao o upload).
    ClientCredential::factory()->create([
        'client_id' => $clientB->id,
        'pfx_data' => 'encrypted-pfx-fixture',
        'pfx_password' => 'encrypted-password-fixture',
        'expires_at' => now()->addYear(),
    ]);

    // -- Elo 3: subscriptions (A: nfe, B: nfse) vencidas para o dispatcher.
    $subA = FiscalSyncSubscription::factory()->create([
        'client_id' => $clientA->id,
        'family' => 'nfe',
        'environment' => 'production',
        'next_run_at' => now()->subMinutes(5),
        'blocked_until' => null,
    ]);
    $subB = FiscalSyncSubscription::factory()->create([
        'client_id' => $clientB->id,
        'family' => 'nfse',
        'environment' => 'production',
        'next_run_at' => now()->subMinutes(5),
        'blocked_until' => null,
    ]);

    // -- Elo 4: canais fake roteados por familia + runner real via singleton.
    $key = str_repeat('1', 44);
    $fullXml = e2eNfeXml($key);

    $nfe = new E2EFakeChannel;
    $nfe->queuedBatches = [new ChannelBatch(
        items: [e2eResumo('000000000000001', $key)],
        lastNsu: '000000000000001',
    )];
    $nfe->byKey[$key] = ['key' => $key, 'xml' => $fullXml];

    $nfse = new E2EFakeChannel;
    $nfse->fetchThrows = NfseCoverageException::limited('adn_coverage_unavailable', [
        'channel' => 'adn',
        'http_status' => 404,
    ]);

    $this->app->singleton(
        FiscalSyncRunner::class,
        fn () => new FiscalSyncRunner(new E2ERoutingFactory($nfe, $nfse))
    );

    // QUEUE_CONNECTION=sync no phpunit: o dispatcher roda os jobs de verdade.
    $this->artisan('fiscal:sync-dispatch')->assertSuccessful();

    // -- Elo 5: dispatcher avancou a janela das duas subscriptions (sem re-dispatch).
    foreach ([$subA, $subB] as $sub) {
        $nextRunAt = FiscalSyncSubscription::withoutGlobalScopes()->findOrFail($sub->id)->next_run_at;

        expect($nextRunAt->greaterThan(now()->addMinutes(50)))->toBeTrue()
            ->and($nextRunAt->lessThanOrEqualTo(now()->addMinutes(75)))->toBeTrue();
    }

    // -- Elo 6: resumo virou pending com ciencia (sistema) + audits.
    expect($nfe->manifestCalls)->toBe([$key]);

    $doc = FiscalDocument::withoutGlobalScopes()->where('client_id', $clientA->id)->firstOrFail();

    expect($doc->key)->toBe($key)
        ->and($doc->family)->toBe('nfe')
        ->and($doc->status)->toBe('pending');

    $action = FiscalDocumentAction::withoutGlobalScopes()
        ->where('fiscal_document_id', $doc->id)->firstOrFail();

    expect($action->type)->toBe('ciencia_210210_auto')
        ->and($action->actor_user_id)->toBeNull()
        ->and($action->metadata['key'] ?? null)->toBe($key);

    $scienceAudit = AuditLog::where('action', 'fiscal.science.auto')->firstOrFail();

    expect($scienceAudit->actor_user_id)->toBeNull()
        ->and($scienceAudit->origin_account_id)->toBe($account->id)
        ->and($scienceAudit->target_account_id)->toBe($account->id)
        ->and($scienceAudit->metadata['client_id'] ?? null)->toBe($clientA->id)
        ->and($scienceAudit->metadata['key'] ?? null)->toBe($key);

    expect(FiscalSyncCursor::withoutGlobalScopes()
        ->where('client_id', $clientA->id)->where('family', 'nfe')
        ->firstOrFail()->getAttribute('last_nsu'))->toBe('000000000000001');

    // -- Elo 7: completion guardou o XML intacto + metadados enriquecidos.
    $doc = FiscalDocument::withoutGlobalScopes()->findOrFail($doc->id);

    // Completion preenche SOMENTE os campos que o resumo deixou em branco
    // (nunca sobrescreve): issuer veio do resumo, number/series/recipient do XML.
    expect($doc->has_xml)->toBeTrue()
        ->and($doc->xml_path)->toBe("fiscal/{$account->id}/{$clientA->id}/{$doc->id}.xml")
        ->and(Storage::disk('local')->get($doc->xml_path))->toBe($fullXml)
        ->and($doc->issuer_name)->toBe('Emitente Teste')
        ->and($doc->issuer_tax_id)->toBe('12345678000195')
        ->and($doc->recipient_name)->toBe('Destinatario Completo SA')
        ->and($doc->number)->toBe('12345')
        ->and($doc->series)->toBe('1');

    // -- Elo 8: DANFE renderizou (bytes %PDF guardados, sem DACTE envolvido).
    $doc = FiscalDocument::withoutGlobalScopes()->findOrFail($doc->id);

    expect($doc->has_danfe)->toBeTrue()
        ->and($doc->pdf_path)->toBe("fiscal/{$account->id}/{$clientA->id}/{$doc->id}.pdf")
        ->and(Storage::disk('local')->get($doc->pdf_path))->toStartWith('%PDF')
        ->and(app(FiscalPdfService::class)->renderFor($doc->fresh()))->toStartWith('%PDF');

    // -- Elo 9: volume contou exatamente 1 (completion nao reconta).
    expect(app(PlanLimitService::class)->volumeConsumed($account->refresh()))->toBe(1);

    // -- Elo 10: ciclo auditado (A: ok com 1 novo; B: blocked/limited).
    $cycleA = AuditLog::where('action', 'fiscal.sync.cycle')
        ->where('metadata->client_id', $clientA->id)->firstOrFail();

    expect($cycleA->actor_user_id)->toBeNull()
        ->and($cycleA->metadata['family'] ?? null)->toBe('nfe')
        ->and($cycleA->metadata['new_documents'] ?? null)->toBe(1)
        ->and($cycleA->metadata['result'] ?? null)->toBe('ok');

    $cycleB = AuditLog::where('action', 'fiscal.sync.cycle')
        ->where('metadata->client_id', $clientB->id)->firstOrFail();

    expect($cycleB->actor_user_id)->toBeNull()
        ->and($cycleB->metadata['family'] ?? null)->toBe('nfse')
        ->and($cycleB->metadata['result'] ?? null)->toBe('blocked')
        ->and($cycleB->metadata['status'] ?? null)->toBe('limited');

    // -- Elo 11: municipio nao aderente -> evidence limited terminal, sem docs.
    $evidence = FiscalCoverageEvidence::withoutGlobalScopes()
        ->where('client_id', $clientB->id)->where('family', 'nfse')->firstOrFail();

    expect($evidence->status)->toBe('limited')
        ->and($evidence->reason)->toBe('adn_coverage_unavailable')
        ->and($evidence->evidence)->toMatchArray(['channel' => 'adn', 'http_status' => 404]);

    expect(FiscalDocument::withoutGlobalScopes()->where('client_id', $clientB->id)->count())->toBe(0);

    // Terminal: o proximo ciclo nao reconsulta o canal.
    $callsBefore = $nfse->fetchSinceCalls;

    expect(app(FiscalSyncRunner::class)->run($subB->fresh())->status)->toBe('limited')
        ->and($nfse->fetchSinceCalls)->toBe($callsBefore);
});
