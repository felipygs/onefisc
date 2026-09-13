<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FiscalSyncSubscription;
use App\Models\User;
use App\Services\Fiscal\ChannelBatch;
use App\Services\Fiscal\DistributionChannel;
use App\Services\Fiscal\FiscalChannelFactory;
use App\Services\Fiscal\FiscalSyncRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Task 5.1 (fiscal-dfe-monitor): per-cycle sync audit + secret-free trails +
// readable notification bodies. This file is SELF-CONTAINED (own fakes and
// factories, audit-* names): it runs green/red standalone as well as in the
// full suite. Fakes cover the INTERNAL channel interface only, never vendor
// transports and never the network.
// ---------------------------------------------------------------------------

if (! class_exists('AuditFakeChannel')) {
    final class AuditFakeChannel implements DistributionChannel
    {
        /** @var array<int, ChannelBatch> */
        public array $queuedBatches = [];

        /** @var array<int, string> */
        public array $manifestCalls = [];

        /** @var array<int, string> */
        public array $manifestFailures = [];

        public function fetchSince(string $lastNsu): ChannelBatch
        {
            return array_shift($this->queuedBatches) ?? new ChannelBatch(items: [], lastNsu: $lastNsu);
        }

        public function fetchByKey(string $key): ?array
        {
            return null;
        }

        public function manifestScience(string $key): bool
        {
            $this->manifestCalls[] = $key;

            return ! in_array($key, $this->manifestFailures, true);
        }
    }
}

if (! class_exists('AuditThrowingChannel')) {
    final class AuditThrowingChannel implements DistributionChannel
    {
        public function __construct(private readonly string $message) {}

        public function fetchSince(string $lastNsu): ChannelBatch
        {
            throw new RuntimeException($this->message);
        }

        public function fetchByKey(string $key): ?array
        {
            return null;
        }

        public function manifestScience(string $key): bool
        {
            return true;
        }
    }
}

if (! function_exists('auditRunnerWithChannel')) {
    function auditRunnerWithChannel(DistributionChannel $channel): FiscalSyncRunner
    {
        $factory = new class($channel) extends FiscalChannelFactory
        {
            public function __construct(private readonly DistributionChannel $channel) {}

            public function for(FiscalSyncSubscription $subscription): DistributionChannel
            {
                return $this->channel;
            }
        };

        return new FiscalSyncRunner($factory);
    }
}

if (! function_exists('auditKey')) {
    function auditKey(string $digit): string
    {
        return str_repeat($digit, 44);
    }
}

