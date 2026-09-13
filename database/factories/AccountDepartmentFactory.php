<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountDepartment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountDepartment>
 */
class AccountDepartmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->unique()->jobTitle(),
        ];
    }
}
