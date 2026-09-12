# SDD Plan — multi-account-a-b-planos (remaining work)

Authority: `openspec/changes/multi-account-a-b-planos/` — proposal.md (WHAT+WHY),
specs/ (accounts, roles, onboarding-invites, plans, clients, account-switcher,
audit), design.md (HOW), tasks.md (scope contract). The OpenSpec spec is the
binding authority; this plan is its execution argument. Conflicts resolve
against the spec.

Context: Laravel 13 (PHP 8.3), Vue 3 + Inertia.js v3, Tailwind CSS v4, Vite.
Tests: Pest (PHP), vue-tsc for types. Lint: Pint (PHP), `vp check` (JS/Vue).
Conventions: conventional commits with scope, Laravel Wayfinder for routes
(call generated helpers from `resources/js/wayfinder`, never hardcode URLs),
Nuxt UI components. Glossary: CONTEXT.md is canonical (Account, Plan, Client,
super_admin, Onboarding). Every Superpowers output lives inside the OpenSpec
change folder.

Baseline: branch `feat/multi-account-a-b-planos`, commit `39c20fd` (WIP:
OpenSpec tasks 1.1–3.2 implementation + 3.3 Onboarding/Accept pages + partial
backend scaffolding: PlanLimitService, MonitoringCheck model+migration,
MonitoringService, ClientRequest/ClientController, PlanController,
AccountController plan switch, AccountSwitcherController, AuditService,
AuditObserver, AuditController, RunMonitoringChecks command — mostly
unwired/unrouted/unverified). Prior OpenSpec tasks 1.1–3.2 are DONE per
`openspec/changes/multi-account-a-b-planos/tasks.md` (8/20). SDD executes the
12 remaining tasks below. Implementers must verify and complete whatever the
baseline left unfinished inside their task scope — do not assume baseline
code works.

## Global Constraints

- OpenSpec owns WHAT+WHY: do not change proposal/specs/design intent; if
  implementation reveals a design issue, surface it, do not silently
  narrow, defer, or simplify specified behavior.
- `tasks.md` in the OpenSpec change is the scope contract: one sentence per
  task with verification stated. Mark complete there (`- [ ]` → `- [x]`)
  only when specified behavior is fully implemented.
- Data isolation: every business entity carries `account_id`; global scope
  filters by effective account; cross-account access denied except via
  super_admin switcher. New entities must follow this.
- Roles: enum `users.role` super_admin/admin/operador/user; Gates/Policies
  enforce spec boundaries; super_admin only in Account A; only super_admin
  creates/promotes super_admin, creates Accounts, manages Plans, uses
  switcher.
- Registration: public `/register` closed once Accounts exist (403 +
  guidance); empty base → onboarding creates Account A + super_admin.
- Invites: name/email/role, 7-day expiry, sha256 token hash stored, password
  set on accept.
- Plans: catalog table with max_users, max_clients, modules JSON, volume,
  is_default; new Accounts get default basic plan; only A switches plans;
  enforcement in backend services, frontend only displays.
- Clients: per-Account wallet (CNPJ unique per account, repeatable across
  accounts), 4 fields required (CNPJ 14, razão social, regime
  simples/presumido/real/mei, contador), monitoring scheduled counting plan
  volume, suspension with upgrade notice when exhausted.
- Switcher: session `switch_account_id`, super_admin only, persistent banner
  "Atuando como {account}", explicit exit back to A, every enter/action/exit
  audited with actor/origin/target/action/moment.
- Audit: observer-based, best-effort (never throws, try/catch + log), single
  log for platform + operation, filterable by Account/actor.
- Frontend: Nuxt UI v4 Dashboard shell per proposal UI catalog; Wayfinder
  route helpers, never hardcoded URLs; `npm run build` +
  `npm run types:check` must pass for UI tasks.
- TDD: failing test first where behavior is new, then implementation; run
  focused tests while iterating, full relevant suite before committing.
- Commits: focused, conventional (`feat(...)`, `fix(...)`), no secrets.

## Task 1

Implement OpenSpec task 3.3: create Inertia pages for onboarding and
invitation accept with Nuxt UI, verified with `npm run build` + manual
smoke. Baseline already contains `resources/js/pages/Onboarding.vue`,
`resources/js/pages/invitations/Accept.vue`, `app.ts` layout mapping, and a
`Register.vue` wayfinder import fix. Verify, complete, fix, or rebuild
whatever is needed so that: `Onboarding.vue` uses UCard+UForm+UFormField+
UInput+UButton+UAlert and posts to the onboarding store route; `invitations/
Accept.vue` uses UCard+UForm+UAlert(expired)+UButton and posts to the
invitation accept store route with the token; both render under AuthLayout;
`InvitationAcceptPageTest` (3 tests) passes; `npm run build` passes.
Files: `resources/js/pages/Onboarding.vue`,
`resources/js/pages/invitations/Accept.vue`, `resources/js/app.ts`.
Verify: `php artisan test --filter=InvitationAcceptPageTest` and
`npm run build`.

