## Context

See `proposal.md` for motivation and the three delta specifications for the behavior contracts. The current application already has Account context middleware, gates, observers, a monitoring command, Inertia administration components, and CI workflows, but the reviewed paths disagree at their boundaries: selected Account authorization still compares the actor's home Account, bulk mutations bypass observers, notification mappings use different event names, and monitoring performs synchronous best-effort loops.

The implementation must preserve the Laravel 13, Inertia, Vue 3, Nuxt UI, PostgreSQL, Pest, PHPStan, and Wayfinder stack. The working tree also contains active changes from the existing OpenSpec changes, so remediation will be delivered in small reviewed batches against their current state.

## Goals / Non-Goals

**Goals:**

- Establish a single Account-context contract used by authorization, queries, quotas, audit, and user-facing navigation.
- Put domain invariants behind transactional application services and database constraints where PostgreSQL can enforce them.
- Make audit and notification event naming explicit and shared.
- Turn monitoring into a schedulable orchestration boundary with isolated, retryable Client work and observable outcomes.
- Make frontend state derive from server-owned queries and truthful domain data.
- Restore one reproducible verification gate across local development and CI.

**Non-Goals:**

- Design or implement provider-specific fiscal ingestion, certificate handling, Documento Fiscal persistence, or DANFE generation.
- Add speculative revenue or sales models solely to populate the Dashboard.
- Replace the existing Account A/B model or introduce a new tenancy package.

## Decisions

### Use an explicit operation context for cross-Account authorization

Authorization will distinguish the authenticated actor's home Account from the selected target Account. Gates and policies will receive the target through the resolved request context, and platform-only actions will additionally require `super_admin` membership in Account A. Audit metadata will capture both Account identifiers.

This keeps cross-Account behavior centralized and testable. The alternative of temporarily rewriting the authenticated user's `account_id` was rejected because it hides actor provenance and can leak the selected context into unrelated authentication or persistence behavior. Duplicating special-case checks across controllers was rejected because the current mismatch originated at that boundary.

### Route administrative mutations through transactional actions

Account B provisioning, `super_admin` promotion, bootstrap onboarding, default Plan changes, and quota-bound creation will use narrow transactional actions. Each action will lock the smallest shared row or advisory key needed for its invariant and rely on database uniqueness or check constraints as the final guard. HTTP controllers will delegate validated input from Form Requests.

The database guard is required because request-time counts and existence checks cannot serialize concurrent requests. Application-only mutexes were rejected because they do not protect other processes or direct writes. Broad table locks were rejected because they would reduce concurrency beyond the contested Account or Plan scope.

### Define canonical audit events and dispatch after committed mutations

A single catalog will name auditable events consumed by observers, explicit actions, notification preferences, and presentation. Bulk operations will load authorized records and delete them through the same auditable path as individual operations. Events that trigger external or queued side effects will be dispatched only after the transaction commits.

Keeping the current parallel name sets was rejected because configuration can silently miss events. Logging one summary entry for a bulk deletion was rejected because it loses per-entity traceability.

### Split monitoring orchestration from per-Client execution

The hourly scheduler will dispatch one job for each eligible Client. A deterministic key derived from Client and monitoring window will provide overlap protection and idempotency, while job retry/backoff and terminal failure handling will persist status and emit safe operational evidence. The console command will orchestrate dispatch and return failure when its requested scope cannot be scheduled.

The current synchronous loop was rejected because one process owns the whole portfolio and swallowed failures cannot be retried or alerted independently. A single queued portfolio job was rejected because it would retain the same failure domain.

### Treat server queries as the source for Client discovery

Client index requests will validate search, filter, sorting, and pagination input, scope the query to the resolved Account, and return query parameters with paginated results. The Vue page will bind controls to those parameters and navigate through generated Wayfinder routes.

Filtering only the hydrated page was rejected because it produces false negatives. Loading every Client to the browser was rejected because it scales poorly and widens the amount of tenant data in shared page props.

### Remove unsupported Dashboard claims and build the public page from product primitives

Dashboard cards will use available persisted counts and operational states. Unsupported financial cards will become explicit unavailable states or be removed; sample data, if retained for a deliberate demonstration mode, will carry an unavoidable label. The public entry will use the existing component and theme system with OneFisc copy and supported authentication routes.

Inventing a financial persistence model in this remediation was rejected because it would expand domain scope without requirements. Keeping deterministic pseudo-values was rejected because repeatability does not make them real Account data.

### Make the repository gate explicit and bounded

Static analysis will receive a repository-owned memory limit appropriate to the current codebase. CI will use lockfile-respecting installs and separate, named checks for PHP, frontend, dependency advisories, and PostgreSQL behavior. SQLite remains useful for fast tests, while PostgreSQL verification covers production-specific constraints and locking.

Relying on developers to remember command flags was rejected because the official `composer test` command must work as documented. Replacing all fast tests with PostgreSQL was rejected because it would unnecessarily slow feedback.

## Risks / Trade-offs

- [Database constraints reveal existing invalid rows during migration] → Add preflight queries and a deterministic data repair migration before enabling each constraint.
- [Locks increase latency under bursts at a Plan boundary] → Lock only the owning Account or Plan record, keep transactions short, and cover concurrent behavior with PostgreSQL tests.
- [Canonical event renaming breaks existing notification preferences] → Migrate stored preference keys and support a bounded compatibility mapping while the migration deploys.
- [Hourly per-Client jobs increase queue volume] → Dispatch only eligible Clients, use unique window keys, expose queue health, and tune workers from measured throughput.
- [Provider retries create duplicate side effects] → Persist idempotency keys before downstream state transitions and make handlers safe to repeat.
- [Replacing Dashboard cards changes the perceived product surface] → Preserve layout hierarchy with honest operational metrics and clear empty states, then validate responsive behavior visually.
- [PostgreSQL CI adds runtime and service variability] → Keep a focused PostgreSQL suite for constraints and concurrency while the main isolated suite remains fast.
- [Parallel edits conflict in the shared dirty worktree] → Apply implementation batches sequentially with fresh subagents, narrow ownership, per-batch review, and verification before the next handoff.

## Migration Plan

1. Add regression tests for authorization, event naming, monitoring outcomes, queries, and existing concurrency gaps before changing behavior.
2. Introduce validation and transactional domain actions, then add compatible database constraints after repairing any invalid data.
3. Migrate event preference keys and deploy canonical event production and consumption together.
4. Deploy monitoring jobs and status persistence with workers ready, then change the schedule from daily orchestration to hourly dispatch.
5. Deploy the administrative and product-facing pages after their routes and authorization contracts are active.
6. Apply the pending notification-preference and remediation migrations before enabling the associated UI.
7. Enable the bounded local/CI gates and PostgreSQL job after the implementation passes both database paths.

Rollback will disable hourly dispatch first, stop new remediation jobs, and revert application batches in reverse order. Additive schema and migrated event data will remain readable during rollback; destructive cleanup is deferred until the compatibility window closes.
