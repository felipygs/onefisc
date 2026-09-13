<?php

namespace App\Services\Fiscal;

use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FiscalSyncSubscription;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use RuntimeException;

/**
 * Builds the distribution channel for a sync subscription.
 *
 * Decrypts the Client PFX secrets and maps the subscription family to its
 * vendor wrapper. NFS-e resolves to the ADN primary channel (task 3.4); the
 * portal fallback is built on demand via portalFor() so the portal password
 * is only decrypted when the ADN primary actually failed.
 */
class FiscalChannelFactory
{
    public function for(FiscalSyncSubscription $subscription): DistributionChannel
    {
        $family = strtolower($subscription->family);

        if (! in_array($family, ['nfe', 'cte', 'nfse'], true)) {
            throw new InvalidArgumentException("Canal fiscal sem suporte: {$subscription->family}.");
        }

        $client = Client::withoutGlobalScopes()->findOrFail($subscription->client_id);
        $credential = ClientCredential::withoutGlobalScopes()->where('client_id', $client->id)->first();

        if ($credential === null || blank($credential->getAttribute('pfx_data'))) {
            throw new RuntimeException('Credencial fiscal ausente para o Client.');
        }

        $password = $credential->getAttribute('pfx_password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Credencial fiscal sem senha do certificado.');
        }

        $pfx = Crypt::decryptString($this->encryptedPfx($credential));
        $pfxPassword = Crypt::decryptString($password);
        $tpAmb = $subscription->environment === 'production' ? 1 : 2;

        return match ($family) {
            'nfe' => new NFeDistChannel($pfx, $pfxPassword, $client->cnpj, $client->razao_social, $tpAmb),
            'cte' => new CTeDistChannel($pfx, $pfxPassword, $client->cnpj, $client->razao_social, $tpAmb),
            default => new NfseAdnChannel($this->adnBaseUrl($subscription), $pfx, $pfxPassword),
        };
    }

    /**
     * Builds the Emissor Nacional portal fallback for an nfse subscription.
     *
     * Throws when the Client never stored a portal password: without it the
     * portal cannot authenticate, so the runner records `unknown` and retries
     * next cycle instead of probing blindly.
     */
    public function portalFor(FiscalSyncSubscription $subscription): DistributionChannel
    {
        $subscription = FiscalSyncSubscription::withoutGlobalScopes()->findOrFail($subscription->id);
        $client = Client::withoutGlobalScopes()->findOrFail($subscription->client_id);
        $credential = ClientCredential::withoutGlobalScopes()->where('client_id', $client->id)->first();

        $encrypted = $credential?->getRawOriginal('portal_password');

        if (! is_string($encrypted) || $encrypted === '') {
            throw new RuntimeException('Senha do portal ausente para o Client.');
        }

        return new NfsePortalChannel(
            $this->portalBaseUrl($subscription),
            $client->cnpj,
            Crypt::decryptString($encrypted),
        );
    }

    private function adnBaseUrl(FiscalSyncSubscription $subscription): string
    {
        $key = $subscription->environment === 'production' ? 'producao' : 'homologacao';
        $baseUrl = (string) config("fiscal.adn.{$key}.base_url", '');

        if (trim($baseUrl) === '') {
            throw new RuntimeException('Endpoint ADN indisponivel para o ambiente da assinatura.');
        }

        return $baseUrl;
    }

    private function portalBaseUrl(FiscalSyncSubscription $subscription): string
    {
        $key = $subscription->environment === 'production' ? 'producao' : 'homologacao';
        $baseUrl = (string) config("fiscal.nfse_portal.{$key}.base_url", '');

        if (trim($baseUrl) === '') {
            throw new RuntimeException('Endpoint do portal NFS-e indisponivel para o ambiente da assinatura.');
        }

        return $baseUrl;
    }

    private function encryptedPfx(ClientCredential $credential): string
    {
        $raw = $credential->getAttribute('pfx_data');

        if (is_resource($raw)) {
            $raw = stream_get_contents($raw) ?: '';
        }

        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('Credencial fiscal sem dados do certificado.');
        }

        return $raw;
    }
}