## Task 2

Implement OpenSpec task 4.1: plan-limit checking services (users, clients,
modules, volume) enforced at creation/use, verified with block-on-exceed
tests. Baseline contains `app/Services/PlanLimitService.php` (unverified).
Complete so that: user capacity counts users + valid pending invites vs
`plan.max_users`; client capacity counts clients vs `plan.max_clients`;
module check verifies membership in `plan.modules`; volume check counts
current-month monitoring checks vs `plan.monthly_query_volume`; all throw
`ValidationException` with an upgrade message naming the limit; enforcement
is wired into `InvitationService::invite` AND `::accept` (users),
client creation path (clients), `MonitoringService::run` (module
`monitoring` + volume). Null plan (no plan) means no enforcement. Write
Pest tests covering each limit blocking when exceeded and passing when
within limits.
Files: `app/Services/PlanLimitService.php`,
`app/Services/InvitationService.php`, `app/Services/MonitoringService.php`,
`tests/Feature/Plans/*` (new or extend).
Verify: `php artisan test --filter=Plan` (plus new tests).

## Task 3

Implement OpenSpec task 4.2: plan switching only by A + plan catalog
management restricted to super_admin, verified with permission tests +
screen smoke. Baseline contains `app/Http/Controllers/PlanController.php`
and `AccountController::updatePlan` (unwired/unverified). Complete so that:
`manage-platform` gate guards all catalog CRUD and the account plan-switch
endpoint; admin/operador/user get 403 on every one; switching applies
immediately; new Accounts still receive the default basic plan; Inertia
pages `admin/Plans/Index.vue` (pricing display of the 3 plans, current
highlighted) and `admin/Plans/Edit.vue` (name/price/limits/modules/default
form) exist and build. Wire routes (Wayfinder-compatible controller
methods, no hardcoded URLs in Vue). Write Pest permission tests.
Files: `app/Http/Controllers/PlanController.php`,
`app/Http/Controllers/AccountController.php`, `routes/web.php`,
`resources/js/pages/admin/Plans/*`, tests.
Verify: `php artisan test` (permission tests) + `npm run build`.

## Task 4

Implement OpenSpec task 4.3: limit-exceeded warning with upgrade action on
affected screens, verified with `npm run build` + `npm run types:check`.
Build on Task 2's `PlanLimitService::usage()` (or equivalent): share usage
(object with users/clients/volume used/max/remaining + modules) to relevant
Inertia pages (at minimum clients index and plans index); render a Nuxt UI
`UAlert` (warning) with an upgrade action when any dimension is at/exceeded;
action routes toward plan management (visible only to those who can act,
informational otherwise). Keep backend enforcement untouched (display
only). No new enforcement logic.
Files: frontend pages + shared props (middleware or controller shares),
e.g. `HandleInertiaRequests` or per-controller props, plus a reusable
limit-warning component if warranted.
Verify: `npm run build` and `npm run types:check`.

## Task 5

Implement OpenSpec task 5.1: Client CRUD with 4-field validation and
per-Account isolation, verified with CRUD + isolation + repeated-CNPJ
tests. Baseline contains `ClientRequest`, `ClientController` (unwired/
unverified). Complete so that: validation requires CNPJ (string, 14),
razao_social, regime (in simples,presumido,real,mei), contador_responsavel,
all with field-pointed errors; CNPJ unique per account but repeatable
across accounts; all reads/writes scoped by global scope + controller
404-on-mismatch; `operate-clients` gate (super_admin/admin/operador
allowed, user denied, cross-account 403/404); plan client-limit enforced on
store. Wire full resource routes. Write Pest tests: CRUD happy path,
validation rejection per missing field, isolation (B cannot see A's
client), same CNPJ in two accounts coexists.
Files: `app/Http/Requests/ClientRequest.php`,
`app/Http/Controllers/ClientController.php`, `routes/web.php`, tests.
Verify: `php artisan test --filter=Client`.

## Task 6

