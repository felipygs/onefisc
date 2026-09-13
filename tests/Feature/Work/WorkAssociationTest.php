<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientTag;
use App\Models\WorkProcess;
use App\Models\WorkProcessClient;
use App\Models\WorkProcessTaskDefinition;
use App\Models\WorkTask;
use Illuminate\Support\Facades\DB;

// Inertia renders resolve without built assets, mirroring WorkProcessTest.
beforeEach(function () {
    $this->withoutVite();
});

if (! function_exists('associationTagClient')) {
    function associationTagClient(Client $client, ClientTag $tag): void
    {
        DB::table('client_tag')->insertOrIgnore([
            'client_id' => $client->id,
            'client_tag_id' => $tag->id,
        ]);
    }
}

if (! function_exists('associationProcessWithDefinitions')) {
    function associationProcessWithDefinitions(Account $account, array $overrides = []): WorkProcess
    {
        $process = WorkProcess::factory()->create(array_merge(['account_id' => $account->id], $overrides));
        WorkProcessTaskDefinition::factory()->create([
            'work_process_id' => $process->id, 'title' => 'Etapa um', 'position' => 0, 'priority' => 'high',
        ]);
        WorkProcessTaskDefinition::factory()->create([
            'work_process_id' => $process->id, 'title' => 'Etapa dois', 'position' => 1,
        ]);

        return $process;
    }
}

it('materializes tasks only for regime-matching clients', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $match = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    $other = Client::factory()->create(['account_id' => $account->id, 'regime' => 'lucro_presumido']);

    $process = associationProcessWithDefinitions($account, ['association_regimes' => ['simples_nacional']]);

    $this->put(route('work.processes.clients.update', $process), [])->assertRedirect();

    expect(WorkProcessClient::where('work_process_id', $process->id)->pluck('client_id')->all())
        ->toBe([$match->id]);

    $tasks = WorkTask::where('work_process_id', $process->id)->where('client_id', $match->id)->orderBy('position')->get();
    expect($tasks)->toHaveCount(2)
        ->and($tasks[0]->title)->toBe('Etapa um')
        ->and($tasks[0]->status)->toBe('todo')
        ->and($tasks[0]->position)->toBe(0)
        ->and($tasks[0]->priority)->toBe('high')
        ->and($tasks[0]->competence)->toBeNull()
        ->and($tasks[0]->start_at)->toBeNull()
        ->and($tasks[0]->due_on)->toBeNull()
        ->and($tasks[1]->title)->toBe('Etapa dois')
        ->and($tasks[1]->status)->toBe('todo');

    expect(WorkTask::where('work_process_id', $process->id)->where('client_id', $other->id)->count())->toBe(0);
});

it('selects every account client when regimes are empty or all', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $first = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    $second = Client::factory()->create(['account_id' => $account->id, 'regime' => 'lucro_real']);

    $emptyRules = associationProcessWithDefinitions($account);
    $allKeyword = associationProcessWithDefinitions($account, ['association_regimes' => ['all']]);

    $this->put(route('work.processes.clients.update', $emptyRules), [])->assertRedirect();
    $this->put(route('work.processes.clients.update', $allKeyword), [])->assertRedirect();

    foreach ([$emptyRules, $allKeyword] as $process) {
        expect(WorkProcessClient::where('work_process_id', $process->id)->pluck('client_id')->sort()->values()->all())
            ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
    }
});

it('narrows regime matches through the tag filter', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $tag = ClientTag::factory()->create(['account_id' => $account->id]);
    $tagged = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    $untagged = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    associationTagClient($tagged, $tag);

    $process = associationProcessWithDefinitions($account, [
        'association_regimes' => ['simples_nacional'],
        'association_tag_ids' => [$tag->id],
    ]);

    $this->put(route('work.processes.clients.update', $process), [])->assertRedirect();

    expect(WorkProcessClient::where('work_process_id', $process->id)->pluck('client_id')->all())
        ->toBe([$tagged->id])
        ->and(WorkTask::where('work_process_id', $process->id)->where('client_id', $untagged->id)->count())->toBe(0);
});

it('removes excluded clients from the effective set', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $kept = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    $excluded = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);

    $process = associationProcessWithDefinitions($account, [
        'association_regimes' => ['simples_nacional'],
        'excluded_client_ids' => [$excluded->id],
    ]);

    $this->put(route('work.processes.clients.update', $process), [])->assertRedirect();

    expect(WorkProcessClient::where('work_process_id', $process->id)->pluck('client_id')->all())
        ->toBe([$kept->id]);
});

it('adds extra clients outside the rule match', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $match = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    $outsider = Client::factory()->create(['account_id' => $account->id, 'regime' => 'lucro_real']);

    $process = associationProcessWithDefinitions($account, [
        'association_regimes' => ['simples_nacional'],
        'extra_client_ids' => [$outsider->id],
    ]);

    $this->put(route('work.processes.clients.update', $process), [])->assertRedirect();

    expect(WorkProcessClient::where('work_process_id', $process->id)->pluck('client_id')->sort()->values()->all())
        ->toBe(collect([$match->id, $outsider->id])->sort()->values()->all())
        ->and(WorkTask::where('work_process_id', $process->id)->where('client_id', $outsider->id)->count())->toBe(2);
});