if (! function_exists('auditResumo')) {
    /**
     * @return array<string, mixed>
     */
    function auditResumo(string $nsu, string $key): array
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

if (! function_exists('auditSyncClient')) {
    function auditSyncClient(): Client
    {
        $client = Client::factory()->create();

        ClientCredential::factory()->create([
            'client_id' => $client->id,
            'pfx_data' => 'encrypted-pfx-fixture',
            'pfx_password' => 'encrypted-password-fixture',
            'expires_at' => now()->addYear(),
        ]);

        return $client;
    }
}

if (! function_exists('auditSyncSubscription')) {
    function auditSyncSubscription(Client $client): FiscalSyncSubscription
    {
        return FiscalSyncSubscription::factory()->create([
            'client_id' => $client->id,
            'family' => 'nfe',
            'environment' => 'production',
            'next_run_at' => now()->subMinutes(5),
            'blocked_until' => null,
        ]);
    }
}

if (! function_exists('auditOpensslConfig')) {
    function auditOpensslConfig(): ?string
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

if (! function_exists('auditMakePfx')) {
    function auditMakePfx(string $password): string
    {
        $options = ['digest_alg' => 'sha256'];

        if (is_string($config = auditOpensslConfig())) {
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

if (! function_exists('auditAccountUser')) {
    /**
     * @return array{Account, User}
     */
    function auditAccountUser(): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);

        return [$account, $user];
    }
}

// ---------------------------------------------------------------------------
// 1. OK cycle with N new documents -> exactly 1 fiscal.sync.cycle audit with
//    client/family/N, NULL (system) actor, Account as origin+target.
// ---------------------------------------------------------------------------

it('audits one ok cycle per subscription run with client, family and new-document count', function () {
    $client = auditSyncClient();
    $subscription = auditSyncSubscription($client);
    $keyA = auditKey('1');
    $keyB = auditKey('2');

    $fake = new AuditFakeChannel;
    $fake->queuedBatches = [new ChannelBatch(
        items: [
            auditResumo('000000000000001', $keyA),
            auditResumo('000000000000002', $keyB),
        ],
        lastNsu: '000000000000002',
    )];

    $result = auditRunnerWithChannel($fake)->run($subscription);

    expect($result->status)->toBe('synced');

    $audits = AuditLog::where('action', 'fiscal.sync.cycle')->get();

    expect($audits)->toHaveCount(1);

    $audit = $audits->firstOrFail();

    expect($audit->actor_user_id)->toBeNull()
        ->and($audit->origin_account_id)->toBe($client->account_id)
        ->and($audit->target_account_id)->toBe($client->account_id)
        ->and($audit->metadata['client_id'] ?? null)->toBe($client->id)
        ->and($audit->metadata['family'] ?? null)->toBe('nfe')
        ->and($audit->metadata['new_documents'] ?? null)->toBe(2)
        ->and($audit->metadata['result'] ?? null)->toBe('ok');
});

// ---------------------------------------------------------------------------
// 2a. Blocked cycle (SEFAZ pause) -> blocked audit with motive, no exception.
// ---------------------------------------------------------------------------

it('audits a blocked cycle with motive instead of failing silently', function () {
    Carbon::setTestNow('2026-09-12 10:23:00');

    try {
        $client = auditSyncClient();
        $subscription = auditSyncSubscription($client);

        $fake = new AuditFakeChannel;
        $fake->queuedBatches = [new ChannelBatch(items: [], lastNsu: '0', pause: 'sefaz_overuse')];

        $result = auditRunnerWithChannel($fake)->run($subscription);

        expect($result->status)->toBe('paused');

        $audit = AuditLog::where('action', 'fiscal.sync.cycle')->firstOrFail();

        expect($audit->actor_user_id)->toBeNull()
            ->and($audit->metadata['client_id'] ?? null)->toBe($client->id)
            ->and($audit->metadata['result'] ?? null)->toBe('blocked')
            ->and($audit->metadata['blocked_until'] ?? null)->not->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

// ---------------------------------------------------------------------------
// 2b. Failed cycle (channel exception) -> failed audit with short motive, no
//     leaked exception text, and no exception escaping run().
// ---------------------------------------------------------------------------

it('audits a failed cycle with a short motive and leaks neither the exception nor secrets', function () {
    $client = auditSyncClient();
    $subscription = auditSyncSubscription($client);
    $marker = 'MARKER-SEFAZ-BOOM-'.Str::random(8);

    $result = auditRunnerWithChannel(new AuditThrowingChannel($marker))->run($subscription);

    expect($result->status)->toBe('failed');

    $audit = AuditLog::where('action', 'fiscal.sync.cycle')->firstOrFail();

    expect($audit->actor_user_id)->toBeNull()
        ->and($audit->metadata['client_id'] ?? null)->toBe($client->id)
        ->and($audit->metadata['result'] ?? null)->toBe('failed')
        ->and((string) json_encode($audit->metadata))->not->toContain($marker);
});

// ---------------------------------------------------------------------------
// 3. Full per-Client trail: certificate store/replace/destroy + portal
//    password + auto-science + cycle, with NO secret in ANY fiscal metadata.
// ---------------------------------------------------------------------------

it('keeps a complete per-client trail with no secret in any fiscal metadata', function () {
    [$account, $admin] = auditAccountUser();
    $client = Client::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $firstPassword = 'pfx-first-'.Str::random(10);
    $this->post(route('certificates.store', $client), [
        'pfx' => UploadedFile::fake()->createWithContent('certificado.pfx', auditMakePfx($firstPassword)),
        'password' => $firstPassword,
    ])->assertRedirect();

    $secondPassword = 'pfx-second-'.Str::random(10);
    $this->post(route('certificates.store', $client), [
        'pfx' => UploadedFile::fake()->createWithContent('certificado.pfx', auditMakePfx($secondPassword)),
        'password' => $secondPassword,
    ])->assertRedirect();

    $portalSecret = 'portal-'.Str::random(12);
    $this->put(route('certificates.portal-password', $client), [
        'portal_password' => $portalSecret,
    ])->assertRedirect();

    $subscription = auditSyncSubscription($client);
    $key = auditKey('4');

    $fake = new AuditFakeChannel;
    $fake->queuedBatches = [new ChannelBatch(
        items: [auditResumo('000000000000001', $key)],
        lastNsu: '000000000000001',
    )];

    auditRunnerWithChannel($fake)->run($subscription);

    $this->delete(route('certificates.destroy', $client))->assertRedirect();

    $trail = AuditLog::query()
        ->where('origin_account_id', $account->id)
        ->where(function ($query) use ($client, $key) {
            $query->where('metadata->client_id', $client->id)
                ->orWhere('metadata->key', $key);
        })
        ->orderBy('id')
        ->get();

    $actions = $trail->pluck('action')->all();

    expect($actions)->toContain('certificates.store')
        ->and($actions)->toContain('certificates.portal-password')
        ->and($actions)->toContain('certificates.destroy')
        ->and($actions)->toContain('fiscal.science.auto')
        ->and($actions)->toContain('fiscal.sync.cycle');

    expect($trail->where('action', 'certificates.store'))->toHaveCount(2)
        ->and($trail->where('action', 'fiscal.science.auto'))->toHaveCount(1)
        ->and($trail->where('action', 'fiscal.sync.cycle'))->toHaveCount(1);

    foreach ($trail as $log) {
        $metadataJson = (string) json_encode($log->metadata);

        expect($metadataJson)->not->toContain($firstPassword)
            ->and($metadataJson)->not->toContain($secondPassword)
            ->and($metadataJson)->not->toContain($portalSecret);

        expect(array_keys($log->metadata ?? []))->not->toContain('pfx_data', 'pfx_password', 'password', 'portal_password');
    }

    foreach ($trail->where('action', 'fiscal.science.auto')->concat($trail->where('action', 'fiscal.sync.cycle')) as $systemLog) {
        expect($systemLog->actor_user_id)->toBeNull();
    }
});

// ---------------------------------------------------------------------------
// 4. Dashboard notifications render readable PT-BR bodies (never the raw
//    action) for every new fiscal action.
// ---------------------------------------------------------------------------

it('shows readable notification bodies for every fiscal action on the dashboard', function () {
    [$account, $admin] = auditAccountUser();
    $client = Client::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $password = 'pfx-'.Str::random(10);
    $this->post(route('certificates.store', $client), [
        'pfx' => UploadedFile::fake()->createWithContent('certificado.pfx', auditMakePfx($password)),
        'password' => $password,
    ])->assertRedirect();

    $this->put(route('certificates.portal-password', $client), [
        'portal_password' => 'portal-'.Str::random(12),
    ])->assertRedirect();

    $subscription = auditSyncSubscription($client);
    $keyA = auditKey('5');
    $keyB = auditKey('6');

    $fake = new AuditFakeChannel;
    $fake->queuedBatches = [new ChannelBatch(
        items: [
            auditResumo('000000000000001', $keyA),
            auditResumo('000000000000002', $keyB),
        ],
        lastNsu: '000000000000002',
    )];

    auditRunnerWithChannel($fake)->run($subscription);

    $this->delete(route('certificates.destroy', $client))->assertRedirect();

    $this->get(route('dashboard'))->assertOk()->assertInertia(fn ($page) => $page
        ->has('notifications', 6)
        ->where('notifications.0.body', 'Removeu o certificado digital do Client.')
        ->where('notifications.1.body', 'Sincronização fiscal concluída: 2 documentos novos.')
        ->where('notifications.2.body', 'Registrou ciência automática de um documento fiscal.')
        ->where('notifications.3.body', 'Registrou ciência automática de um documento fiscal.')
        ->where('notifications.4.body', 'Atualizou a senha do portal do Client.')
        ->where('notifications.5.body', 'Instalou o certificado digital do Client.'));
});
