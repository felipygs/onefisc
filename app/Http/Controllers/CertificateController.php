<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Services\AuditService;
use App\Support\CurrentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class CertificateController extends Controller
{
    public function store(Client $client, AuditService $audit): RedirectResponse
    {
        $account = $this->authorizeCertificate($client);

        $validated = request()->validate([
            'pfx' => ['required', 'file', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $pfx = request()->file('pfx');

        if (! $pfx instanceof UploadedFile) {
            throw ValidationException::withMessages(['pfx' => 'Arquivo PFX inválido.']);
        }

        $password = $validated['password'];

        if (! is_string($password)) {
            throw ValidationException::withMessages(['password' => 'Senha inválida.']);
        }

        $raw = file_get_contents($pfx->getRealPath());

        if ($raw === false) {
            throw ValidationException::withMessages(['pfx' => 'Não foi possível ler o arquivo PFX.']);
        }

        $certificates = [];

        if (! openssl_pkcs12_read($raw, $certificates, $password) || ! isset($certificates['cert']) || ! is_string($certificates['cert'])) {
            throw ValidationException::withMessages(['pfx' => 'Não foi possível abrir o PFX com a senha informada.']);
        }

        $parsed = openssl_x509_parse($certificates['cert']);

        if (! is_array($parsed)) {
            throw ValidationException::withMessages(['pfx' => 'Não foi possível ler o certificado do arquivo PFX.']);
        }

        $validTo = $parsed['validTo_time_t'] ?? null;

        if (! is_int($validTo)) {
            throw ValidationException::withMessages(['pfx' => 'Não foi possível determinar a validade do certificado.']);
        }

        $expiresAt = Carbon::createFromTimestamp($validTo);

        if ($expiresAt->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['pfx' => 'O certificado está expirado. Suba um certificado válido.']);
        }

        $der = base64_decode((string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certificates['cert']), true);

        if ($der === false) {
            throw ValidationException::withMessages(['pfx' => 'Não foi possível ler o certificado do arquivo PFX.']);
        }

        $replaced = ClientCredential::query()->where('client_id', $client->id)->exists();
        $thumbprint = hash('sha256', $der);

        ClientCredential::updateOrCreate(
            ['client_id' => $client->id],
            [
                'pfx_data' => Crypt::encryptString($raw),
                'pfx_password' => Crypt::encryptString($password),
                'thumbprint' => $thumbprint,
                'expires_at' => $expiresAt,
            ]
        );

        $audit->record(
            action: 'certificates.store',
            targetAccountId: $account->id,
            metadata: [
                'client_id' => $client->id,
                'thumbprint' => $thumbprint,
                'expires_at' => $expiresAt->toIso8601String(),
                'replaced' => $replaced,
            ]
        );

        return redirect()->back()->with('status', 'Certificado instalado.');
    }

    public function updatePortalPassword(Client $client, AuditService $audit): RedirectResponse
    {
        $account = $this->authorizeCertificate($client);

        $validated = request()->validate([
            'portal_password' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        $portalPassword = $validated['portal_password'];

        ClientCredential::updateOrCreate(
            ['client_id' => $client->id],
            ['portal_password' => Crypt::encryptString($portalPassword)]
        );

        $audit->record(
            action: 'certificates.portal-password',
            targetAccountId: $account->id,
            metadata: ['client_id' => $client->id]
        );

        return redirect()->back()->with('status', 'Senha do portal salva.');
    }

    public function destroy(Client $client, AuditService $audit): RedirectResponse
    {
        $account = $this->authorizeCertificate($client);

        ClientCredential::query()->where('client_id', $client->id)->delete();

        $audit->record(
            action: 'certificates.destroy',
            targetAccountId: $account->id,
            metadata: ['client_id' => $client->id]
        );

        return redirect()->back()->with('status', 'Certificado removido.');
    }

    protected function authorizeCertificate(Client $client): Account
    {
        $account = CurrentAccount::resolve();
        $user = request()->user();

        abort_unless($account !== null && $user !== null
            && ($user->can('manage-certificates', $account)
                || (CurrentAccount::isSwitching() && $user->can('manage-platform'))), 403);

        // Global scope already isolates by account; fail closed if mismatch.
        abort_if((int) $client->account_id !== (int) $account->id, 404);

        return $account;
    }
}
