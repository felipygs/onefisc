<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Models\Account;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('onboarding', [OnboardingController::class, 'create'])->name('onboarding');
Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

Route::get('invitations/accept/{token}', [InvitationController::class, 'show'])->name('invitations.accept');
Route::post('invitations/accept/{token}', [InvitationController::class, 'accept'])->name('invitations.accept.store');

Route::match(['get', 'post'], 'register', function () {
    abort_if(Account::exists(), 403, 'O registro público está fechado. Solicite um convite.');

    return redirect()->route('onboarding');
})->name('register');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
    Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
    Route::match(['put', 'patch'], 'plans/{plan}', [PlanController::class, 'update'])->name('plans.update');

    Route::patch('accounts/{account}/plan', [AccountController::class, 'updatePlan'])->name('accounts.plan.update');
});

require __DIR__.'/settings.php';
