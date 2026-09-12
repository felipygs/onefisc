<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-platform');

        return Inertia::render('admin/Plans/Index', [
            'plans' => Plan::query()->orderBy('price_cents')->get(),
        ]);
    }

    public function edit(Plan $plan): Response
    {
        Gate::authorize('manage-platform');

        return Inertia::render('admin/Plans/Edit', [
            'plan' => $plan,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-platform');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'max_users' => ['required', 'integer', 'min:1'],
            'max_clients' => ['required', 'integer', 'min:1'],
            'modules' => ['required', 'array'],
            'modules.*' => ['string'],
            'monthly_query_volume' => ['required', 'integer', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (($validated['is_default'] ?? false) === true) {
            Plan::query()->update(['is_default' => false]);
        }

        Plan::create($validated);

        return redirect()->route('plans.index')->with('status', 'Plan criado.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        Gate::authorize('manage-platform');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'max_users' => ['required', 'integer', 'min:1'],
            'max_clients' => ['required', 'integer', 'min:1'],
            'modules' => ['required', 'array'],
            'modules.*' => ['string'],
            'monthly_query_volume' => ['required', 'integer', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (($validated['is_default'] ?? false) === true) {
            Plan::query()->where('id', '!=', $plan->id)->update(['is_default' => false]);
        }

        $plan->update($validated);

        return redirect()->route('plans.index')->with('status', 'Plan atualizado.');
    }
}
