# Multi-Account (A/B + Plans) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar o modelo multi-account (Accounts A/B, papéis, onboarding, Plans, Clients, seletor, auditoria) no Laravel 13 + Inertia + Vue.

**Architecture:** Isolamento por `account_id` com global scope; contexto de Account resolvido em middleware (`currentAccount` + props Inertia); papéis como enum em `users.role` com Gates/Policies; seletor via sessão; Plans em tabela com checagem em services; auditoria via observer best-effort; convites com token hash.

**Tech Stack:** Laravel 13 (PHP 8.3), Pest, Vue 3 + Inertia 3, TypeScript, Tailwind v4, Nuxt UI, Wayfinder, SQLite (dev) / sqlite :memory: (testes).

**Spec:** `openspec/changes/multi-account-a-b-planos/` — proposal.md (porquê + UI Catalog de componentes Nuxt UI por página), specs/ (7 capabilities com cenários WHEN/THEN), design.md (decisões), tasks.md (contrato de escopo, 7 grupos). Este plan referencia os IDs do tasks.md e nunca os contradiz. Componentes e páginas seguem o UI Catalog do proposal; shell padrão `UApp → UDashboardGroup → UDashboardSidebar → UDashboardPanel`.

## Global Constraints

- PHP 8.3, Laravel 13, Pest com plugin Laravel; `php artisan test` roda em sqlite :memory:.
- PHPStan level 7 (`phpstan.neon`) e Pint (`pint.json`) devem passar; rodar `composer lint` antes de commitar PHP.
- Frontend: `npm run types:check` (vue-tsc) e `npm run check` (vp) devem passar; `npm run build` deve compilar.
- Rotas frontend via Wayfinder (`resources/js/wayfinder`), nunca hardcodar URLs; componentes Nuxt UI com prefixo `U`.
- Commits em conventional commits com escopo (`feat(accounts): ...`); um commit por task com o tasks.md marcado no mesmo commit.
- Testes: Factories em `database/factories/`; HTTP/comportamento em `tests/Feature/`, lógica pura em `tests/Unit/`; arquivos `*Test.php`.
- Glossário canônico em `CONTEXT.md`: Account, Account A, Account B, super_admin, admin, operador, user, Plan, Client, Onboarding, Convite, Seletor de Accounts.
- TDD estrito: teste vermelho antes de cada implementação; código escrito antes do teste é deletado, não avisado.

---

## File Map (criar / modificar)

**Criar (backend):**
- `database/migrations/2026_09_12_000001_create_accounts_table.php` — accounts (id, name, profile enum A/B, plan_id, timestamps).
- `database/migrations/2026_09_12_000002_create_plans_table.php` — plans (id, name, price_cents, max_users, max_clients, modules JSON, monthly_query_volume, is_default bool).
- `database/migrations/2026_09_12_000003_add_account_and_role_to_users_table.php` — users.account_id FK nullable + role enum default `user`.
- `database/migrations/2026_09_12_000004_create_clients_table.php` — clients (id, account_id FK, cnpj, razao_social, regime, contador_responsavel, timestamps; unique [account_id, cnpj]).
- `database/migrations/2026_09_12_000005_create_invitations_table.php` — invitations (id, account_id FK, name, email, role, token_hash unique, expires_at, accepted_at nullable).
- `database/migrations/2026_09_12_000006_create_audit_logs_table.php` — audit_logs (id, actor_user_id, origin_account_id, target_account_id nullable, action, metadata JSON, created_at).
- `database/seeders/PlanSeeder.php` — 3 Plans (básico padrão, intermediário, avançado).
- `database/factories/AccountFactory.php`, `PlanFactory.php`, `ClientFactory.php`, `InvitationFactory.php`.
- `app/Models/Account.php`, `Plan.php`, `Client.php`, `Invitation.php`, `AuditLog.php`.
- `app/Concerns/BelongsToAccount.php` — trait com global scope por `currentAccount`.
- `app/Http/Middleware/ResolveAccountContext.php` — resolve Account efetiva (usuário ou `switch_account_id` se super_admin).
- `app/Support/CurrentAccount.php` — helper (`resolve()`, `set()`, `clear()`, `isSwitching()`).
- `app/Services/PlanLimitService.php` — `assertCanCreateUser()`, `assertCanCreateClient()`, `assertModuleAllowed()`, `assertQueryVolume()`.
- `app/Services/AccountProvisioningService.php` — cria Account B + convida admin inicial.
- `app/Services/InvitationService.php` — cria convite, aceita (cria user), expiração.
- `app/Services/AccountSwitcher.php` — enter/exit com validação de super_admin.
- `app/Observers/AuditObserver.php` + models observados.
- `app/Policies/AccountPolicy.php`, `PlanPolicy.php`, `ClientPolicy.php`, `UserPolicy.php` (ou Gates em `AppServiceProvider`).
- `app/Http/Controllers/OnboardingController.php`, `InvitationController.php`, `AccountController.php` (A), `PlanController.php` (A), `ClientController.php`, `AccountSwitchController.php`, `AuditLogController.php`.
- `app/Http/Requests/*` — StoreAccount, StorePlan, UpdatePlan, StoreClient, UpdateClient, StoreInvitation, AcceptInvitation, OnboardingRequest.
- `tests/Feature/Accounts/*Test.php`, `Roles/*Test.php`, `OnboardingTest.php`, `InvitationsTest.php`, `Plans/*Test.php`, `Clients/*Test.php`, `AccountSwitchTest.php`, `AuditTest.php`.

