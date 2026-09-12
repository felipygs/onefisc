## Purpose

Define the product-facing guarantees for truthful Dashboard information, portfolio-wide Client discovery, accessible interaction, and a coherent public entry point.

## ADDED Requirements

### Requirement: Dashboard data states are truthful
The Dashboard SHALL display only persisted domain data as real metrics and SHALL label any sample or demonstration content explicitly.

#### Scenario: No real financial metric exists
- **WHEN** the domain has no persisted source for a revenue, sales, or variation metric
- **THEN** the Dashboard presents an honest empty or unavailable state instead of a generated value

#### Scenario: Demonstration content is shown
- **WHEN** the product intentionally displays sample Dashboard content
- **THEN** the interface visibly identifies the content as demonstrative wherever a user could interpret it as current Account data

### Requirement: Public entry represents the product
The unauthenticated entry page SHALL identify OneFisc, explain the available product purpose, and provide clear paths to the supported authentication flows.

#### Scenario: Visitor opens the public root page
- **WHEN** an unauthenticated visitor opens the product root
- **THEN** the page displays OneFisc identity and relevant sign-in or onboarding actions without Laravel starter content

### Requirement: Client discovery covers the full permitted portfolio
Client search, filters, sorting, and pagination SHALL execute over all Clients visible in the selected Account and SHALL preserve the active query across navigation.

#### Scenario: Match exists outside the current page
- **WHEN** a user searches for a visible Client that is not present in the currently loaded page
- **THEN** the server returns the matching Client and updates pagination for the filtered result set

#### Scenario: User changes pages with active filters
- **WHEN** a user navigates through a filtered Client result set
- **THEN** the active search, filter, and sorting parameters remain applied

#### Scenario: User lacks access to another Account
- **WHEN** a user crafts search parameters that refer to Clients outside the selected permitted Account
- **THEN** the result set contains no inaccessible Client data

### Requirement: Interactive controls are accessible
Every interactive control SHALL have an accessible name, visible focus indication, keyboard operation, and a usable responsive layout at supported viewport sizes.

#### Scenario: Icon-only action receives focus
- **WHEN** a keyboard or assistive-technology user reaches an icon-only action
- **THEN** the control exposes a meaningful accessible name and a visible focus state

#### Scenario: Client management on a narrow viewport
- **WHEN** a user opens Client search, filters, table actions, or pagination on a narrow supported viewport
- **THEN** controls remain reachable, content does not require unintended page-level horizontal scrolling, and actions remain understandable

### Requirement: Administrative interfaces expose supported workflows
Authorized platform administrators SHALL be able to reach Account listing, Account B creation, initial administrator invitation, and eligible `super_admin` promotion through the product interface.

#### Scenario: Super admin navigates platform administration
- **WHEN** an authorized `super_admin` opens platform administration
- **THEN** the interface exposes the supported Account provisioning and promotion workflows with clear validation and completion feedback

#### Scenario: Unauthorized user navigates directly
- **WHEN** an unauthorized user requests an administrative page directly
- **THEN** access is denied and platform-wide data is not included in the response
