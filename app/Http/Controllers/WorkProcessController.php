<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkProcessRequest;
use App\Http\Requests\UpdateWorkProcessRequest;
use App\Models\User;
use App\Models\WorkProcess;
use App\Policies\WorkProcessPolicy;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkProcessController extends Controller
{
    public function index(): Response
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        Gate::authorize('viewAny', WorkProcess::class);

        $processes = $this->scopedQuery()
            ->with(['definitions' => fn ($query) => $query->orderBy('position')])
            ->orderBy('title')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Work/Processes/Index', [
            'processes' => $processes,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', WorkProcess::class);

        return Inertia::render('Work/Processes/Create');
    }

    public function store(StoreWorkProcessRequest $request): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);

        $validated = $request->validated();
        $definitions = $validated['definitions'] ?? null;
        unset($validated['definitions']);

        // Origin is always manual here; any incoming `source` is ignored and
        // never reaches validated data. Marketplace installs land in Task 2.5.
        DB::transaction(function () use ($account, $validated, $definitions): void {
            $process = WorkProcess::create([
                ...$validated,
                'account_id' => $account->id,
                'source' => 'manual',
            ]);

            if (is_array($definitions)) {
                $this->syncDefinitions($process, $definitions);
            }
        });

        return redirect()->route('work.processes.index')->with('status', 'Processo criado.');
    }

    public function show(int|string $process): Response
    {
        abort_unless(CurrentAccount::resolve() !== null, 404);
        $model = $this->findProcess($process);
        Gate::authorize('view', $model);

        return Inertia::render('Work/Processes/Show', [
            'process' => $model->load(['definitions' => fn ($query) => $query->orderBy('position')]),
        ]);
    }

    public function edit(int|string $process): Response
    {
        abort_unless(CurrentAccount::resolve() !== null, 404);
        $model = $this->findProcess($process);
        Gate::authorize('update', $model);

        return Inertia::render('Work/Processes/Edit', [
            'process' => $model->load(['definitions' => fn ($query) => $query->orderBy('position')]),
        ]);
    }

    public function update(UpdateWorkProcessRequest $request, int|string $process): RedirectResponse
    {
        abort_unless(CurrentAccount::resolve() !== null, 404);
        $model = $this->findProcess($process);
        $validated = $request->validated();
        $definitions = $validated['definitions'] ?? null;
        unset($validated['definitions']);

        DB::transaction(function () use ($model, $validated, $definitions): void {
            $model->update($validated);

            if (is_array($definitions)) {
                $this->syncDefinitions($model, $definitions);
            }
        });

        return redirect()->route('work.processes.index')->with('status', 'Processo atualizado.');
    }

    public function destroy(int|string $process): RedirectResponse
    {
        abort_unless(CurrentAccount::resolve() !== null, 404);
        $model = $this->findProcess($process);
        Gate::authorize('delete', $model);

        // Hard delete: DB cascades remove definitions, associations and
        // tasks. Soft-retire stays available through the `archived` status.
        $model->delete();

        return redirect()->route('work.processes.index')->with('status', 'Processo removido.');
    }

    /**
     * Resolve a process through the account-scoped query. Foreign or malformed
     * ids fail with 404 here, before any policy check can leak a 403.
     */
    protected function findProcess(int|string $id): WorkProcess
    {
        return WorkProcess::query()->findOrFail($id);
    }

    /**
     * Account-scoped listing: managers see every process, collaborators only
     * processes holding at least one client assigned to them via client_user.
     *
     * @return Builder<WorkProcess>
     */
    protected function scopedQuery(): Builder
    {
        $query = WorkProcess::query();
        $user = request()->user();

        if ($user instanceof User && ! in_array($user->role, WorkProcessPolicy::MANAGING_ROLES, true)) {
            $query->whereExists(function ($exists) use ($user): void {
                $exists->select(DB::raw('1'))
                    ->from('work_process_clients as wpc')
                    ->join('client_user as cu', 'cu.client_id', '=', 'wpc.client_id')
                    ->whereColumn('wpc.work_process_id', 'work_processes.id')
                    ->where('cu.user_id', $user->id);
            });
        }

        return $query;
    }

    /**
     * Replace the checklist by stable identity: update rows carrying an id,
     * insert rows without one, delete rows omitted from the payload.
     * Definition ids never change, so existing WorkTask links survive.
     *
     * @param  array<int, array<string, mixed>>  $definitions
     */
    protected function syncDefinitions(WorkProcess $process, array $definitions): void
    {
        $incomingIds = collect($definitions)
            ->pluck('id')
            ->filter()
            ->all();

        $process->definitions()->whereNotIn('id', $incomingIds)->delete();

        foreach (array_values($definitions) as $index => $item) {
            $payload = [
                ...$item,
                'position' => $item['position'] ?? $index,
            ];
            unset($payload['id']);

            if (! empty($item['id'])) {
                $process->definitions()->whereKey($item['id'])->update($payload);
            } else {
                $process->definitions()->create($payload);
            }
        }
    }
}
