<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalCoverageEvidence;
use App\Models\FiscalDocument;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentDashboardController extends Controller
{
    /**
     * Valid portfolio windows. Anything else falls back to the default.
     *
     * @var array<string, int>
     */
    protected const PERIODS = [
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    public function index(Request $request): Response
    {
        $account = $this->authorizeDocuments();
        $period = $this->resolvePeriod($request);
        $cutoff = $this->cutoff($period);

        return Inertia::render('documents/Index', [
            'overview' => $this->overview($account, $cutoff),
            'period' => $period,
            'chart' => $this->chart($cutoff),
        ]);
    }

    public function all(Request $request): Response
    {
        $this->authorizeDocuments();

        $family = $request->input('family');
        $family = in_array($family, ['nfe', 'cte', 'nfse'], true) ? $family : null;

        $documents = FiscalDocument::query()
            ->with('client:id,razao_social')
            ->when($family !== null, fn ($query) => $query->where('family', $family))
            ->orderByDesc('emission_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (FiscalDocument $document) => $this->row($document));

        $clients = Client::query()
            ->orderBy('razao_social')
            ->get(['id', 'razao_social'])
            ->map(fn (Client $client): array => ['id' => $client->id, 'name' => $client->razao_social])
            ->values()
            ->all();

        return Inertia::render('documents/All', [
            'documents' => $documents,
            'family' => $family,
            'clients' => $clients,
        ]);
    }

    public function clients(): Response
    {
        $account = $this->authorizeDocuments();

        return Inertia::render('documents/Clients', [
            'attention' => $this->attention($account),
        ]);
    }

    protected function authorizeDocuments(): Account
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null && request()->user()?->can('operate-clients', $account), 403);

        return $account;
    }

    protected function resolvePeriod(Request $request): string
    {
        $period = $request->input('period');

        return array_key_exists($period, self::PERIODS) ? $period : '30d';
    }

    protected function cutoff(string $period): CarbonImmutable
    {
        return CarbonImmutable::today()->subDays(self::PERIODS[$period] - 1);
    }

    /**
     * @return array{totals: array<string, int>, families: array<int, array{family: string, count: int}>, rankings: array{clients: array<int, array{id: int, name: string, value: int}>}, recent: array<int, array<string, mixed>>, attention: array<int, array<string, mixed>>}
     */
    protected function overview(Account $account, CarbonImmutable $cutoff): array
    {
        $windowed = fn ($query) => $query->where(fn ($inner) => $inner
            ->where('emission_at', '>=', $cutoff->startOfDay())
            ->orWhereNull('emission_at'));

        $families = [];
        foreach (['nfe', 'cte', 'nfse'] as $family) {
            $families[] = [
                'family' => $family,
                'count' => FiscalDocument::query()->where('family', $family)->where($windowed)->count(),
            ];
        }

        $documents = array_sum(array_column($families, 'count'));

        // Fiscal documents carry no monetary value column, so the wallet
        // ranking is by document volume per client (value = count).
        $volumes = FiscalDocument::query()
            ->where($windowed)
            ->selectRaw('client_id, COUNT(*) as aggregate')
            ->groupBy('client_id')
            ->pluck('aggregate', 'client_id');

        $names = Client::query()
            ->whereIn('id', $volumes->keys()->all())
            ->pluck('razao_social', 'id');

        $rankings = $volumes
            ->map(fn (mixed $count, mixed $clientId): array => [
                'id' => (int) $clientId,
                'name' => (string) ($names[$clientId] ?? '—'),
                'value' => (int) $count,
            ])
            ->sortBy([['value', 'desc'], ['name', 'asc']])
            ->take(8)
            ->values()
            ->all();

        $recent = FiscalDocument::query()
            ->with('client:id,razao_social')
            ->where($windowed)
            ->orderByDesc('emission_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (FiscalDocument $document) => $this->row($document))
            ->all();

        $attention = $this->attention($account);

        return [
            'totals' => [
                'documents' => $documents,
                'pending_xml' => FiscalDocument::query()->where('has_xml', false)->count(),
                'sync_attention' => count($attention),
                'certificates_expiring' => $this->certificatesExpiring(),
                'clients' => Client::query()->count(),
                'clients_with_documents' => FiscalDocument::query()->distinct()->count('client_id'),
            ],
            'families' => $families,
            'rankings' => ['clients' => $rankings],
            'recent' => $recent,
            'attention' => $attention,
        ];
    }

    /**
     * Daily document counts over the window (zero-filled) for the unovis chart.
     *
     * @return array<int, array{date: string, amount: int}>
     */
    protected function chart(CarbonImmutable $cutoff): array
    {
        $counts = FiscalDocument::query()
            ->where('emission_at', '>=', $cutoff->startOfDay())
            ->selectRaw('date(emission_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $points = [];
        $cursor = $cutoff;
        $today = CarbonImmutable::today();

        while (! $cursor->greaterThan($today)) {
            $day = $cursor->toDateString();
            $points[] = ['date' => $day, 'amount' => (int) ($counts[$day] ?? 0)];
            $cursor = $cursor->addDay();
        }

        return $points;
    }

    /**
     * Wallet attention rows. One row per (client, reason); global scopes
     * keep every source query inside the current account.
     *
     * @return array<int, array{client_id: int, name: string, tax_id: string, reason: string}>
     */
    protected function attention(Account $account): array
    {
        $rows = [];

        $clients = Client::query()
            ->with(['credential', 'syncSubscriptions', 'syncCursors'])
            ->orderBy('razao_social')
            ->get();

        $limitedClientIds = FiscalCoverageEvidence::query()
            ->where('status', 'limited')
            ->pluck('client_id')
            ->unique()
            ->all();

        $pendingXmlClientIds = FiscalDocument::query()
            ->where('has_xml', false)
            ->pluck('client_id')
            ->unique()
            ->all();

        foreach ($clients as $client) {
            $reasons = [];

            if ($this->isSyncBlocked($client)) {
                $reasons[] = 'sync_failed';
            }

            if (in_array($client->id, $limitedClientIds, true)) {
                $reasons[] = 'coverage_limited';
            }

            $certificate = $this->certificateReason($client->credential?->pfx_data, $client->credential?->expires_at);

            if ($certificate === 'certificate_missing' || $certificate === 'certificate_expired') {
                $reasons[] = 'certificate_missing';
            } elseif ($certificate === 'certificate_expiring') {
                $reasons[] = 'certificate_expiring';
            }

            if (in_array($client->id, $pendingXmlClientIds, true)) {
                $reasons[] = 'pending_xml';
            }

            foreach ($reasons as $reason) {
                $rows[] = [
                    'client_id' => $client->id,
                    'name' => $client->razao_social,
                    'tax_id' => $client->cnpj,
                    'reason' => $reason,
                ];
            }
        }

        return $rows;
    }

    protected function isSyncBlocked(Client $client): bool
    {
        foreach ($client->syncSubscriptions as $subscription) {
            if ($subscription->blocked_until !== null && $subscription->blocked_until->isFuture()) {
                return true;
            }
        }

        return false;
    }

    protected function certificateReason(mixed $pfxData, mixed $expiresAt): string
    {
        if (blank($pfxData) || $expiresAt === null) {
            return 'certificate_missing';
        }

        if ($expiresAt->lessThanOrEqualTo(now())) {
            return 'certificate_expired';
        }

        if ($expiresAt->lessThanOrEqualTo(now()->addDays(30))) {
            return 'certificate_expiring';
        }

        return 'certificate_valid';
    }

    protected function certificatesExpiring(): int
    {
        return Client::query()
            ->with('credential')
            ->get()
            ->filter(fn (Client $client) => $client->credential !== null
                && filled($client->credential->pfx_data)
                && $client->credential->expires_at !== null
                && $client->credential->expires_at->greaterThan(now())
                && $client->credential->expires_at->lessThanOrEqualTo(now()->addDays(30)))
            ->count();
    }

    /**
     * Public row shape: flags only, never secrets or storage paths.
     *
     * @return array<string, mixed>
     */
    protected function row(FiscalDocument $document): array
    {
        return [
            'id' => $document->id,
            'family' => $document->family,
            'doc_type' => $document->doc_type,
            'number' => $document->number,
            'series' => $document->series,
            'key' => $document->key,
            'derived_from_key' => (bool) $document->derived_from_key,
            'emission_at' => $document->emission_at?->toIso8601String(),
            'issuer_name' => $document->issuer_name,
            'issuer_tax_id' => $document->issuer_tax_id,
            'recipient_name' => $document->recipient_name,
            'recipient_tax_id' => $document->recipient_tax_id,
            'status' => $document->status,
            'status_label' => $this->statusLabel($document->status),
            'has_xml' => (bool) $document->has_xml,
            'has_danfe' => (bool) $document->has_danfe,
            'client' => $document->client !== null ? [
                'id' => $document->client->id,
                'name' => $document->client->razao_social,
            ] : null,
        ];
    }

    protected function statusLabel(?string $status): string
    {
        return match ($status) {
            'authorized' => 'Autorizada',
            'cancelled' => 'Cancelada',
            'denied' => 'Denegada',
            'pending' => 'Pendente',
            default => '—',
        };
    }
}
