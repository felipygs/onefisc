<?php

use App\Http\Controllers\OnboardingController;
use App\Models\Account;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('onboarding', [OnboardingController::class, 'create'])->name('onboarding');
Route::post('onboarding', [OnboardingController::class, 'store'])->name('onboarding.store');

Route::match(['get', 'post'], 'register', function () {
    abort_if(Account::exists(), 403, 'O registro público está fechado. Solicite um convite.');

    return redirect()->route('onboarding');
})->name('register');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