it('lets an explicit client list override the computed rules', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $ruleMatch = Client::factory()->create(['account_id' => $account->id, 'regime' => 'simples_nacional']);
    $chosen = Client::factory()->create(['account_id' => $account->id, 'regime' => 'lucro_presumido']);

    $process = associationProcessWithDefinitions($account, ['association_regimes' => ['simples_nacional']]);

    $this->put(route('work.processes.clients.update', $process), ['client_ids' => [$chosen->id]])->assertRedirect();

    expect(WorkProcessClient::where('work_process_id', $process->id)->pluck('client_id')->all())
        ->toBe([$chosen->id])
        ->and(WorkTask::where('work_process_id', $process->id)->where('client_id', $ruleMatch->id)->count())->toBe(0)
        ->and(WorkTask::where('work_process_id', $process->id)->where('client_id', $chosen->id)->count())->toBe(2);
});

it('re-applies adding only deltas while preserving done tasks', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $first = Client::factory()->create(['account_id' => $account->id]);
    $second = Client::factory()->create(['account_id' => $account->id]);

    $process = associationProcessWithDefinitions($account);

    $this->put(route('work.processes.clients.update', $process), [])->assertRedirect();
    expect(WorkTask::where('work_process_id', $process->id)->count())->toBe(4);

    WorkTask::where('work_process_id', $process->id)->where('client_id', $first->id)->orderBy('position')->firstOrFail()
        ->update(['status' => 'done']);

    $third = Client::factory()->create(['account_id' => $account->id]);

    $this->put(route('work.processes.clients.update', $process), [])->assertRedirect();

    expect(WorkTask::where('work_process_id', $process->id)->count())->toBe(6)
        ->and(WorkTask::where('work_process_id', $process->id)->where('client_id', $first->id)->count())->toBe(2)
        ->and(WorkTask::where('work_process_id', $process->id)->where('client_id', $second->id)->count())->toBe(2)
        ->and(WorkTask::where('work_process_id', $process->id)->where('client_id', $third->id)->count())->toBe(2)
        ->and(WorkTask::where('work_process_id', $process->id)->where('client_id', $first->id)->where('status', 'done')->count())->toBe(1);
});

it('disassociates shrunken clients removing only non-done tasks', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $kept = Client::factory()->create(['account_id' => $account->id]);
    $removed = Client::factory()->create(['account_id' => $account->id]);

    $process = associationProcessWithDefinitions($account);

    $this->put(route('work.processes.clients.update', $process), [])->assertRedirect();
    expect(WorkTask::where('work_process_id', $process->id)->count())->toBe(4);

    WorkTask::where('work_process_id', $process->id)->where('client_id', $removed->id)->orderBy('position')->firstOrFail()
        ->update(['status' => 'done']);

    $this->put(route('work.processes.clients.update', $process), ['client_ids' => [$kept->id]])->assertRedirect();

    expect(WorkProcessClient::where('work_process_id', $process->id)->pluck('client_id')->all())
        ->toBe([$kept->id]);

    $remaining = WorkTask::where('work_process_id', $process->id)->where('client_id', $removed->id)->get();
    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()?->status)->toBe('done')
        ->and(WorkTask::where('work_process_id', $process->id)->where('client_id', $kept->id)->count())->toBe(2);
});

it('holds later tasks in backlog when cascade is on and releases all as todo when off', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $client = Client::factory()->create(['account_id' => $account->id]);

    $cascading = associationProcessWithDefinitions($account, ['cascade_execution' => true]);
    $flat = associationProcessWithDefinitions($account, ['cascade_execution' => false]);

    $this->put(route('work.processes.clients.update', $cascading), ['client_ids' => [$client->id]])->assertRedirect();
    $this->put(route('work.processes.clients.update', $flat), ['client_ids' => [$client->id]])->assertRedirect();

    $cascaded = WorkTask::where('work_process_id', $cascading->id)->orderBy('position')->pluck('status')->all();
    $released = WorkTask::where('work_process_id', $flat->id)->orderBy('position')->pluck('status')->all();

    expect($cascaded)->toBe(['todo', 'backlog'])
        ->and($released)->toBe(['todo', 'todo']);
});

it('forbids collaborators from applying associations', function () {
    [$account, $user] = workAccountUser('user');
    $this->actingAs($user);

    $process = associationProcessWithDefinitions($account);

    $this->put(route('work.processes.clients.update', $process), [])->assertForbidden();

    expect(WorkProcessClient::where('work_process_id', $process->id)->count())->toBe(0);
});

it('answers 404 for processes of another account', function () {
    [$accountA] = workAccountUser('admin');
    [$accountB, $adminB] = workAccountUser('admin');

    $foreign = associationProcessWithDefinitions($accountA);

    $this->actingAs($adminB);

    $this->put(route('work.processes.clients.update', $foreign), [])->assertNotFound();
    expect(WorkProcessClient::where('work_process_id', $foreign->id)->count())->toBe(0);
});

it('rejects unknown or foreign client ids with 422', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $otherAccount = Account::factory()->create(['profile' => 'B']);
    $foreignClient = Client::factory()->create(['account_id' => $otherAccount->id]);

    $process = associationProcessWithDefinitions($account);

    $this->putJson(route('work.processes.clients.update', $process), ['client_ids' => [999999]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('client_ids.0');

    $this->putJson(route('work.processes.clients.update', $process), ['client_ids' => [$foreignClient->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('client_ids.0');

    expect(WorkProcessClient::where('work_process_id', $process->id)->count())->toBe(0);
});