Implement OpenSpec task 5.2: scheduled per-Client monitoring counting plan
volume, verified with suspension-on-exhaustion test. Baseline contains
`MonitoringCheck` model + migration + factory, `MonitoringService`, and
`RunMonitoringChecks` command (unwired/unverified). Complete so that: each
run creates one check row counting toward the account's current-month
volume; `monitoring` module lock and volume exhaustion both block with
upgrade messages; `monitoring:run` iterates all clients best-effort (one
client's failure never stops others) and reports ran/suspended counts; the
command is scheduled (daily) in `routes/console.php`. Migration runs clean
on fresh test DB. Write Pest tests: run within volume creates a row;
exhausted volume suspends (throws, no row); module-locked plan blocks.
Files: `app/Models/MonitoringCheck.php`, migration, factory,
`app/Services/MonitoringService.php`,
`app/Console/Commands/RunMonitoringChecks.php`, `routes/console.php`,
tests.
Verify: `php artisan test --filter=Monitor` (plus new tests) and
`php artisan monitoring:run --help` smoke.

## Task 7

Implement OpenSpec task 5.3: Inertia wallet pages with Nuxt UI, verified
with `npm run build` + manual smoke. Build on Task 5's controller (routes
must exist first; if missing, surface don't invent): `clients/Index.vue`
(toolbar with search+regime filter, table CNPJ/razão/regime badge/contador/
monitoring status, pagination, New button), `clients/Create.vue` +
`Edit.vue` (masked CNPJ input, razão, regime select, contador + submit),
`clients/Show.vue` (data card + tabs + timeline + volume-exhausted alert).
All under the Dashboard shell with Nuxt UI components. Use Wayfinder
helpers only.
Files: `resources/js/pages/clients/*`.
Verify: `npm run build` (+ smoke via existing client tests).

## Task 8

Implement OpenSpec task 6.1: Account switcher (session
`switch_account_id`, super_admin only, banner + explicit exit), verified
with role-denied + return-to-origin tests. Baseline contains
`AccountSwitcherController` and `ResolveAccountContext` support
(unwired/unverified). Complete so that: index/select/exit routes exist;
non-super_admin gets 403 on all three; select writes a valid target id to
session and subsequent requests resolve the effective account to the
target; exit clears the session and returns to A; every enter/exit writes
an audit record with actor/origin/target. Write Pest tests: admin denied,
super_admin select+effective-context, exit restores origin.
Files: `app/Http/Controllers/AccountSwitcherController.php`,
`routes/web.php`, `app/Http/Middleware/ResolveAccountContext.php` (only if
broken), tests.
Verify: `php artisan test --filter=Switch` (plus new tests).

## Task 9

Implement OpenSpec task 6.2: observer-based audit (actor, origin, target,
action, moment) covering platform + operation, verified with key-event
registration tests. Baseline contains `AuditService` + `AuditObserver`
(unwired/unverified). Complete so that: observers are registered (at
minimum Account, Plan, Client, Invitation, User, MonitoringCheck) and record
`<table>.created/updated/deleted` with target account resolution
(model.account_id, or self id for accounts); manual records exist for plan
switch, switcher enter/exit, invitation accept; the observer/service never
throws (try/catch + log); `audit_logs` rows carry actor_user_id,
origin_account_id, target_account_id, action, metadata, created_at. Write
Pest tests asserting rows for account creation, plan switch, client CRUD,
and switcher enter/exit.
Files: `app/Services/AuditService.php`,
`app/Observers/AuditObserver.php`,
`app/Providers/AppServiceProvider.php`, call sites, tests.
Verify: `php artisan test --filter=Audit` (plus new tests).

## Task 10

Implement OpenSpec task 6.3: switcher + audit query screens with
Account/actor filters, verified with `npm run build` + manual smoke. Build
on Tasks 8–9 (routes/controllers must exist; if missing, surface don't
invent): `admin/Switcher/Index.vue` (searchable account select + persistent
banner "Atuando como {account}" + exit button via `AccountSwitcher.vue`
component), `admin/Audit/Index.vue` (toolbar with Account select + actor
input + action filter, table moment/actor/origin/target/action badge/
details slideover, pagination). Empty/loading states with Nuxt UI. Use
Wayfinder helpers only.
Files: `resources/js/pages/admin/Switcher/Index.vue`,
`resources/js/components/AccountSwitcher.vue` (or equivalent),
`resources/js/pages/admin/Audit/Index.vue`.
Verify: `npm run build` (+ smoke via switcher/audit tests).

## Task 11

Implement OpenSpec task 7.1: run the full gate
(`php artisan test`, `npm run types:check`, `npm run build`, `npm run check`)
and fix failures. Scope is verification + minimal fixes only: no new
features, no refactors beyond what failures require. If a failure reveals a
spec/design defect, surface it in the report (do not redesign silently).
Files: whatever the failures require (expected small).
Verify: all four commands green; paste outputs in report.

## Task 12

Implement OpenSpec task 7.2: end-to-end smoke — onboarding → create B →
invite admin → wallet → switch plan → switcher → audit. Drive exclusively
through HTTP/service-level actions (Pest or artisan tinker-style script,
no manual browser): create Account A via onboarding endpoint, provision B,
accept its admin invite, create a client in B, switch B's plan as
super_admin, enter B via switcher, run monitoring, assert audit rows exist
for each step. Report the exact sequence + assertions + output. If any step
fails, report BLOCKED with the failing step (do not redesign).
Files: a smoke test or script (e.g. `tests/Feature/Smoke/MultiAccountSmokeTest.php`).
Verify: the smoke test passes.
