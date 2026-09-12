<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringCheck>
 */
class MonitoringCheckFactory extends Factory
{
    protected $model = MonitoringCheck::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => Client::factory(),
            'status' => 'ok',
        ];
    }
}