**Modificar (backend):**
- `app/Models/User.php` — `account_id`, `role`, relações `account()`, casts.
- `app/Http/Middleware/HandleInertiaRequests.php` — share `currentAccount`, `isSwitching`, `permissions`.
- `bootstrap/app.php` — registra `ResolveAccountContext` no grupo web.
- `config/fortify.php` — remove `Features::registration()` (registro fechado).
- `app/Actions/Fortify/CreateNewUser.php` — bloqueia criação quando há Accounts (só onboarding permite).
- `routes/web.php` — rotas de onboarding, convites, accounts, plans, clients, switch, audit.

**Criar (frontend):**
- `resources/js/pages/Onboarding.vue`, `resources/js/pages/invitations/Accept.vue`.
- `resources/js/pages/admin/Accounts/Index.vue`, `Create.vue`, `resources/js/pages/admin/Plans/Index.vue`, `Edit.vue`.
- `resources/js/pages/clients/Index.vue`, `Create.vue`, `Edit.vue`, `Show.vue`.
- `resources/js/pages/admin/Audit/Index.vue`.
- `resources/js/components/AccountSwitcher.vue` (seletor + banner "atuando como").
- `resources/js/composables/useCurrentAccount.ts`, `usePermissions.ts`.

---

### Task 1 — Migrations accounts + plans + seed (cobre tasks 1.1)

**Files:**
- Create: `database/migrations/2026_09_12_000001_create_accounts_table.php`
- Create: `database/migrations/2026_09_12_000002_create_plans_table.php`
- Create: `database/seeders/PlanSeeder.php`
- Test: `tests/Feature/Plans/PlanCatalogTest.php`

**Interfaces:**
- Consumes: nada (primeira task).
- Produces: tabelas `accounts(profile: A|B)`, `plans` com `is_default`; `Plan::default()` retorna o básico.

- [ ] **Step 1: Escrever o teste que falha**

```php
// tests/Feature/Plans/PlanCatalogTest.php
use App\Models\Plan;

it('has a default basic plan after seeding', function () {
    $this->seed(\Database\Seeders\PlanSeeder::class);

    $default = Plan::query()->where('is_default', true)->first();

    expect($default)->not->toBeNull()
        ->and($default->name)->toBe('Básico');
});
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test --filter="has a default basic plan"`
Expected: FAIL (tabelas `accounts`/`plans` não existem).

- [ ] **Step 3: Criar as migrations e o seeder mínimos**

```php
// 2026_09_12_000001_create_accounts_table.php
Schema::create('accounts', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('profile', 1); // A | B
    $table->foreignId('plan_id')->nullable()->constrained('plans');
    $table->timestamps();
});
```

```php
// 2026_09_12_000002_create_plans_table.php
Schema::create('plans', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->unsignedInteger('price_cents')->default(0);
    $table->unsignedInteger('max_users');
    $table->unsignedInteger('max_clients');
    $table->json('modules');
    $table->unsignedInteger('monthly_query_volume');
    $table->boolean('is_default')->default(false);
    $table->timestamps();
});
```

