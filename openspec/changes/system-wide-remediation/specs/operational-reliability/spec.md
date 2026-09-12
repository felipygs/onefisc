## Purpose

Define reliable audit, notification, monitoring, deployment, and quality-gate behavior so operational failures are visible and privileged changes remain traceable.

## ADDED Requirements

### Requirement: Every material mutation is auditable
The system SHALL record each material Account, Plan, user, invitation, and Client mutation with its canonical event, actor, origin Account, target Account, affected entity, and safe metadata.

#### Scenario: Bulk Client deletion
- **WHEN** an authorized user deletes multiple Clients in one request
- **THEN** the system records an audit entry for every deleted Client and attributes each entry to the same actor and target Account

#### Scenario: Cross-Account mutation
- **WHEN** a `super_admin` from Account A changes data in a selected Account B
- **THEN** the audit record identifies the actor, Account A as origin, and Account B as target

#### Scenario: Sensitive provider data is processed
- **WHEN** an operation contains credentials, tokens, message bodies, documents, or personally identifiable provider payloads
- **THEN** audit and application logs omit or redact those sensitive values

### Requirement: Notifications consume canonical domain events
The system SHALL use one canonical event code per audited action and SHALL present supported notification events with human-readable product language.

#### Scenario: Supported event generates a notification
- **WHEN** a configured notification preference matches a canonical Client, invitation, Plan, or monitoring event
- **THEN** the user receives a notification with a readable title and description for that action

#### Scenario: Notification preferences are evaluated
- **WHEN** an auditable event occurs for an Account
- **THEN** notifications are sent only to eligible users whose preferences enable the canonical event

### Requirement: Fiscal monitoring is dispatched hourly per Client
The scheduler SHALL initiate monitoring every hour and SHALL isolate each eligible Client in an independently retryable execution.

#### Scenario: Hourly schedule is evaluated
- **WHEN** the scheduler reaches an hourly boundary
- **THEN** it dispatches one monitoring execution for each eligible Client without performing all provider work synchronously in the scheduler process

#### Scenario: One Client fails
- **WHEN** monitoring fails for one Client while other Client executions are valid
- **THEN** the failed execution follows its retry policy and the other Clients continue independently

### Requirement: Monitoring executions are idempotent and non-overlapping
The system MUST prevent duplicate active monitoring for the same Client and monitoring window and MUST avoid duplicate documents or events when an execution is retried.

#### Scenario: Duplicate dispatch for the same window
- **WHEN** the same Client and monitoring window are dispatched more than once
- **THEN** at most one active execution performs the work

#### Scenario: Retry receives previously processed provider data
- **WHEN** a retried execution encounters an item already processed for that Client
- **THEN** the system preserves one logical record and does not emit duplicate business events

### Requirement: Monitoring failures are visible
The system SHALL expose monitoring failures through persisted status, safe logs, and an auditable failure event, and manual execution SHALL return an unsuccessful outcome when requested work cannot be completed.

#### Scenario: Provider execution exhausts retries
- **WHEN** a Client monitoring execution fails after all configured attempts
- **THEN** the system records a visible failed status, emits a safe log and audit event, and retains enough non-sensitive context for diagnosis

#### Scenario: Manual monitoring cannot complete
- **WHEN** a manually invoked monitoring run cannot dispatch or complete its requested scope
- **THEN** the command reports the affected counts and exits unsuccessfully

### Requirement: Official quality gates are reproducible
The repository MUST provide deterministic backend, frontend, dependency, migration, and supported-database checks that can run locally and in continuous integration.

#### Scenario: Pull request quality gate
- **WHEN** continuous integration evaluates a pull request
- **THEN** it installs locked dependencies and verifies PHP formatting, static analysis, Pest, frontend formatting and linting, Vue types, production build, dependency advisories, and the supported PostgreSQL path
