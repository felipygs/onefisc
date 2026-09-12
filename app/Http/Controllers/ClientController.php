<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Services\PlanLimitService;
use App\Support\CurrentAccount;
use Illuminate\Http\RedirectResponse;
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

    public function show(Client $client): Response
    {
        $this->authorizeClient($client);

        return Inertia::render('clients/Show', [
            'client' => $client,
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

    protected function authorizeClient(Client $client): void
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null && request()->user()?->can('operate-clients', $account), 403);

        // Global scope already isolates by account; fail closed if mismatch.
        abort_if((int) $client->account_id !== (int) $account->id, 404);
    }
}