```php
// database/seeders/PlanSeeder.php
Plan::create(['name' => 'Básico', 'price_cents' => 0, 'max_users' => 3, 'max_clients' => 10, 'modules' => ['clients'], 'monthly_query_volume' => 100, 'is_default' => true]);
Plan::create(['name' => 'Intermediário', 'price_cents' => 9900, 'max_users' => 10, 'max_clients' => 50, 'modules' => ['clients', 'monitoring'], 'monthly_query_volume' => 1000, 'is_default' => false]);
Plan::create(['name' => 'Avançado', 'price_cents' => 29900, 'max_users' => 50, 'max_clients' => 200, 'modules' => ['clients', 'monitoring', 'comms', 'docs'], 'monthly_query_volume' => 10000, 'is_default' => false]);
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test --filter="has a default basic plan"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_09_12_00000*.php database/seeders/PlanSeeder.php tests/Feature/Plans/PlanCatalogTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(plans): catalogo de plans com basico padrao"
```

---

### Task 2 — users.account_id + role (cobre tasks 1.2)

**Files:**
- Create: `database/migrations/2026_09_12_000003_add_account_and_role_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Accounts/UserBelongsToAccountTest.php`

**Interfaces:**
- Consumes: tabelas `accounts` (Task 1).
- Produces: `User->account`, `User->role` (`super_admin|admin|operador|user`); helper `User::isSuperAdmin()`.

- [ ] **Step 1: Escrever o teste que falha**

```php
// tests/Feature/Accounts/UserBelongsToAccountTest.php
use App\Models\Account;
use App\Models\User;

it('links a user to exactly one account with a role', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $user = User::factory()->create(['account_id' => $account->id, 'role' => 'admin']);

    expect($user->account->is($account))->toBeTrue()
        ->and($user->role)->toBe('admin');
});
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test --filter="links a user to exactly one account"`
Expected: FAIL (colunas/factory inexistentes).

- [ ] **Step 3: Migration + model + factory mínimos**

```php
// migration up()
Schema::table('users', function (Blueprint $table) {
    $table->foreignId('account_id')->nullable()->constrained('accounts');
    $table->string('role', 20)->default('user');
});
```

```php
// app/Models/User.php (adicionar)
public function account(): BelongsTo
{
    return $this->belongsTo(Account::class);
}

public function isSuperAdmin(): bool
{
    return $this->role === 'super_admin';
}
```

Ajustar `UserFactory` com `account_id => null, role => 'user'` por padrão.

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test --filter="links a user to exactly one account"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_09_12_000003*.php app/Models/User.php database/factories/UserFactory.php tests/Feature/Accounts/UserBelongsToAccountTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(accounts): vinculo usuario-account com papel"
```

---

### Task 3 — Tabelas clients, invitations, audit_logs (cobre tasks 1.3)

**Files:**
- Create: `database/migrations/2026_09_12_000004_create_clients_table.php`
- Create: `database/migrations/2026_09_12_000005_create_invitations_table.php`
- Create: `database/migrations/2026_09_12_000006_create_audit_logs_table.php`
- Test: `tests/Feature/Accounts/SchemaTest.php`

**Interfaces:**
- Consumes: `accounts` (Task 1).
- Produces: tabelas `clients` (unique account_id+cnpj), `invitations` (token_hash unique, expires_at), `audit_logs`.

- [ ] **Step 1: Escrever o teste que falha**

```php
// tests/Feature/Accounts/SchemaTest.php
use App\Models\Account;
use App\Models\Client;

