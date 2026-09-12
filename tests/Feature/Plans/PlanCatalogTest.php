<?php

use App\Models\Plan;
use Database\Seeders\PlanSeeder;

it('has a default basic plan after seeding', function () {
    $this->seed(PlanSeeder::class);

    $default = Plan::query()->where('is_default', true)->first();

    expect($default)->not->toBeNull()
        ->and($default->name)->toBe('Básico');
});

it('returns the basic plan via Plan::default', function () {
    $this->seed(PlanSeeder::class);

    $default = Plan::default();

    expect($default)->not->toBeNull()
        ->and($default->name)->toBe('Básico');
});
