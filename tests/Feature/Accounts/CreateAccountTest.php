<?php

use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Services\AccountProvisioningService;
use App\Services\InvitationService;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;

it('provisions an account B with the default plan and an admin invitation', function () {
    $this->seed(PlanSeeder::class);

    $platform = Account::factory()->create(['profile' => 'A']);
    $this->actingAs(User::factory()->create(['account_id' => $platform->id, 'role' => 'super_admin']));

    $result = app(AccountProvisioningService::class)->createForAdmin('Cliente X', 'Ada Admin', 'ada@clientex.com');

    expect($result['account']->profile)->toBe('B')
        ->and($result['account']->plan_id)->toBe(Plan::default()?->id)
        ->and($result['invitation']->account_id)->toBe($result['account']->id)
        ->and($result['invitation']->email)->toBe('ada@clientex.com')
        ->and($result['invitation']->role)->toBe('admin')
        ->and($result['token'])->toBeString()->not->toBeEmpty();
});

it('creates the provisioned admin user with the new account id on accept', function () {
    $this->seed(PlanSeeder::class);

    $platform = Account::factory()->create(['profile' => 'A']);
    $this->actingAs(User::factory()->create(['account_id' => $platform->id, 'role' => 'super_admin']));

    $result = app(AccountProvisioningService::class)->createForAdmin('Cliente Y', 'Bea Admin', 'bea@clientey.com');

    $user = app(InvitationService::class)->accept($result['token'], 'secret123', 'secret123');

    expect($user->account_id)->toBe($result['account']->id)
        ->and($user->account_id)->not->toBeNull()
        ->and($user->role)->toBe('admin');
});

it('denies account provisioning for non super admins', function () {
    $this->seed(PlanSeeder::class);

    $accountB = Account::factory()->create(['profile' => 'B']);
    $this->actingAs(User::factory()->create(['account_id' => $accountB->id, 'role' => 'admin']));

    expect(fn () => app(AccountProvisioningService::class)->createForAdmin('Cliente Z', 'Zed', 'zed@z.com'))
        ->toThrow(AuthorizationException::class);
});
