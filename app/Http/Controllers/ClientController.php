<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Services\PlanLimitService;
use App\Support\CurrentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null && request()->user()?->can('operate-clients', $account), 403);

        $clients = Client::query()
            ->orderBy('razao_social')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('clients/Index', [
            'clients' => $clients,
        ]);
    }

    public function create(): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null && request()->user()?->can('operate-clients', $account), 403);

        return Inertia::render('clients/Create');
    }

    public function store(ClientRequest $request, PlanLimitService $limits): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null && $request->user()?->can('operate-clients', $account), 403);

        $limits->ensureClientCapacity($account);

        Client::create([
            ...$request->validated(),
            'account_id' => $account->id,
        ]);

        return redirect()->route('clients.index')->with('status', 'Client cadastrado.');
    }

    public function show(Request $request, Client $client, PlanLimitService $limits): Response
    {
        $this->authorizeClient($client);

        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 403);

        $filters = $this->documentFilters($request);

        $documents = FiscalDocument::query()
            ->where('client_id', $client->id)
            ->with('client:id,razao_social')
            ->when($filters['family'] !== null, fn ($query) => $query->where('family', $filters['family']))
            ->when($filters['status'] !== null, fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['origin'] !== null, fn ($query) => $query->where('origin', $filters['origin']))
            ->when($filters['q'] !== '', fn ($query) => $query->where(function ($query) use ($filters) {
                // Escape LIKE wildcards so %/_ match literally; the explicit
                // ESCAPE clause keeps it literal on every driver (SQLite has
                // no default LIKE escape character).
                $like = '%'.addcslashes($filters['q'], '%_\\').'%';
                $query->whereRaw('key LIKE ? ESCAPE \'\\\'', [$like])
                    ->orWhereRaw('number LIKE ? ESCAPE \'\\\'', [$like])
                    ->orWhereRaw('issuer_name LIKE ? ESCAPE \'\\\'', [$like]);
            }))
            ->orderBy($this->sortColumn($filters['sort']), $filters['dir'])
            ->orderBy('id', $filters['dir'])
            ->paginate(15)
            ->withQueryString()
            ->through(fn (FiscalDocument $document) => $this->row($document));

        return Inertia::render('clients/Show', [
            'client' => $client,
            'certificate' => $this->certificateState($client),
            'documents' => $documents,
            'filters' => $filters,
            'sync' => $this->syncState($client, $account, $limits),
            'syncFamilies' => $this->syncFamilies($client),
        ]);
    }

    public function edit(Client $client): Response
    {
        $this->authorizeClient($client);

        return Inertia::render('clients/Edit', [
            'client' => $client,
        ]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $this->authorizeClient($client);

        $client->update($request->validated());

        return redirect()->route('clients.index')->with('status', 'Client atualizado.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $this->authorizeClient($client);

        $client->delete();

        return redirect()->route('clients.index')->with('status', 'Client removido.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null && request()->user()?->can('operate-clients', $account), 403);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        // The account global scope keeps ids from other Accounts untouched.
        Client::query()->whereIn('id', $validated['ids'])->delete();

        return redirect()->route('clients.index')->with('status', 'Clients removidos.');
    }

    protected function authorizeClient(Client $client): void
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null && request()->user()?->can('operate-clients', $account), 403);

        // Global scope already isolates by account; fail closed if mismatch.
        abort_if((int) $client->account_id !== (int) $account->id, 404);
    }

    /**
     * Read-only certificate validity for the Client rail.
     *
     * Never includes secrets: only status + expires_at.
     *
     * @return array{status: string, expires_at?: string}
     */
    protected function certificateState(Client $client): array
    {
        $credential = $client->credential;

        if ($credential === null || blank($credential->pfx_data) || $credential->expires_at === null) {
            return ['status' => 'missing'];
        }

        $expiresAt = $credential->expires_at;

        if ($expiresAt->lessThanOrEqualTo(now())) {
            return ['status' => 'expired'];
        }

        if ($expiresAt->lessThanOrEqualTo(now()->addDays(30))) {
            return ['status' => 'expiring', 'expires_at' => $expiresAt->toIso8601String()];
        }

        return ['status' => 'valid', 'expires_at' => $expiresAt->toIso8601String()];
    }

    /**
     * Same filter/sort contract as DocumentDashboardController@all, so the
     * Fiscal sub-tab reuses the global table behavior scoped to one Client.
     *
     * @return array{q: string, family: string|null, status: string|null, origin: string|null, sort: 'documento'|'emissao'|'status', dir: 'asc'|'desc'}
     */
    protected function documentFilters(Request $request): array
    {
        $family = $request->input('family');
        $family = in_array($family, ['nfe', 'cte', 'nfse'], true) ? $family : null;

        $status = $request->input('status');
        $status = in_array($status, ['authorized', 'cancelled', 'denied', 'pending'], true) ? $status : null;

        $origin = $request->input('origin');
        $origin = in_array($origin, ['distribuicao', 'portal'], true) ? $origin : null;

        $q = is_string($request->input('q')) ? trim($request->input('q')) : '';

        $sort = $request->input('sort');
        $sort = in_array($sort, ['documento', 'emissao', 'status'], true) ? $sort : 'emissao';

        $dir = strtolower((string) $request->input('dir')) === 'asc' ? 'asc' : 'desc';

        return [
            'q' => $q,
            'family' => $family,
            'status' => $status,
            'origin' => $origin,
            'sort' => $sort,
            'dir' => $dir,
        ];
    }

    /**
     * Read-only sync snapshot aggregated over the Client subscriptions and
     * cursors. Honest nulls when nothing ever synced; never throws.
     *
     * @return array{last_run_at: string|null, new_documents: int, next_run_at: string|null, blocked_until: string|null, volume_exhausted: bool}
     */
    protected function syncState(Client $client, Account $account, PlanLimitService $limits): array
    {
        $client->loadMissing(['syncSubscriptions', 'syncCursors']);

        $subscriptions = $client->syncSubscriptions;
        $cursors = $client->syncCursors;

        $volumeExhausted = $this->isVolumeExhausted($account, $limits);

        if ($subscriptions->isEmpty() && $cursors->isEmpty()) {
            return [
                'last_run_at' => null,
                'new_documents' => 0,
                'next_run_at' => null,
                'blocked_until' => null,
                'volume_exhausted' => $volumeExhausted,
            ];
        }

        // Last touch of the hourly machinery: dispatches advance next_run_at,
        // SEFAZ pauses touch blocked_until, progress touches the cursor.
        $lastRunAt = null;
        foreach ([...$subscriptions->all(), ...$cursors->all()] as $record) {
            $touched = $record->updated_at;

            if ($touched !== null && ($lastRunAt === null || $touched->greaterThan($lastRunAt))) {
                $lastRunAt = $touched;
            }
        }

        $nextRunAt = null;
        foreach ($subscriptions as $subscription) {
            $candidate = $subscription->next_run_at;

            if ($candidate !== null && ($nextRunAt === null || $candidate->lessThan($nextRunAt))) {
                $nextRunAt = $candidate;
            }
        }

        // Only a live SEFAZ pause counts as blocked; expired windows read as free.
        $blockedUntil = null;
        foreach ($subscriptions as $subscription) {
            $candidate = $subscription->blocked_until;

            if ($candidate !== null && $candidate->isFuture() && ($blockedUntil === null || $candidate->greaterThan($blockedUntil))) {
                $blockedUntil = $candidate;
            }
        }

        // Rolling last-cycle window (hourly cadence): documents that landed
        // within the last hour read as the fresh arrivals.
        $newDocuments = FiscalDocument::query()
            ->where('client_id', $client->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        return [
            'last_run_at' => $lastRunAt?->toIso8601String(),
            'new_documents' => $newDocuments,
            'next_run_at' => $nextRunAt?->toIso8601String(),
            'blocked_until' => $blockedUntil?->toIso8601String(),
            'volume_exhausted' => $volumeExhausted,
        ];
    }

    /**
     * Subscribed families drive the per-family capacity display.
     *
     * @return array<int, string>
     */
    protected function syncFamilies(Client $client): array
    {
        $client->loadMissing('syncSubscriptions');

        return $client->syncSubscriptions
            ->pluck('family')
            ->unique()
            ->values()
            ->all();
    }

    protected function isVolumeExhausted(Account $account, PlanLimitService $limits): bool
    {
        try {
            $limits->ensureVolume($account);
        } catch (ValidationException) {
            return true;
        }

        return false;
    }

    /**
     * Public row shape, same as DocumentDashboardController@all: flags only,
     * never secrets or storage paths.
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
            'origin' => $document->origin,
            'has_xml' => (bool) $document->has_xml,
            'has_danfe' => (bool) $document->has_danfe,
            'client' => $document->client !== null ? [
                'id' => $document->client->id,
                'name' => $document->client->razao_social,
            ] : null,
        ];
    }

    protected function sortColumn(string $sort): string
    {
        return match ($sort) {
            'documento' => 'number',
            'status' => 'status',
            default => 'emission_at',
        };
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
