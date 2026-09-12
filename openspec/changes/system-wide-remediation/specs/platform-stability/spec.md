## Purpose

Define the security and data-integrity guarantees that keep Account administration, context switching, onboarding, and Plan limits correct under normal and concurrent use.

## ADDED Requirements

### Requirement: Super admin operates in the selected Account
The system SHALL authorize a `super_admin` from Account A to perform permitted administrative and Client operations in the Account selected through the Account Selector, while preserving the actor's origin and selected target.

#### Scenario: Super admin manages a Client in Account B
- **WHEN** a `super_admin` from Account A selects Account B and performs a permitted Client operation
- **THEN** the system authorizes the operation against Account B and records Account A as the actor's origin and Account B as the target

#### Scenario: Non-super-admin attempts to select another Account
- **WHEN** a user without the `super_admin` role attempts to operate in an Account other than their own
- **THEN** the system denies the context switch and does not expose or modify data from the requested Account

### Requirement: Account B can be provisioned through supported product flows
The system SHALL provide authenticated administrative flows to list Accounts, create Account B with an initial Plan, and invite its initial administrator.

#### Scenario: Super admin creates Account B
- **WHEN** an authorized `super_admin` submits valid Account B and initial administrator data
- **THEN** the system creates the Account with its selected Plan, issues the administrator invitation, and presents the resulting Account in the administration interface

#### Scenario: Unauthorized user attempts Account provisioning
- **WHEN** a user without platform administration permission attempts to list or create Accounts
- **THEN** the system denies the request without revealing platform-wide Account data

### Requirement: Super-admin promotion is restricted to Account A
The system SHALL allow an existing user to be promoted to `super_admin` only through an authorized Account A administration flow.

#### Scenario: Account A promotes an eligible user
- **WHEN** an authorized `super_admin` operating in Account A promotes an eligible Account A user
- **THEN** the user receives the `super_admin` role and the change is auditable

#### Scenario: Promotion is attempted outside Account A
- **WHEN** a promotion to `super_admin` is requested while the target or selected Account is not Account A
- **THEN** the system rejects the promotion and preserves the user's current role

### Requirement: Onboarding creates a single Account A
The system MUST guarantee that only one Account A can be created, including when multiple onboarding submissions execute concurrently.

#### Scenario: Concurrent bootstrap submissions
- **WHEN** two valid initial onboarding requests race before Account A exists
- **THEN** exactly one request establishes Account A and the other receives a controlled conflict without creating duplicate platform roots

#### Scenario: Onboarding after Account A exists
- **WHEN** any user requests initial onboarding after Account A has been established
- **THEN** the system refuses to create another Account A

### Requirement: Domain classifications remain valid
The system MUST validate CNPJ values using the official numeric structure and check digits and MUST persist only supported Account profiles and user roles.

#### Scenario: Invalid CNPJ check digits
- **WHEN** Account data contains a fourteen-digit CNPJ with invalid check digits
- **THEN** the system rejects the data with a field-level validation error

#### Scenario: Unsupported profile or role
- **WHEN** a request or persistence attempt uses an Account profile or user role outside the supported domain values
- **THEN** the system rejects the value and leaves existing data unchanged

### Requirement: Default Plan and quotas are concurrency-safe
The system MUST maintain at most one default Plan and MUST enforce user, Client, and volume limits atomically across concurrent requests.

#### Scenario: Concurrent default Plan selection
- **WHEN** multiple requests attempt to assign different Plans as default at the same time
- **THEN** the committed state contains exactly one default Plan

#### Scenario: Concurrent creation at the quota boundary
- **WHEN** concurrent requests attempt to create resources with only one unit of quota remaining
- **THEN** no more than one request succeeds and the Account never exceeds its Plan limit

#### Scenario: Limit rejection
- **WHEN** an Account has reached a configured Plan limit
- **THEN** the system rejects the additional operation with an actionable error and creates no partial resource
