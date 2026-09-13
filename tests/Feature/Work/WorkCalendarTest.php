<?php

use App\Models\Client;
use App\Models\WorkProcess;
use App\Models\WorkTask;

// TDD Task 3.3: visões mês/semana/dia do calendário por `due_on`, com
// inferência de competência só em mês civil cheio. Factories only.

beforeEach(function () {
    $this->withoutVite();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function calendarTask(Client $client, WorkProcess $process, string $title, array $overrides = []): WorkTask
{
    return WorkTask::factory()->create(array_merge([
        'account_id' => $client->account_id,
        'work_process_id' => $process->id,
        'client_id' => $client->id,
        'title' => $title,
    ], $overrides));
}

function calendarioUrl(string $cal, string $date): string
{
    return route('work.processos', ['view' => 'calendario', 'cal' => $cal, 'date' => $date]);
}

it('lists month tasks due within the full month with Y-m-d due dates', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    calendarTask($client, $process, 'Primeiro', ['due_on' => '2026-10-01', 'competence' => null]);
    calendarTask($client, $process, 'Meio', ['due_on' => '2026-10-15', 'competence' => null]);
    calendarTask($client, $process, 'Último', ['due_on' => '2026-10-31', 'competence' => null]);
    calendarTask($client, $process, 'Setembro', ['due_on' => '2026-09-30', 'competence' => null]);
    calendarTask($client, $process, 'Novembro', ['due_on' => '2026-11-01', 'competence' => null]);

    $this->get(calendarioUrl('month', '2026-10-15'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('work/Processos', false)
            ->where('calendar.cal', 'month')
            ->where('calendar.date', '2026-10-15')
            ->where('calendar.start', '2026-10-01')
            ->where('calendar.end', '2026-10-31')
            ->where('calendar.competence', '2026-10')
            ->has('calendar.tasks', 3)
            ->where('calendar.tasks.0.title', 'Primeiro')
            ->where('calendar.tasks.0.due_on', '2026-10-01')
            ->where('calendar.tasks.1.title', 'Meio')
            ->where('calendar.tasks.2.title', 'Último'));
});

it('lists week tasks Monday to Sunday containing the date', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    // 2026-10-15 é quinta: semana Seg 12 – Dom 18.
    calendarTask($client, $process, 'Segunda', ['due_on' => '2026-10-12', 'competence' => null]);
    calendarTask($client, $process, 'Domingo', ['due_on' => '2026-10-18', 'competence' => null]);
    calendarTask($client, $process, 'Domingo anterior', ['due_on' => '2026-10-11', 'competence' => null]);
    calendarTask($client, $process, 'Segunda seguinte', ['due_on' => '2026-10-19', 'competence' => null]);

    $this->get(calendarioUrl('week', '2026-10-15'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('calendar.cal', 'week')
            ->where('calendar.start', '2026-10-12')
            ->where('calendar.end', '2026-10-18')
            ->where('calendar.competence', null)
            ->has('calendar.tasks', 2)
            ->where('calendar.tasks.0.title', 'Segunda')
            ->where('calendar.tasks.1.title', 'Domingo'));
});

it('lists day tasks for the single requested date', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    calendarTask($client, $process, 'No dia', ['due_on' => '2026-10-20', 'competence' => null]);
    calendarTask($client, $process, 'Véspera', ['due_on' => '2026-10-19', 'competence' => null]);
    calendarTask($client, $process, 'Dia seguinte', ['due_on' => '2026-10-21', 'competence' => null]);

    $this->get(calendarioUrl('day', '2026-10-20'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('calendar.cal', 'day')
            ->where('calendar.start', '2026-10-20')
            ->where('calendar.end', '2026-10-20')
            ->where('calendar.competence', null)
            ->has('calendar.tasks', 1)
            ->where('calendar.tasks.0.title', 'No dia'));
});

it('shows a prior-competence task due in range on day and week without materializing', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = competenceProcess($account);
    competenceAssociate($process, $client);
    $this->actingAs($admin);

    // Tarefa da competência de setembro vencendo 20 de outubro: aparece pelo
    // vencimento, não por inferência da competência de outubro.
    calendarTask($client, $process, 'Setembro vence outubro', [
        'competence' => '2026-09', 'due_on' => '2026-10-20',
    ]);
    calendarTask($client, $process, 'Outubro outro dia', [
        'competence' => '2026-10', 'due_on' => '2026-10-05',
    ]);

    $before = WorkTask::where('work_process_id', $process->id)->count();

    $this->get(calendarioUrl('day', '2026-10-20'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('calendar.tasks', 1)
            ->where('calendar.tasks.0.title', 'Setembro vence outubro'));

    $this->get(calendarioUrl('week', '2026-10-20'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('calendar.start', '2026-10-19')
            ->where('calendar.end', '2026-10-25')
            ->has('calendar.tasks', 1)
            ->where('calendar.tasks.0.title', 'Setembro vence outubro'));

    // Semana/dia nunca inferem/materializam, mesmo com os dois limites no
    // mesmo mês: nenhuma linha nova foi criada.
    expect(WorkTask::where('work_process_id', $process->id)->count())->toBe($before);
});

it('materializes the competence when opening a full month', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = competenceProcess($account);
    competenceAssociate($process, $client);
    $this->actingAs($admin);

    expect(WorkTask::where('work_process_id', $process->id)->count())->toBe(0);

    $this->get(calendarioUrl('month', '2026-10-15'))->assertOk();

    // competenceProcess tem 2 definições ⇒ 2 instâncias de outubro criadas.
    expect(WorkTask::where('work_process_id', $process->id)
        ->where('competence', '2026-10')->count())->toBe(2);
});

it('lists timeless dateless rows separately and never plots them', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    calendarTask($client, $process, 'Sem data', ['competence' => null, 'due_on' => null]);
    calendarTask($client, $process, 'Com data', ['competence' => null, 'due_on' => '2026-10-10']);

    $this->get(calendarioUrl('month', '2026-10-15'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('calendar.tasks', 1)
            ->where('calendar.tasks.0.title', 'Com data')
            ->has('calendar.dateless', 1)
            ->where('calendar.dateless.0.title', 'Sem data')
            ->where('calendar.dateless.0.due_on', null));
});

it('flags overdue tasks with the lists rule', function () {
    [$account, $admin] = workAccountUser('admin');
    $client = Client::factory()->create(['account_id' => $account->id]);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($admin);

    $past = now()->subDays(3)->format('Y-m-d');
    $future = now()->addDays(3)->format('Y-m-d');

    calendarTask($client, $process, 'Atrasada', [
        'competence' => null, 'due_on' => $past, 'status' => 'todo',
    ]);
    calendarTask($client, $process, 'Concluída atrasada', [
        'competence' => null, 'due_on' => $past, 'status' => 'done',
    ]);
    calendarTask($client, $process, 'Futura', [
        'competence' => null, 'due_on' => $future, 'status' => 'todo',
    ]);

    $month = now()->format('Y-m').'-15';

    $this->get(calendarioUrl('month', $month))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('calendar.tasks', 3)
            ->where('calendar.tasks.0.title', 'Atrasada')
            ->where('calendar.tasks.0.is_overdue', true)
            ->where('calendar.tasks.1.title', 'Concluída atrasada')
            ->where('calendar.tasks.1.is_overdue', false)
            ->where('calendar.tasks.2.title', 'Futura')
            ->where('calendar.tasks.2.is_overdue', false));
});

it('scopes calendar tasks to clients visible to the member', function () {
    [$account, $user] = workAccountUser('user');
    $mine = Client::factory()->create(['account_id' => $account->id]);
    $other = Client::factory()->create(['account_id' => $account->id]);
    assignClientToUser($mine, $user);
    $process = WorkProcess::factory()->create(['account_id' => $account->id]);
    $this->actingAs($user);

    calendarTask($mine, $process, 'Minha', ['competence' => null, 'due_on' => '2026-10-10']);
    calendarTask($other, $process, 'Alheia', ['competence' => null, 'due_on' => '2026-10-10']);
    calendarTask($other, $process, 'Alheia sem data', ['competence' => null, 'due_on' => null]);

    $this->get(calendarioUrl('month', '2026-10-15'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('calendar.tasks', 1)
            ->where('calendar.tasks.0.title', 'Minha')
            ->has('calendar.dateless', 0));
});

it('rejects invalid calendar view and date with 422', function () {
    [$account, $admin] = workAccountUser('admin');
    $this->actingAs($admin);

    $this->getJson(calendarioUrl('fortnight', '2026-10-15'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('cal');

    $this->getJson(calendarioUrl('month', 'outubro'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('date');

    $this->getJson(calendarioUrl('month', '2026-02-30'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('date');
});
