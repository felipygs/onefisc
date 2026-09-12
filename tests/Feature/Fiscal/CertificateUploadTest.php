<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

if (! function_exists('testPfxOpensslConfig')) {
    /**
     * Explicit openssl.cnf path: the test runner PHP may default to a
     * non-existent config file, so never rely on the environment.
     */
    function testPfxOpensslConfig(): ?string
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

if (! function_exists('makeTestPfx')) {
    /**
     * Generate a PKCS#12 blob in-memory (no binary fixtures in the repo).
     *
     * @throws RuntimeException when OpenSSL cannot generate the bundle.
     */
    function makeTestPfx(string $password, int $days = 365): string
    {
        $options = ['digest_alg' => 'sha256'];

        if (is_string($config = testPfxOpensslConfig())) {
            $options['config'] = $config;
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ...$options,
        ]);

        if ($key === false) {
            throw new RuntimeException('openssl_pkey_new failed: '.(openssl_error_string() ?: 'unknown'));
        }

        $csr = openssl_csr_new(['CN' => 'Cliente Teste'], $key, $options);

        if ($csr === false) {
            throw new RuntimeException('openssl_csr_new failed: '.(openssl_error_string() ?: 'unknown'));
        }

        $cert = openssl_csr_sign($csr, null, $key, $days, $options);

        if ($cert === false) {
            throw new RuntimeException('openssl_csr_sign failed: '.(openssl_error_string() ?: 'unknown'));
        }

        $pfx = '';

        if (! openssl_pkcs12_export($cert, $pfx, $key, $password)) {
            throw new RuntimeException('openssl_pkcs12_export failed: '.(openssl_error_string() ?: 'unknown'));
        }

        return $pfx;
    }
}

if (! function_exists('pfxUploadFile')) {
    function pfxUploadFile(string $binary): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('certificado.pfx', $binary);
    }
}

if (! function_exists('certificateAccountUser')) {
    /**
     * @return array{Account, User}
     */
    function certificateAccountUser(string $role = 'admin'): array
    {
        $account = Account::factory()->create(['profile' => 'B']);
        $user = User::factory()->create(['account_id' => $account->id, 'role' => $role]);

        return [$account, $user];
    }
}

it('stores a valid A1 certificate with encrypted secrets and audit', function () {
    [$account, $admin] = certificateAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $password = 'senha-'.Str::random(8);
    $pfx = makeTestPfx($password);

    $this->post(route('certificates.store', $client), [
        'pfx' => pfxUploadFile($pfx),
        'password' => $password,
    ])->assertRedirect()->assertSessionHas('status');

    $credential = ClientCredential::withoutGlobalScopes()->where('client_id', $client->id)->firstOrFail();

    expect($credential->expires_at)->not->toBeNull()
        ->and($credential->expires_at->isFuture())->toBeTrue()
        ->and($credential->thumbprint)->not->toBeNull();

    expect($credential->getRawOriginal('pfx_data'))->not->toBe($pfx)
        ->and(Crypt::decryptString($credential->getRawOriginal('pfx_password')))->toBe($password);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'certificates.store',
        'actor_user_id' => $admin->id,
        'origin_account_id' => $account->id,
        'target_account_id' => $account->id,
    ]);

    $log = AuditLog::query()->where('action', 'certificates.store')->firstOrFail();
    expect($log->metadata)->toBeArray()
        ->and(array_keys($log->metadata ?? []))->not->toContain('pfx_data', 'pfx_password', 'password')
        ->and((string) json_encode($log->metadata))->not->toContain($password);
});

it('rejects a PFX with the wrong password without saving anything', function () {
    [$account, $admin] = certificateAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $pfx = makeTestPfx('correct-password');

    $this->post(route('certificates.store', $client), [
        'pfx' => pfxUploadFile($pfx),
        'password' => 'wrong-password',
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('pfx');

    expect(ClientCredential::withoutGlobalScopes()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'certificates.store')->count())->toBe(0);
});

it('rejects an expired PFX without saving anything', function () {
    [$account, $admin] = certificateAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $password = 'senha-'.Str::random(8);
    $pfx = makeTestPfx($password, 0);

    $this->post(route('certificates.store', $client), [
        'pfx' => pfxUploadFile($pfx),
        'password' => $password,
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('pfx');

    expect(ClientCredential::withoutGlobalScopes()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'certificates.store')->count())->toBe(0);
});

it('denies operador and user on store and destroy', function (string $role) {
    [$account, $user] = certificateAccountUser($role);
    $client = Client::factory()->create(['account_id' => $account->id]);
    $this->actingAs($user);

    $password = 'senha-'.Str::random(8);

    $this->post(route('certificates.store', $client), [
        'pfx' => pfxUploadFile(makeTestPfx($password)),
        'password' => $password,
    ])->assertForbidden();

    $this->delete(route('certificates.destroy', $client))->assertForbidden();

    expect(ClientCredential::withoutGlobalScopes()->count())->toBe(0);
})->with(['operador', 'user']);

it('replaces the previous certificate on re-upload keeping a single row', function () {
    [$account, $admin] = certificateAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $first = 'first-'.Str::random(8);
    $this->post(route('certificates.store', $client), [
        'pfx' => pfxUploadFile(makeTestPfx($first)),
        'password' => $first,
    ])->assertRedirect();

    $previous = ClientCredential::withoutGlobalScopes()->where('client_id', $client->id)->firstOrFail();

    $second = 'second-'.Str::random(8);
    $this->post(route('certificates.store', $client), [
        'pfx' => pfxUploadFile(makeTestPfx($second)),
        'password' => $second,
    ])->assertRedirect();

    expect(ClientCredential::withoutGlobalScopes()->where('client_id', $client->id)->count())->toBe(1);

    $credential = ClientCredential::withoutGlobalScopes()->where('client_id', $client->id)->firstOrFail();
    expect($credential->thumbprint)->not->toBe($previous->thumbprint)
        ->and(Crypt::decryptString($credential->getRawOriginal('pfx_password')))->toBe($second)
        ->and(AuditLog::query()->where('action', 'certificates.store')->count())->toBe(2);
});
