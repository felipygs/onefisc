<?php

namespace App\Http\Controllers;

use App\Models\WorkMarketplaceProcess;
use App\Models\WorkProcess;
use App\Services\AuditService;
use App\Support\CurrentAccount;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkMarketplaceController extends Controller
{
    /**
     * Browse (Task 4.2): published listings with per-account install flags.
     * Shape matches the 4.1 Catalog Prontos tab exactly:
     * `{listings: [{id, title, description, category, task_count, added,
     * added_process_id}]}`. Any account member may browse (the `viewAny`
     * gate is membership only); unpublished rows never leave the server.
     */
    public function index(): JsonResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        Gate::authorize('viewAny', WorkProcess::class);

        $listings = WorkMarketplaceProcess::query()
            ->where('published', true)
            ->withCount('definitions as task_count')
            ->orderBy('title')
            ->get();

        $installed = WorkProcess::query()
            ->where('work_processes.account_id', $account->id)
            ->whereNotNull('work_processes.marketplace_process_id')
            ->pluck('work_processes.id', 'work_processes.marketplace_process_id');

        return response()->json([
            'listings' => $listings->map(fn (WorkMarketplaceProcess $listing): array => [
                'id' => $listing->id,
                'title' => $listing->title,
                'description' => $listing->description,
                'category' => $listing->category,
                'task_count' => (int) ($listing->getAttribute('task_count') ?? 0),
                'added' => $installed->has($listing->id),
                'added_process_id' => $installed->has($listing->id) ? (int) $installed->get($listing->id) : null,
            ])->all(),
        ]);
    }

    /**
     * Idempotent install (Task 4.2): copies title/description/checklist
     * (position order, rich fields) into a `marketplace`-sourced WorkProcess
     * of the CALLER's account. `admin`/`operador` only (`user` ⇒ 403);
     * unpublished/missing ⇒ 404. A second install returns the existing row;
     * a unique violation (23000) under true concurrency re-fetches the
     * winner instead of erroring. Responds 303 to the catalog editor
     * association tab (resolves 4.1's deferred I2: Inertia follows the
     * redirect instead of showing a stale Adicionar).
     */
    public function install(int|string $listing, AuditService $audit): RedirectResponse
    {
        $account = CurrentAccount::resolve();
        abort_unless($account !== null, 404);
        Gate::authorize('create', WorkProcess::class);

        $marketplace = WorkMarketplaceProcess::query()->findOrFail($listing);
        abort_unless($marketplace->published, 404);

        // String-keyed: `marketplace_process_id` is a varchar guard column,
        // so every read and write casts the listing id the same way.
        $marketplaceKey = (string) $marketplace->id;

        $alreadyInstalled = WorkProcess::query()
            ->where('work_processes.account_id', $account->id)
            ->where('work_processes.marketplace_process_id', $marketplaceKey)
            ->exists();

        try {
            $process = DB::transaction(function () use ($account, $marketplace, $marketplaceKey): WorkProcess {
                $existing = WorkProcess::query()
                    ->where('work_processes.account_id', $account->id)
                    ->where('work_processes.marketplace_process_id', $marketplaceKey)
                    ->first();

                if ($existing instanceof WorkProcess) {
                    return $existing;
                }

                $created = WorkProcess::create([
                    'account_id' => $account->id,
                    'title' => $marketplace->title,
                    'description' => $marketplace->description,
                    'source' => 'marketplace',
                    'marketplace_process_id' => $marketplaceKey,
                ]);

                foreach ($marketplace->definitions()->orderBy('position')->get() as $definition) {
                    $created->definitions()->create([
                        'title' => $definition->title,
                        'position' => $definition->position,
                        'description' => $definition->description,
                        'due_day' => $definition->due_day,
                        'competence_offset' => $definition->competence_offset,
                        'priority' => $definition->priority,
                        'requires_document' => $definition->requires_document,
                    ]);
                }

                return $created;
            });
        } catch (QueryException $exception) {
            if (! self::isUniqueViolation($exception)) {
                throw $exception;
            }

            $alreadyInstalled = true;

            $process = WorkProcess::query()
                ->where('work_processes.account_id', $account->id)
                ->where('work_processes.marketplace_process_id', $marketplaceKey)
                ->firstOrFail();
        }

        $audit->record(
            action: 'work.marketplace.installed',
            targetAccountId: $account->id,
            metadata: [
                'listing_id' => $marketplace->id,
                'listing_slug' => $marketplace->slug,
                'process_id' => $process->id,
                'already_installed' => $alreadyInstalled,
            ]
        );

        return redirect()
            ->route('work.catalog.show', ['process' => $process->id, 'tab' => 'association'], 303)
            ->with('status', 'Modelo adicionado ao catálogo.');
    }

    /**
     * True-concurrency guard: only unique violations (SQLSTATE 23000,
     * Postgres 23505, SQLite 19 / message fallback) converge to the winner;
     * anything else rethrows.
     */
    protected static function isUniqueViolation(QueryException $exception): bool
    {
        foreach ([$exception->getCode(), $exception->getPrevious()?->getCode()] as $code) {
            if (in_array((string) $code, ['23000', '23505', '19'], true)) {
                return true;
            }
        }

        return str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }
}
