# Repository Guidelines

## Project Structure & Module Organization

- `app/` holds backend code: `Http/Controllers`, `Http/Requests`, `Models`, `Actions`, `Console`, `Providers`.
- `routes/` defines endpoints: `web.php`, `settings.php`, `console.php`.
- `resources/js/` holds the Vue 3 + Inertia frontend: `pages/`, `components/`, `layouts/`, `composables/`, `lib/`, `actions/`, `types/`, `wayfinder/` (generated routes, do not edit).
- `resources/views/app.blade.php` is the Inertia root template; `resources/css/` has styles.
- `database/` holds `migrations/`, `factories/`, `seeders/`; `tests/Feature` and `tests/Unit` mirror Pest suites.
- `openspec/` holds specs and change proposals (`changes/`, `specs/`).

## Build, Test, and Development Commands

- `composer dev` starts the full local stack (server, queue, logs, Vite).
- `npm run dev` runs only the Vite frontend; `npm run build` produces a production frontend build.
- `composer test` runs the full gate: config clear, Pint check, PHPStan, Pest suite.
- `php artisan test` runs Pest only; `php artisan test --filter=Name` runs one test.
- `npm run types:check` runs `vue-tsc`; `npm run check` runs frontend lint/format.
- `composer lint` fixes PHP style with Pint; `composer lint:check` only verifies.

## Coding Style & Naming Conventions

- PHP: 4 spaces, Laravel preset via Pint (`pint.json`); run `composer lint` before pushing.
- Frontend: Tailwind CSS v4, Nuxt UI + Reka UI components, `clsx` + `tailwind-merge` for classes.
- Routes use Laravel Wayfinder: call generated helpers from `resources/js/wayfinder`, never hardcode URLs.
- Naming: PHP classes `StudlyCase`, methods `camelCase`; Vue files `PascalCase.vue`, composables `useX`, pages map to routes.

## Testing Guidelines

- Framework: Pest with the Laravel plugin; DB is in-memory SQLite (`phpunit.xml`).
- Put HTTP and behavior tests in `tests/Feature`, pure logic in `tests/Unit`; name files `*Test.php`.
- Use factories over manual model creation; keep tests isolated (array cache, sync queue).
- Type safety: PHPStan level 7 (`phpstan.neon`) must pass; `vue-tsc` must pass for frontend changes.

## Spec-Driven Workflow (OpenSpec + Superpowers)

- OpenSpec owns WHAT + WHY: `proposal.md`, `specs/`, `design.md`, `tasks.md`, plus the Delta/Archive lifecycle (`openspec-propose`, `openspec-apply-change`, `openspec-archive-change`). It is the only planning system; do not run Superpowers `brainstorming` or `writing-plans` as a parallel planner.
- Superpowers owns HOW WELL: `brainstorming` (only to discover fuzzy requirements before proposing), `test-driven-development` (red/green/refactor on every implementation task), `systematic-debugging` (on any failure), `requesting-code-review` (before each commit), `verification-before-completion` (before claiming done).
- Routing: fuzzy requirements → `brainstorming` first, output to `openspec/changes/<name>/brainstorm.md`, then `openspec-propose`. Settled requirements → `openspec-propose` directly. Applying → TDD + review per task batch with `tasks.md` as the scope contract. Finished → `openspec-archive-change` always last; never leave a change un-archived.
- Homes (all inside `openspec/changes/<name>/`): `brainstorm.md` (pre-propose discovery), `proposal.md` + `specs/` + `design.md` + `tasks.md` (official plan), `plan.md` (execution detail via `writing-plans`, only when needed, referencing task IDs). No planning docs outside the change folder; the spec library lives only in `openspec/specs/` after archive.
- Every mistake becomes structure: lift new gotchas into `openspec/config.yaml` rules, and crystallized terms/decisions into `CONTEXT.md` / `docs/adr/`.

## Commit & Pull Request Guidelines

- Commits follow conventional commits with scope: `feat(inbox): ...`, `fix(whats): ...`, `docs(specs): ...`, `chore(repo): ...`.
- Keep commits focused; spec/design sync commits use `docs(specs):`.
- PRs need a clear description of what changed and why, linked issues or change IDs (`openspec/changes/<name>`), and test evidence (`composer test` output). Add screenshots for UI changes.

## Security & Configuration Tips

- Never commit `.env`; copy `.env.example` for local setup. Secrets stay in environment config.
- Validate all input with Form Requests; keep authorization in policies or request classes.
- Redact provider payloads in logs; never log tokens, message bodies, or PII verbatim.
