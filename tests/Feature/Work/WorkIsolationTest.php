<?php

use App\Models\Account;
use App\Models\AccountDepartment;
use App\Models\Client;
use App\Models\ClientTag;
use App\Models\WorkProcess;
use App\Models\WorkProcessClient;
use App\Models\WorkProcessTaskDefinition;
use App\Models\WorkTask;
use App\Support\CurrentAccount;

it('keeps processes, tasks, departments and tags invisible across accounts', function () {
    $accountA = Account::factory()->create();
    $accountB = Account::factory()->create();

    $processA = WorkProcess::factory()->create(['account_id' => $accountA->id]);
    $processB = WorkProcess::factory()->create(['account_id' => $accountB->id]);

    $clientA = Client::factory()->create(['account_id' => $accountA->id]);
    $taskA = WorkTask::factory()->create([
        'account_id' => $accountA->id,
        'work_process_id' => $processA->id,
        'client_id' => $clientA->id,
    ]);
    $clientB = Client::factory()->create(['account_id' => $accountB->id]);
    WorkTask::factory()->create([
        'account_id' => $accountB->id,
        'work_process_id' => $processB->id,
        'client_id' => $clientB->id,
    ]);

    $departmentA = AccountDepartment::factory()->create(['account_id' => $accountA->id]);
    AccountDepartment::factory()->create(['account_id' => $accountB->id]);

    $tagA = ClientTag::factory()->create(['account_id' => $accountA->id]);
    ClientTag::factory()->create(['account_id' => $accountB->id]);

    CurrentAccount::set($accountB);

    expect(WorkProcess::find($processA->id))->toBeNull()
        ->and(WorkProcess::find($processB->id)?->id)->toBe($processB->id)
        ->and(WorkTask::find($taskA->id))->toBeNull()
        ->and(AccountDepartment::find($departmentA->id))->toBeNull()
        ->and(ClientTag::find($tagA->id))->toBeNull();
});

it('removes definitions, associations and tasks when a process is deleted', function () {
    $process = WorkProcess::factory()->create();
    $definition = WorkProcessTaskDefinition::factory()->create(['work_process_id' => $process->id]);
    $client = Client::factory()->create(['account_id' => $process->account_id]);
    $association = WorkProcessClient::factory()->create([
        'work_process_id' => $process->id,
        'client_id' => $client->id,
    ]);
    $task = WorkTask::factory()->create([
        'account_id' => $process->account_id,
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'work_process_task_definition_id' => $definition->id,
    ]);

    $process->delete();

    expect(WorkProcessTaskDefinition::find($definition->id))->toBeNull()
        ->and(WorkProcessClient::find($association->id))->toBeNull()
        ->and(WorkTask::withoutGlobalScopes()->find($task->id))->toBeNull();
});
