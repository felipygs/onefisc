<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\CurrentAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $effective = CurrentAccount::resolve();
        abort_unless($effective !== null, 403);

        $canSeeAll = $user->can('manage-platform');

        $query = AuditLog::query()->with(['actor', 'origin', 'target'])->orderByDesc('id');

        if (! $canSeeAll) {
            // Admin sees only its own account events (origin or target).
            $query->where(function ($q) use ($effective): void {
                $q->where('origin_account_id', $effective->id)
                    ->orWhere('target_account_id', $effective->id);
            });
        } else {
            if ($request->filled('account_id')) {
                $accountId = (int) $request->input('account_id');
                $query->where(function ($q) use ($accountId): void {
                    $q->where('origin_account_id', $accountId)
                        ->orWhere('target_account_id', $accountId);
                });
            }
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_user_id', (int) $request->input('actor_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->input('action').'%');
        }

        return Inertia::render('admin/Audit/Index', [
            'logs' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only(['account_id', 'actor_id', 'action']),
        ]);
    }
}