it('allows the same cnpj in different accounts', function () {
    $a = Account::factory()->create(['profile' => 'A']);
    $b = Account::factory()->create(['profile' => 'B']);

    Client::factory()->create(['account_id' => $a->id, 'cnpj' => '11222333000181']);
    Client::factory()->create(['account_id' => $b->id, 'cnpj' => '11222333000181']);

    expect(Client::where('cnpj', '11222333000181')->count())->toBe(2);
});
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test --filter="allows the same cnpj in different accounts"`
Expected: FAIL (tabela/model inexistentes).

- [ ] **Step 3: Criar migrations e models mínimos**

Clients: `account_id FK`, `cnpj(14)`, `razao_social`, `regime`, `contador_responsavel`, unique(`account_id`,`cnpj`). Invitations: `account_id FK`, `name`, `email`, `role`, `token_hash unique`, `expires_at`, `accepted_at nullable`. Audit_logs: `actor_user_id FK users`, `origin_account_id FK accounts`, `target_account_id nullable FK accounts`, `action`, `metadata JSON`, `created_at` (sem updated_at).

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test --filter="allows the same cnpj in different accounts"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_09_12_00000[456]*.php app/Models/Client.php app/Models/Invitation.php app/Models/AuditLog.php database/factories/*.php tests/Feature/Accounts/SchemaTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(accounts): tabelas clients invitations audit_logs"
```

---

### Task 4 — Global scope + contexto de Account (cobre tasks 2.1, 2.2)

**Files:**
- Create: `app/Concerns/BelongsToAccount.php`
- Create: `app/Support/CurrentAccount.php`
- Create: `app/Http/Middleware/ResolveAccountContext.php`
- Modify: `bootstrap/app.php`, `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Feature/Accounts/AccountIsolationTest.php`

**Interfaces:**
- Consumes: models com `account_id` (Tasks 1-3).
- Produces: `CurrentAccount::resolve()` retorna a Account efetiva; props Inertia `currentAccount`, `isSwitching`, `permissions`.

- [ ] **Step 1: Escrever o teste que falha**

```php
// tests/Feature/Accounts/AccountIsolationTest.php
it('hides other accounts data via global scope', function () {
    $a = Account::factory()->create(['profile' => 'A']);
    $b = Account::factory()->create(['profile' => 'B']);
    Client::factory()->create(['account_id' => $a->id]);
    Client::factory()->create(['account_id' => $b->id]);

    CurrentAccount::set($b);

    expect(Client::count())->toBe(1)
        ->and(Client::first()->account_id)->toBe($b->id);
});
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test --filter="hides other accounts data"`
Expected: FAIL (`CurrentAccount`/scope inexistentes).

- [ ] **Step 3: Implementar trait + suporte + middleware mínimos**

```php
// app/Concerns/BelongsToAccount.php
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope('account', function (Builder $builder) {
            if ($account = CurrentAccount::resolve()) {
                $builder->where($builder->getModel()->getTable().'.account_id', $account->id);
            }
        });

        static::creating(function (Model $model) {
            if (! $model->account_id && ($account = CurrentAccount::resolve())) {
                $model->account_id = $account->id;
            }
        });
    }
}
```

`CurrentAccount` guarda a Account em instância da app (`app()->instance`). Middleware: se `session('switch_account_id')` e user é super_admin, usa a alvo; senão, usa `user->account`. Compartilha no Inertia.

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test --filter="hides other accounts data"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Concerns/BelongsToAccount.php app/Support/CurrentAccount.php app/Http/Middleware/ResolveAccountContext.php bootstrap/app.php app/Http/Middleware/HandleInertiaRequests.php tests/Feature/Accounts/AccountIsolationTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(accounts): isolamento por account com contexto"
```

---

### Task 5 — Gates/Policies por papel (cobre tasks 2.3)

**Files:**
- Create: `app/Policies/AccountPolicy.php`, `ClientPolicy.php`, `UserPolicy.php`, `PlanPolicy.php` (ou Gates em `AppServiceProvider`)
- Test: `tests/Feature/Roles/RolePermissionsTest.php`

**Interfaces:**
- Consumes: `User->role`, contexto (Task 4).
- Produces: `manage-users`, `manage-clients`, `manage-platform`, `operate` verificáveis via `Gate::allows()` e policies.

- [ ] **Step 1: Escrever o teste que falha**

```php
// tests/Feature/Roles/RolePermissionsTest.php
it('denies operator from managing users but allows operating clients', function () {
    $account = Account::factory()->create(['profile' => 'B']);
    $operator = User::factory()->create(['account_id' => $account->id, 'role' => 'operador']);

    expect($operator->can('manage-users', $account))->toBeFalse()
        ->and($operator->can('operate-clients', $account))->toBeTrue();
});
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `php artisan test --filter="denies operator from managing users"`
Expected: FAIL (abilities inexistentes).

