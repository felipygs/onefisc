## 1. Workflow Baseline

- [ ] 1.1 Capture the remediation baseline with focused failing Pest tests for selected-Account authorization, audit naming, bulk deletion, monitoring outcomes, server-side Client search, and domain invariants, and verify each test fails for its intended behavioral reason before implementation.

## 2. Account Context and Platform Administration

- [ ] 2.1 Centralize actor-origin and selected-target Account authorization for Client and administrative operations, and verify feature tests allow Account A `super_admin` access to Account B while denying cross-Account access to other roles.
- [ ] 2.2 Add validated transactional flows and routes for Account listing, Account B creation, Plan assignment, and initial administrator invitation, and verify authorized, invalid, duplicate, and forbidden feature scenarios.
- [ ] 2.3 Add the Account A-only `super_admin` promotion flow with complete audit attribution, and verify promotion succeeds only for an eligible Account A user under an authorized Account A context.
- [ ] 2.4 Build the reachable Inertia administration pages for Account provisioning and promotion using generated Wayfinder routes, and verify Vue types, frontend checks, authorization responses, and production build.

## 3. Domain Integrity and Concurrency

- [ ] 3.1 Move inline mutation validation into Form Requests and enforce official CNPJ check digits plus supported Account profiles and user roles, and verify field-level validation tests and database rejection of unsupported persisted values.
- [ ] 3.2 Make initial onboarding create exactly one Account A under concurrent submissions with a controlled conflict for losers, and verify the invariant in focused PostgreSQL concurrency tests.
- [ ] 3.3 Make default Plan selection transactional with a database-backed uniqueness invariant, repair conflicting existing rows deterministically, and verify concurrent PostgreSQL tests leave exactly one default Plan.
- [ ] 3.4 Enforce user, Client, and volume limits atomically at their transactional creation boundaries, and verify concurrent PostgreSQL tests never commit resources above each Plan limit.

## 4. Audit and Notifications

- [ ] 4.1 Introduce and migrate a canonical audit-event catalog shared by producers, notification preferences, and human-readable presentation, and verify every supported Client, invitation, Plan, Account, user, and monitoring event maps end to end.
- [ ] 4.2 Route bulk Client deletion through the authorized auditable deletion path with safe per-Client metadata, and verify one correctly attributed audit record is committed for every deleted Client.
- [ ] 4.3 Ensure cross-Account audit records include actor, origin Account, target Account, affected entity, and redacted metadata, and verify regression tests contain no credentials, tokens, message bodies, documents, or raw provider PII.

## 5. Monitoring Reliability

- [ ] 5.1 Add persisted monitoring execution state and deterministic Client/window idempotency keys with a compatible migration, and verify duplicate keys and state transitions on PostgreSQL.
- [ ] 5.2 Implement an independently retryable and non-overlapping monitoring job per eligible Client with safe terminal-failure logging and audit, and verify retries isolate one Client failure without duplicating records or events.
- [ ] 5.3 Change scheduled and manual monitoring to hourly per-Client dispatch with truthful counts and unsuccessful manual exit codes on dispatch failure, and verify scheduler inspection plus command and queue feature tests.

## 6. Product-Facing Integrity

- [ ] 6.1 Replace generated Dashboard revenue, sales, and variation values with persisted operational metrics or explicit unavailable/demo states, and verify controller and page tests never present synthetic values as current Account data.
- [ ] 6.2 Implement validated Account-scoped server-side Client search, filters, sorting, and pagination with preserved query parameters, and verify matches beyond the first page, navigation persistence, and tenant isolation.
- [ ] 6.3 Replace the Laravel starter page with a responsive OneFisc entry using supported authentication routes, and verify rendered product copy, navigation targets, frontend checks, and production build.
- [ ] 6.4 Add accessible names, focus behavior, keyboard operation, and narrow-viewport layouts to icon actions and Client administration controls, and verify automated accessibility checks plus desktop and mobile visual inspection.

## 7. Repository Gate and Integration

- [ ] 7.1 Configure the official PHPStan command with sufficient repository-owned memory and resolve every reported finding, and verify `composer test` completes successfully without manual flags.
- [ ] 7.2 Make the full frontend formatting, linting, type, and build gate pass for repository-owned files and add meaningful tests for interactive search and administration behavior, and verify `npm run check`, `npm run types:check`, the frontend test command, and `npm run build` all succeed.
- [ ] 7.3 Update continuous integration to install locked dependencies, run Composer and npm advisory checks, and execute the focused PostgreSQL migration/concurrency suite alongside the main gate, and verify the workflow syntax and equivalent local commands.
- [ ] 7.4 Apply all pending migrations in a production-like PostgreSQL environment, run the complete backend and frontend gates plus strict OpenSpec validation, and record the fresh outputs before marking the remediation ready to archive.
