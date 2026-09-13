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
 * vendor wrapper. Families without a channel yet (nfse, task 3.4) throw.
 */
class FiscalChannelFactory
{
    public function for(FiscalSyncSubscription $subscription): DistributionChannel
    {
        $family = strtolower($subscription->family);

        if (! in_array($family, ['nfe', 'cte'], true)) {
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
            default => new CTeDistChannel($pfx, $pfxPassword, $client->cnpj, $client->razao_social, $tpAmb),
        };
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
