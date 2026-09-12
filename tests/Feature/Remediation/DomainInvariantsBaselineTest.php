<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects a fourteen-digit Client CNPJ whose official check digits are invalid', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);

    $this->actingAs($admin)
        ->post(route('clients.store'), [
            'cnpj' => '12345678000196',
            'razao_social' => 'CNPJ com dígito inválido',
            'regime' => 'simples',
            'contador_responsavel' => 'Contador',
        ])
        ->assertSessionHasErrors('cnpj');

    expect(Client::withoutGlobalScopes()->where('cnpj', '12345678000196')->exists())->toBeFalse();
});

it('does not persist an unsupported Account profile', function () {
    $attemptedName = 'Perfil inválido — fix round 1';

    expect(fn () => DB::table('accounts')->insert([
        'name' => $attemptedName,
        'profile' => 'Z',
        'created_at' => now(),
        'updated_at' => now(),
    ]))
        ->toThrow(QueryException::class);

    $this->assertDatabaseMissing('accounts', [
        'name' => $attemptedName,
    ]);
});
