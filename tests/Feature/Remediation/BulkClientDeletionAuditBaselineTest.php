<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;

it('records one attributed deletion audit event for every Client removed in a bulk request', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);
    $first = Client::factory()->create(['account_id' => $account->id]);
    $second = Client::factory()->create(['account_id' => $account->id]);

    $this->actingAs($admin)
        ->post(route('clients.bulk-destroy'), ['ids' => [$first->id, $second->id]])
        ->assertRedirect(route('clients.index'));

    $this->assertDatabaseMissing('clients', ['id' => $first->id]);
    $this->assertDatabaseMissing('clients', ['id' => $second->id]);

    $auditLogs = AuditLog::query()
        ->where('action', 'clients.deleted')
        ->orderBy('id')
        ->get();

    expect($auditLogs)->toHaveCount(2)
        ->and($auditLogs->pluck('metadata.id')->sort()->values()->all())
        ->toBe([$first->id, $second->id])
        ->and($auditLogs->every(fn (AuditLog $log): bool => $log->actor_user_id === $admin->id
            && $log->origin_account_id === $account->id
            && $log->target_account_id === $account->id))
        ->toBeTrue();
});
