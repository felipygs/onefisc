<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountSwitcherController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentDashboardController;
use App\Http\Controllers\FiscalDownloadController;
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
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
    Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
    Route::match(['put', 'patch'], 'plans/{plan}', [PlanController::class, 'update'])->name('plans.update');

    Route::patch('accounts/{account}/plan', [AccountController::class, 'updatePlan'])->name('accounts.plan.update');

    Route::get('switcher', [AccountSwitcherController::class, 'index'])->name('switcher.index');
    Route::post('switcher/{account}', [AccountSwitcherController::class, 'select'])->name('switcher.select');
    Route::delete('switcher', [AccountSwitcherController::class, 'destroy'])->name('switcher.destroy');

    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

    Route::get('documents', [DocumentDashboardController::class, 'index'])->name('documents.index');
    Route::get('documents/all', [DocumentDashboardController::class, 'all'])->name('documents.all');
    Route::get('documents/clients', [DocumentDashboardController::class, 'clients'])->name('documents.clients');

    Route::get('fiscal/documents/{document}/download', [FiscalDownloadController::class, 'show'])->name('fiscal.download');
    Route::get('fiscal/documents/{document}/danfe', [FiscalDownloadController::class, 'pdf'])->name('fiscal.danfe');

    Route::post('clients/bulk-destroy', [ClientController::class, 'bulkDestroy'])->name('clients.bulk-destroy');
    Route::post('clients/{client}/certificate', [CertificateController::class, 'store'])->name('certificates.store');
    Route::put('clients/{client}/portal-password', [CertificateController::class, 'updatePortalPassword'])->name('certificates.portal-password');
    Route::delete('clients/{client}/certificate', [CertificateController::class, 'destroy'])->name('certificates.destroy');
    Route::resource('clients', ClientController::class);
});

// Signed file streams require the session AND the signature: auth binds the
// stream to the account context (ResolveAccountContext), the short-lived
// signature keeps the URL opaque and expiring. The DanfeModal iframe and the
// download fetch always run inside the authenticated session, so they keep
// working; logged-out direct hits redirect to login, cross-account replays
// fail closed with 404. Paths expose ids only, never storage references,
// CNPJ or access keys.
Route::get('fiscal/files/{document}/xml', [FiscalDownloadController::class, 'streamXml'])->name('fiscal.download.file')->middleware(['auth', 'signed']);
Route::get('fiscal/files/{document}/pdf', [FiscalDownloadController::class, 'streamPdf'])->name('fiscal.danfe.file')->middleware(['auth', 'signed']);

require __DIR__.'/settings.php';
