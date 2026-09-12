<?php

namespace App\Http\Controllers;

use App\Http\Requests\OnboardingRequest;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * Show the onboarding form (only when the platform is empty).
     */
    public function create(): Response
    {
        abort_if(Account::exists(), 403, 'O onboarding já foi concluído. Solicite um convite.');

        return Inertia::render('Onboarding');
    }

    /**
     * Create the first account with its super admin.
     */
    public function store(OnboardingRequest $request): RedirectResponse
    {
        abort_if(Account::exists(), 403, 'O onboarding já foi concluído. Solicite um convite.');

        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $account = Account::create([
                'name' => $validated['account_name'],
                'profile' => 'A',
                'plan_id' => Plan::default()?->id,
            ]);

            $user = new User([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);
            $user->account_id = $account->id;
            $user->role = 'super_admin';
            $user->save();

            return $user;
        });

        Auth::login($user);

        return redirect('/dashboard');
    }
}
