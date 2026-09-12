<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\Plan;

it('returns an unsuccessful outcome when requested monitoring cannot complete for a Client', function () {
    $plan = Plan::create([
        'name' => 'Sem monitoramento',
        'price_cents' => 0,
        'max_users' => 10,
        'max_clients' => 10,
        'modules' => ['clients'],
        'monthly_query_volume' => 10,
        'is_default' => false,
    ]);
    $account = Account::factory()->create(['profile' => 'B', 'plan_id' => $plan->id]);
    Client::factory()->create(['account_id' => $account->id]);

    $this->artisan('monitoring:run')
        ->assertExitCode(1);
});
