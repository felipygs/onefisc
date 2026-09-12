<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;

it('presents canonical Client invitation and Plan audit actions in human-readable notification language', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $user = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);

    foreach (['clients.created', 'invitation.accepted', 'plan.switch'] as $action) {
        AuditLog::factory()->create([
            'actor_user_id' => $user->id,
            'origin_account_id' => $account->id,
            'target_account_id' => $account->id,
            'action' => $action,
        ]);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('notifications', 3)
            ->where('notifications.0.body', 'Trocou o Plan de uma Account.')
            ->where('notifications.1.body', 'Aceitou um Convite.')
            ->where('notifications.2.body', 'Cadastrou um Client.'));
});