- [ ] **Step 3: Implementar Gates mínimos**

```php
// AppServiceProvider::boot()
Gate::define('manage-users', fn (User $u, Account $a) => $u->account_id === $a->id && in_array($u->role, ['super_admin', 'admin']));
Gate::define('operate-clients', fn (User $u, Account $a) => $u->account_id === $a->id && in_array($u->role, ['super_admin', 'admin', 'operador']));
Gate::define('manage-platform', fn (User $u) => $u->isSuperAdmin());
Gate::define('promote-super-admin', fn (User $u, User $t) => $u->isSuperAdmin() && $t->account->profile === 'A');
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `php artisan test --filter="denies operator from managing users"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php app/Policies/*.php tests/Feature/Roles/RolePermissionsTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(roles): gates por nivel de usuario"
```

---

### Task 6 — Onboarding + registro fechado (cobre tasks 3.1)

**Files:**
- Create: `app/Http/Controllers/OnboardingController.php`, `app/Http/Requests/OnboardingRequest.php`
- Modify: `config/fortify.php`, `app/Actions/Fortify/CreateNewUser.php`, `routes/web.php`
- Test: `tests/Feature/OnboardingTest.php`

**Interfaces:**
- Consumes: Account/Plan/User (Tasks 1-2).
- Produces: `GET /onboarding` (só base vazia), `POST /onboarding` cria A + super_admin; `/register` bloqueado com base populada.

- [ ] **Step 1: Escrever os testes que falham**

```php
// tests/Feature/OnboardingTest.php
it('creates account A with super_admin on empty base', function () {
    $this->post('/onboarding', ['account_name' => 'Matriz', 'name' => 'Root', 'email' => 'root@x.com', 'password' => 'password123', 'password_confirmation' => 'password123'])
        ->assertRedirect('/dashboard');

    expect(Account::first()->profile)->toBe('A')
        ->and(User::first()->role)->toBe('super_admin');
});

it('blocks public registration once accounts exist', function () {
    Account::factory()->create(['profile' => 'A']);

    $this->get('/register')->assertForbidden();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="OnboardingTest"`
Expected: FAIL (rotas/controller inexistentes).

- [ ] **Step 3: Implementar o mínimo**

Remover `Features::registration()` do fortify; `CreateNewUser` aborta 403 se `Account::exists()`; controller transacional cria Account A (plan default) + user super_admin e autentica; rota `/register` GET/POST retorna 403 com mensagem quando há Accounts.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter="OnboardingTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/OnboardingController.php app/Http/Requests/OnboardingRequest.php config/fortify.php app/Actions/Fortify/CreateNewUser.php routes/web.php tests/Feature/OnboardingTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(onboarding): primeira account A com super_admin"
```

---

### Task 7 — Convites + criação de Account pela A (cobre tasks 3.2)

**Files:**
- Create: `app/Services/InvitationService.php`, `app/Services/AccountProvisioningService.php`, `app/Http/Controllers/InvitationController.php`, `app/Http/Controllers/AccountController.php`
- Test: `tests/Feature/InvitationsTest.php`, `tests/Feature/Accounts/CreateAccountTest.php`

**Interfaces:**
- Consumes: Gates `manage-users`/`manage-platform` (Task 5).
- Produces: `InvitationService::invite()` / `::accept(token, password, confirmation)`; `AccountProvisioningService::createForAdmin()`.

- [ ] **Step 1: Escrever os testes que falham**

```php
it('accepts a valid invitation creating the user', function () {
    $inv = Invitation::factory()->create(['expires_at' => now()->addDays(7)]);

    app(InvitationService::class)->accept($inv->token, 'secret123', 'secret123');

    expect(User::where('email', $inv->email)->exists())->toBeTrue()
        ->and($inv->fresh()->accepted_at)->not->toBeNull();
});

it('rejects expired invitations', function () {
    $inv = Invitation::factory()->create(['expires_at' => now()->subDay()]);

    expect(fn () => app(InvitationService::class)->accept($inv->token, 'secret123', 'secret123'))
        ->toThrow(ValidationException::class);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="invitation"`
Expected: FAIL (services inexistentes).

- [ ] **Step 3: Implementar services mínimos**

Token aleatório 32 bytes, hash sha256 no banco, token puro só no link; expiração `expires_at = now()+7d`; aceite em transação (cria user com role/account do convite, marca accepted_at, autentica). Provisioning: cria Account B (plan default) + convite admin, tudo em transação, Gate `manage-platform`.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter="invitation|CreateAccountTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/InvitationService.php app/Services/AccountProvisioningService.php app/Http/Controllers/*.php tests/Feature/InvitationsTest.php tests/Feature/Accounts/CreateAccountTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(accounts): convites e criacao de account pela A"
```

---

### Task 8 — Páginas de onboarding e convite (cobre tasks 3.3)

**Files:**
- Create: `resources/js/pages/Onboarding.vue`, `resources/js/pages/invitations/Accept.vue`
- Test: build + smoke (sem teste automatizado de UI nesta fase).

**Interfaces:**
- Consumes: rotas Wayfinder `onboarding.*`, `invitations.*` (Task 6-7).
- Produces: telas com Nuxt UI (`UCard`, `UForm`, `UInput`, `UButton`, `UAlert`).

- [ ] **Step 1: Criar as páginas com `<UApp>` e formulário**

Formulário de onboarding (account_name, name, email, password) e aceite (password + confirmação), erros vindos do backend via `useForm` do Inertia, link de expiração tratado com `UAlert`.

- [ ] **Step 2: Rodar build e typecheck**

Run: `npm run build` e `npm run types:check`
Expected: ambos PASS, sem erros de tipo.

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/Onboarding.vue resources/js/pages/invitations/Accept.vue openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(onboarding): telas de onboarding e aceite"
```

---

### Task 9 — Checagem de limites do Plan (cobre tasks 4.1)

**Files:**
- Create: `app/Services/PlanLimitService.php`
- Test: `tests/Feature/Plans/PlanLimitsTest.php`

**Interfaces:**
- Consumes: `Account->plan` (Tasks 1-2).
- Produces: `assertCanCreateUser()`, `assertCanCreateClient()`, `assertModuleAllowed()`, `assertQueryVolume()` lançando `ValidationException` com mensagem de upgrade.

- [ ] **Step 1: Escrever o teste que falha**

```php
it('blocks user creation beyond plan limit', function () {
    $plan = Plan::factory()->create(['max_users' => 1]);
    $account = Account::factory()->create(['plan_id' => $plan->id]);
    User::factory()->create(['account_id' => $account->id]);

    expect(fn () => app(PlanLimitService::class)->for($account)->assertCanCreateUser())
        ->toThrow(ValidationException::class, 'limite');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="blocks user creation beyond plan limit"`
Expected: FAIL (service inexistente).

- [ ] **Step 3: Implementar o service mínimo**

Conta `users`/`clients` da Account vs `max_*`; módulos via `in_array` no JSON; volume via `audit_logs` de consultas no mês. Integrar nos controllers (invites, clients) antes de persistir.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter="PlanLimitsTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PlanLimitService.php tests/Feature/Plans/PlanLimitsTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(plans): checagem de limites no backend"
```

---

### Task 10 — Gestão de Plans pela A (cobre tasks 4.2, 4.3)

**Files:**
- Create: `app/Http/Controllers/PlanController.php`, `resources/js/pages/admin/Plans/Index.vue`, `Edit.vue`
- Test: `tests/Feature/Plans/ManagePlansTest.php`

**Interfaces:**
- Consumes: `manage-platform` (Task 5), limites (Task 9).
- Produces: CRUD de Plans + troca de Plan por Account, só super_admin; `UAlert` de limite nas telas.

- [ ] **Step 1: Escrever o teste que falha**

```php
it('denies plan change to non super_admin', function () {
    $b = Account::factory()->create(['profile' => 'B']);
    $admin = User::factory()->create(['account_id' => $b->id, 'role' => 'admin']);

    $this->actingAs($admin)->put("/admin/accounts/{$b->id}/plan", ['plan_id' => 1])
        ->assertForbidden();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="denies plan change to non super_admin"`
Expected: FAIL (rota/controller inexistentes).

- [ ] **Step 3: Implementar controller + telas mínimas**

CRUD com Form Requests, Gate `manage-platform`, troca imediata de limites; telas Nuxt UI (`UTable`, `UForm`, `USelect` de Plans); aviso de limite com ação "Solicitar upgrade".

- [ ] **Step 4: Rodar testes + build**

Run: `php artisan test --filter="ManagePlansTest"`, `npm run build`, `npm run types:check`
Expected: todos PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PlanController.php resources/js/pages/admin/Plans/*.vue tests/Feature/Plans/ManagePlansTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(plans): gestao de catalogo e troca pela A"
```

---

### Task 11 — CRUD de Clients (cobre tasks 5.1, 5.3)

**Files:**
- Create: `app/Http/Controllers/ClientController.php`, `app/Http/Requests/StoreClientRequest.php`, `UpdateClientRequest.php`, páginas `resources/js/pages/clients/*.vue`
- Test: `tests/Feature/Clients/ClientCrudTest.php`

**Interfaces:**
- Consumes: scope por Account (Task 4), Gates (Task 5), limites (Task 9).
- Produces: CRUD validando CNPJ/razao/regime/contador; isolamento garantido.

- [ ] **Step 1: Escrever os testes que falham**

```php
it('rejects incomplete client data', function () {
    $this->actingAs($this->admin())->post('/clients', ['cnpj' => '11222333000181'])
        ->assertSessionHasErrors(['razao_social', 'regime', 'contador_responsavel']);
});

it('isolates clients per account', function () {
    // client da account A invisível para user da B
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="ClientCrudTest"`
Expected: FAIL.

- [ ] **Step 3: Implementar controller + requests + telas**

Validação: CNPJ 14 dígitos, unique por account; telas Nuxt UI (`UTable`, `UForm`, `UInput`, `USelect` de regime); limite `assertCanCreateClient()` antes de persistir.

- [ ] **Step 4: Rodar testes + build**

Run: `php artisan test --filter="ClientCrudTest"`, `npm run build`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ClientController.php app/Http/Requests/*Client*.php resources/js/pages/clients/*.vue tests/Feature/Clients/ClientCrudTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(clients): carteira isolada por account"
```

---

### Task 12 — Monitoramento agendado (cobre tasks 5.2)

**Files:**
- Create: `app/Jobs/RunClientMonitoring.php`, `app/Services/MonitoringService.php` (contrato, sem SERPRO real)
- Test: `tests/Feature/Clients/MonitoringTest.php`

**Interfaces:**
- Consumes: Clients (Task 11), volume do Plan (Task 9).
- Produces: job por Client registra consulta em `audit_logs`/histórico; suspende com volume esgotado.

- [ ] **Step 1: Escrever o teste que falha**

```php
it('suspends monitoring when plan volume is exhausted', function () {
    // plan com volume 0 → dispatch do job → nenhuma consulta registrada + aviso
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="suspends monitoring when plan volume"`
Expected: FAIL.

- [ ] **Step 3: Implementar job + service mínimos**

Service com método `check(Client)` retornando stub estruturado (integração real em change futura); job verifica `assertQueryVolume()` antes, registra resultado, conta no volume.

- [ ] **Step 4: Rodar e ver passar**

Run: `php artisan test --filter="MonitoringTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/RunClientMonitoring.php app/Services/MonitoringService.php tests/Feature/Clients/MonitoringTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(clients): monitoramento agendado com volume"
```

---

### Task 13 — Seletor de Accounts (cobre tasks 6.1, 6.3 parcial)

**Files:**
- Create: `app/Services/AccountSwitcher.php`, `app/Http/Controllers/AccountSwitchController.php`, `resources/js/components/AccountSwitcher.vue`
- Modify: `app/Http/Middleware/ResolveAccountContext.php` (lê sessão), `resources/js/layouts/AppLayout.vue` (banner)
- Test: `tests/Feature/AccountSwitchTest.php`

**Interfaces:**
- Consumes: contexto (Task 4), `manage-platform` (Task 5).
- Produces: `POST /admin/switch/{account}` (enter), `DELETE /admin/switch` (exit); banner "Atuando como {account} — sair".

- [ ] **Step 1: Escrever os testes que falham**

```php
it('denies switch to non super_admin', function () {
    $this->actingAs($this->bAdmin())->post("/admin/switch/{$this->other()->id}")
        ->assertForbidden();
});

it('returns to origin on exit', function () {
    // enter como super_admin → exit → contexto volta à A
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="AccountSwitchTest"`
Expected: FAIL.

- [ ] **Step 3: Implementar service + controller + banner**

Enter valida super_admin + alvo existente, grava `switch_account_id`, audita; exit limpa e audita; componente Vue com `USelect` de Accounts + banner `UAlert` persistente.

- [ ] **Step 4: Rodar testes + build**

Run: `php artisan test --filter="AccountSwitchTest"`, `npm run build`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AccountSwitcher.php app/Http/Controllers/AccountSwitchController.php resources/js/components/AccountSwitcher.vue resources/js/layouts/AppLayout.vue tests/Feature/AccountSwitchTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(switcher): seletor de accounts do super_admin"
```

---

### Task 14 — Auditoria (cobre tasks 6.2, 6.3 parcial)

**Files:**
- Create: `app/Observers/AuditObserver.php`, `app/Http/Controllers/AuditLogController.php`, `resources/js/pages/admin/Audit/Index.vue`
- Test: `tests/Feature/AuditTest.php`

**Interfaces:**
- Consumes: `audit_logs` (Task 3), contexto com origem/alvo (Task 4, 13).
- Produces: registros em criar Account, trocar Plan, convites, switch enter/exit; listagem com filtros account/ator.

- [ ] **Step 1: Escrever o teste que falha**

```php
it('logs platform and operation events with actor and accounts', function () {
    // cria account + convida user → audit_logs tem 2+ registros com actor/origin/action
    expect(AuditLog::where('action', 'account.created')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php artisan test --filter="logs platform and operation events"`
Expected: FAIL.

- [ ] **Step 3: Implementar observer + controller mínimos**

Observer com try/catch (nunca quebra a escrita); helper `Audit::record(action, target?, meta?)`; controller com filtros `?account_id&actor_id`, Gate: super_admin vê tudo, admin só a própria; tela `UTable` + filtros `USelect`/`UInput`.

- [ ] **Step 4: Rodar testes + build**

Run: `php artisan test --filter="AuditTest"`, `npm run build`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Observers/AuditObserver.php app/Http/Controllers/AuditLogController.php resources/js/pages/admin/Audit/Index.vue tests/Feature/AuditTest.php openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(audit): log unico com filtros"
```

---

### Task 15 — Gestão de Accounts pela A (telas restantes de tasks 3.3/6.3)

**Files:**
- Create: `resources/js/pages/admin/Accounts/Index.vue`, `Create.vue`
- Test: build + smoke (cobertura backend já em Task 7).

**Interfaces:**
- Consumes: `AccountProvisioningService`, rotas `admin.accounts.*` (Task 7).
- Produces: listagem (`UTable` com perfil/plan) + criação com admin inicial (`UForm`).

- [ ] **Step 1: Criar as telas**

Index com colunas nome/perfil/plan/ações (ver, trocar plan, entrar via seletor); Create com campos da Account + nome/e-mail do admin inicial.

- [ ] **Step 2: Rodar build e typecheck**

Run: `npm run build`, `npm run types:check`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/admin/Accounts/*.vue openspec/changes/multi-account-a-b-planos/tasks.md
git commit -m "feat(accounts): telas de gestao pela A"
```

---

### Task 16 — Validação final (cobre tasks 7.1, 7.2)

**Files:** nenhum (verificação).

- [ ] **Step 1: Rodar o gate completo**

Run: `composer test`, `npm run types:check`, `npm run build`, `npm run check`
Expected: tudo verde; corrigir o que falhar antes de prosseguir.

- [ ] **Step 2: Smoke fim a fim**

Onboarding → criar B → aceitar convite admin → criar Client → trocar Plan → seletor enter/exit → auditoria com filtros. Registrar evidências e corrigir desvios.

- [ ] **Step 3: Commit de fechamento (se houver fix)**

```bash
git add -A
git commit -m "test(accounts): validacao fim a fim multi-account"
```
