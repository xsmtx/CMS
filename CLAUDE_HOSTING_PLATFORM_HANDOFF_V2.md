# HOSTING AUTOMATION PLATFORM --- CLAUDE CODE HANDOFF

> Working title: NexusHost. Goal: production-grade, modular, white-label
> hosting automation platform comparable in scope to WHMCS, with modern
> Laravel/MariaDB architecture and a separately deployable licensing
> control plane.

## 0. Claude Code rules

1.  Build production code, not a demo. Work milestone-by-milestone.
2.  Every feature requires validation, authorization, tests, audit
    behavior and docs.
3.  Provider-specific logic must be behind versioned contracts/adapters;
    never in controllers/models.
4.  Never store raw card data or log secrets/passwords/tokens/private
    keys.
5.  Financial records are append-oriented; do not silently mutate
    finalized history.
6.  Provisioning, payment webhooks and licensing operations must be
    idempotent.
7.  External calls require timeouts, retry/backoff, correlation IDs and
    structured errors. Do not hold DB transactions during remote calls.
8.  All UI strings are translatable. Extensions/themes are versioned.
9.  Start as a modular monolith. Architectural deviations require an ADR
    in `docs/adr/`.
10. End each milestone by running formatter, static analysis and tests
    and documenting changed files/remaining work.

## 1. Product scope

Lifecycle:
`Visitor → Cart → Order → Risk → Payment → Provisioning → Active Service → Renewal → Upgrade/Downgrade → Support → Suspension → Termination`.

Support shared/reseller hosting, VPS/VDS/cloud, dedicated servers,
domains, SSL, email, software licenses, managed services and generic
recurring/one-time products. Product must be self-hostable, white-label,
API-first, multi-language, multi-currency, auditable, automation-first
and commercially licensable.

## 2. Technology baseline

Use newest stable compatible versions at implementation time.

-   Backend: PHP 8.4+, Laravel 13.x preferred, MariaDB 11.8 LTS, Redis,
    Horizon, Scheduler, Sanctum, Pest, PHPStan/Larastan, Pint.
-   Frontend: Vue 3, TypeScript, Inertia.js for first-party admin/client
    UI, Tailwind CSS, Vite, internal accessible design system.
-   Runtime: Docker Compose for dev; Nginx/Caddy + PHP-FPM initially;
    optional Octane/FrankenPHP only after profiling; S3-compatible
    object storage; centralized logs/metrics.
-   Kubernetes-compatible later, but Kubernetes must not be required for
    installation.

Repository:

``` text
app/{Domain,Application,Infrastructure,Http,Support}
modules/
themes/{storefront,client,admin}
database/
resources/
routes/
tests/
docs/{adr,api,architecture,modules,operations}
docker/
CLAUDE.md
```

## 3. Architecture

Use a modular monolith with Domain, Application, Infrastructure and
Interface layers. Domains communicate through application services,
commands/queries and domain events. Slow side effects use queues.

Never call provider SDKs from controllers, put external orchestration in
Eloquent models, manipulate another domain's tables directly, create
giant generic service classes, hardcode vendors, or put business rules
in Vue components.

## 4. Core bounded contexts

### Identity & Access

Customers, contacts/subaccounts, staff, roles, granular permissions,
TOTP 2FA, recovery codes, sessions, API tokens, login history,
impersonation with audit logging and rate limiting. Never use only
`is_admin`.

### CRM

Customer/company profiles, addresses, tax IDs, contacts, custom fields,
tags, notes, communication preferences, credit, service/billing history
and privacy export/anonymization workflows.

### Catalog

`Product Group → Product → Prices → Configurable Options → Addons → Provisioning Module → Upgrade Paths → Automation Policy`.
Billing cycles:
one-time/monthly/quarterly/semiannual/annual/biennial/triennial; metered
later. Money is integer minor units + ISO currency, never float.

### Cart / Checkout / Orders

Cart, configurable products, domains, addons, promotions, tax, terms,
checkout, risk decision, payment intent, manual review and full status
history. States:
`draft,pending,awaiting_payment,payment_review,fraud_review,paid,provisioning,active,partially_fulfilled,cancelled,failed,refunded`.
Transitions are explicit and tested.

### Billing

Invoices/lines, payments, transactions, credits, refunds, credit notes,
taxes, currency/exchange snapshots and payment-method references.
Support sequential numbering, PDF, optional proforma, partial payment,
overpayment, account credit, recurring invoices, dunning, late fees,
multi-currency and tax abstraction. Issued invoices snapshot
customer/address/description/price/tax data. Never hardcode one
country's tax rules into core billing.

### Payments

Stable gateway contract: capabilities, create payment, refund, webhook.
Initial adapters: Stripe, PayPal, bank transfer. Verify signatures,
persist/deduplicate provider event IDs, use idempotency keys, reconcile
async state and never trust redirect success as payment proof.

### Service Lifecycle

States:
`pending,provisioning,active,suspended,grace_period,cancel_pending,terminated,failed`.
Operations: create/provision/suspend/unsuspend/renew/change
package/upgrade/downgrade/reset credential/terminate/sync. Store
customer, product, billing snapshot, next due date, target node,
external ID, hostname/domain and configuration snapshot.

### Provisioning Framework

Contract exposes connection test, create, suspend, unsuspend, terminate,
package change and sync. Adapters later: cPanel/WHM, Plesk, DirectAdmin,
Proxmox VE, Virtualizor, SolusVM, generic REST. Queue flow:
`PaymentSucceeded → MarkOrderPaid → CreateService → ProvisionJob → Adapter → SaveExternalID → ActivateService → WelcomeNotification`.
Permanent failures enter manual intervention.

### Infrastructure Inventory

Server groups/nodes, capacity, product assignments, health, weight,
maintenance, account limits, region, encrypted credentials and health
checks. Placement strategies: least-accounts, weighted, capacity-aware,
region-aware, manual.

### Domains

Registrar abstraction for
availability/register/transfer/renew/nameservers/contacts/EPP/lock/auto-renew/sync.
Domain pricing includes TLD, register/renew/transfer/redemption, term,
currency and margin.

### Support

Departments, tickets, replies, internal notes, attachments, priority,
assignment, tags, SLA, canned responses and links to
services/orders/invoices. Email piping later.

### Notifications

Email, in-app and webhook; SMS later. Templates are locale/brand aware,
previewable, testable and overrideable.

### Promotions

Fixed/percentage, scope, cycles, first-payment/recurring, usage limits,
customer limits, date ranges, minimums, new-customer-only and
stackability.

### Automation

Renewal invoices, reminders, overdue notices, suspend/terminate, domain
expiry/renewal, service/domain sync, provisioning retries, exchange
rates, health checks, notification retries and cleanup.

### Audit & Reporting

Audit actor/action/target/time/IP/user-agent/correlation-ID/safe
diff/reason. Reports: MRR, ARR, active services, churn, invoice aging,
unpaid, revenue by product/gateway, refunds, renewals, suspensions,
tickets and provisioning failures.

## 5. Extension SDK

Module types:
`payment-gateway,provisioning,registrar,notification-channel,fraud,tax,report,admin-widget,client-widget,addon`.

Manifest includes name, slug, type, version, compatible platform range,
provider, entrypoint, permissions and dependencies. Lifecycle:
install/enable/disable/upgrade/guarded-uninstall. Modules may provide
migrations, config schema, encrypted secret fields, routes,
translations, permissions, event listeners, navigation, hooks, health
checks and logs.

Expose platform-owned contracts rather than Laravel internals to reduce
breakage across framework upgrades. Important events include
CustomerCreated, OrderPlaced, OrderPaid, InvoiceIssued, InvoicePaid,
PaymentFailed, ServiceProvisioned/Suspended/Terminated,
DomainRegistered/Renewed and TicketOpened/Replied.

## 6. Theme / template / white-label

Surfaces: Storefront, Client Area, Admin. Theme precedence:
`installation override → child theme → parent theme → core fallback`.

Support theme manifests, child themes, layouts/components, logo/favicon,
colors/typography, email/invoice branding, settings schema, translations
and upgrade-safe overrides. Do not require core-file editing. Public
storefront must not be permanently locked to Inertia; keep a renderer
abstraction for Blade themes and future headless/API storefronts.

White-label settings: company/legal name, addresses, contacts, logos,
favicon, colors, domains, email sender, invoice footer, legal links,
portal naming and vendor-brand removal according to license edition.

## 7. API

Version under `/api/v1`: auth, clients, products, carts, orders,
invoices, payments, services, domains, tickets and admin automation.
Require scopes, rate limits, pagination/filter/sort, consistent errors,
request IDs, idempotency keys for sensitive POSTs and OpenAPI docs.
Outbound webhooks use signing secrets, retry/backoff and delivery logs.
Prefer public ULID/UUID identifiers.

## 8. Database conventions

Prefer ULIDs for public/core aggregate IDs. Add timestamps and
domain-appropriate soft deletion only. Core table families: identity;
catalog; cart/orders; invoices/payments/refunds/credits;
services/events/config; servers/groups; domains/TLD prices; tickets;
promotions; modules; themes; notification deliveries; webhooks; audit
logs; automation runs; failed operations. Encrypt provider secrets. Add
uniqueness for provider event IDs/idempotency keys and indexes based on
actual access patterns.

## 9. Separate licensing platform

Licensing is a separate application/service and database. Customer
installation compromise must not grant access to the licensing control
plane.

Architecture:
`Installation → HTTPS License API → License DB → Vendor License Admin`.

Entities: product, edition, license, activation, installation,
entitlement, feature, release/update channel and audit event. Types:
trial/monthly/annual/lifetime/development/partner. Constraints may
include domain, installation ID, server fingerprint, IP/CIDR if
commercially necessary, max installations, expiry, maintenance
entitlement, feature flags and edition.

Activation protocol: 1. Installation creates persistent installation
UUID. 2. Send license key + installation ID + normalized environment
claims over TLS. 3. Server validates status/limits/entitlements. 4.
Return short-lived signed token containing license ID, edition,
features, expiry and heartbeat deadline. 5. Application verifies locally
using embedded public key; private signing key stays only on licensing
infrastructure. 6. Cache successful validation for configurable grace
period; temporary license-server outage must not instantly disable
production. 7. Revocation takes effect according to heartbeat/grace
policy; replay and clock anomalies are audited.

Endpoints: `POST /v1/licenses/{activate,heartbeat,deactivate,validate}`,
`GET /v1/licenses/entitlements`, `GET /v1/releases/latest`.

Admin: create/revoke/suspend, change edition, reset activations, trials,
products/features, installation/heartbeat history and full audit. Update
service uses signed release manifests, checksums, stable/beta channels,
compatibility bounds and download entitlement.

Do not use reversible obfuscation as the trust model. Assume distributed
PHP can be inspected unless a separate commercial code-protection
decision is made.

## 10. Security / reliability

CSRF, strict validation, output escaping, CSP where practical, secure
cookies, session rotation, 2FA, secure password hashing, rate limiting,
encrypted credentials, secret redaction, webhook verification, SSRF
controls, safe uploads, non-public attachment storage, optional AV hook,
authorization policies and audit trail. High-risk admin actions can
require recent password/2FA confirmation.

Structured JSON logs in production. Correlation ID on requests/jobs.
Monitor HTTP latency/errors, queues, payment/provisioning/registrar/mail
failures, scheduler heartbeat, DB/Redis and license API. Build an Admin
System Health page.

## 11. Testing / CI

Tests: money/tax/discount/state machines/license rules;
auth/RBAC/checkout/invoices/services/tickets; provider contract tests;
webhook replay/idempotency; queue retry; invoice/payment concurrency;
fake-provider integration; critical browser E2E; migration and license
grace/revocation tests. Normal suite never performs real paid provider
calls.

CI: Composer validate/install → npm ci → lint → Pint → PHPStan/Larastan
→ Pest → TS typecheck/tests → frontend build → dependency/security
checks → artifact package.

## 12. Roadmap

### Phase 0 --- Foundation

Repo, Docker, Laravel/Vue/TS/Inertia, MariaDB/Redis, CI, coding
standards, ADRs, error envelope, correlation IDs, audit and RBAC
foundation.

### Phase 1 --- Identity + CRM

Auth, staff/customer accounts, contacts, roles/permissions, 2FA,
sessions, audit and admin/client shells.

### Phase 2 --- Catalog + Pricing

Groups, products, cycles, price books, options, addons and admin catalog
UI.

### Phase 3 --- Cart + Orders

Cart, checkout, promotions, tax interface, order state machine and admin
order management.

### Phase 4 --- Billing + Payments

Invoice engine, PDF, recurring billing, credits/refunds,
Stripe/PayPal/manual gateway and webhook idempotency.

### Phase 5 --- Services + Provisioning

Service lifecycle, servers/groups, placement, provisioning SDK, generic
fake provider, then one real hosting-panel adapter.

### Phase 6 --- Domains

TLD pricing, registrar SDK, domain lifecycle and one registrar adapter.

### Phase 7 --- Automation

Renewals, reminders, dunning, suspension/termination, retries, health
checks and automation UI.

### Phase 8 --- Support + Notifications

Ticketing, departments/SLA, notification templates/channels and delivery
logs.

### Phase 9 --- Themes + White-label

Storefront/client theme SDK, child themes, branding and theme packaging.

### Phase 10 --- Module SDK + Developer Experience

Module CLI/scaffolding, manifests, lifecycle, compatibility checks,
hooks/events, docs and example modules.

### Phase 11 --- Licensing Control Plane

Separate license API/admin/database, signed tokens, heartbeat/grace,
editions/features, activation limits and update entitlement.

### Phase 12 --- Reporting + Operations

Dashboards, exports, health UI, backup/restore docs,
performance/security hardening.

### Phase 13 --- Release Candidate

Upgrade/rollback tests, installer, environment checks, packaging,
module/theme compatibility matrix, migration rehearsal, security review,
load tests and disaster-recovery rehearsal.

## 13. Definition of Done

A feature is not done until: migrations are reversible where practical;
policies/permissions exist; validation exists; tests cover
success/failure/idempotency; audit exists for sensitive actions;
translations exist; API docs are updated; errors are actionable; secrets
are redacted; UI handles loading/empty/error states; relevant indexes
exist; and operational documentation is updated.

## 14. First Claude Code execution prompt

Start with Phase 0 only. First inspect the repository. Then produce
`docs/architecture/phase-0-plan.md` containing: proposed folder
structure, Composer/npm dependencies with justification, Docker
services, environment variables, CI stages, RBAC approach, audit
approach, correlation-ID approach, test strategy and ADRs required. Do
not implement until the plan is written. After writing it, implement
Phase 0 in small commits/logical steps, run all checks, and create
`docs/architecture/phase-0-result.md` with commands run, test results,
files changed, unresolved risks and the exact next recommended task. Do
not begin Phase 1 automatically.

## 15. Key architecture decisions to preserve

-   Modular monolith first; extract services only when operational
    evidence justifies it.
-   Billing, provisioning and licensing are separate bounded contexts.
-   Licensing control plane is a separate deployment/database.
-   Provider integrations are adapters behind stable contracts.
-   Redis queues handle slow side effects; Horizon monitors them.
-   Financial values never use float.
-   Payment/provisioning/webhook/license operations are idempotent.
-   Themes/modules never require core edits.
-   Public APIs and extension contracts are versioned.
-   Customer installations tolerate temporary license-server outages
    through signed cached entitlements and grace periods.

---

# V2 ADDENDUM — CLIENT AREA, API, RESELLER & OPERATIONS

> This section supersedes conflicting V1 requirements. Treat the combined document as V2.

## 1. First-Class Product Surfaces

The platform has five user-facing surfaces sharing the same Application Layer:

1. Storefront — catalog, domain search, cart and checkout.
2. Client Area — customer services, domains, billing, support, account and developer tools.
3. Reseller Area — isolated customer/service/domain management for reseller organizations.
4. Admin Panel — provider operations and configuration.
5. Developer Platform — versioned REST API, tokens, webhooks and documentation.

The Vendor Licensing Control Plane remains a separately deployed sixth system.

Never duplicate business logic between interfaces. Client Area, Admin, Reseller and API must call the same application use cases. Example: both a Client Area reboot button and `POST /api/v1/services/{id}/actions/reboot` call `RestartServiceAction`.

## 2. Reseller-Ready Ownership Model

Introduce an explicit `Organization`/`Account` ownership boundary from Phase 0.

```text
Provider Organization
 ├─ Direct Customers
 └─ Reseller Organization
     └─ Reseller Customers
```

Authorization is: Actor -> Organization Boundary -> Resource Ownership -> Permission.

Do not scatter unrelated nullable `reseller_id` fields throughout the schema. A reseller must never access another reseller's customers, invoices, services, domains, tickets, API tokens, webhooks or reports.

## 3. Client Area

Primary navigation:

```text
Dashboard
Services
 ├─ Hosting
 ├─ VPS/VDS
 ├─ Dedicated
 ├─ Email / SSL
 └─ Other
Domains
 ├─ My Domains
 ├─ Register
 ├─ Transfer
 └─ Renewals
Billing
 ├─ Invoices
 ├─ Transactions
 ├─ Account Credit
 ├─ Payment Methods
 └─ Billing Information
Support
 ├─ Tickets
 ├─ New Ticket
 ├─ Knowledge Base
 └─ Announcements
Marketplace
 ├─ Order Service
 ├─ Upgrades
 └─ Addons
Developer
 ├─ API Tokens
 ├─ Webhooks
 ├─ API Documentation
 └─ API Activity
Account
 ├─ Profile
 ├─ Contacts
 ├─ Security / 2FA
 ├─ Sessions
 └─ Notifications
```

Dashboard: active services, unpaid invoices, upcoming renewals, expiring domains, open tickets, announcements, credit and recent activity.

Service pages expose core tabs: Overview, Management, Usage, Credentials, Upgrade/Downgrade, Addons, Billing and Activity.

Provisioning modules may register UI capabilities. VPS modules can add Power, Console, Metrics, Network, rDNS, Snapshots, Backups, Reinstall and Rescue Mode. Hosting modules can add Control Panel Login, Disk/Bandwidth, Domains, Email, Databases and Backups.

Stable extension points:

```text
ClientDashboardWidget
ClientNavigation
ClientSidebar
ClientServiceOverview
ClientServiceTab
ClientServiceAction
ClientInvoiceAction
ClientDomainAction
```

Module UI never bypasses core authorization/audit.

## 4. Reseller Area

Design for reseller dashboard, customers, services, domains, orders, invoices, tickets, API, branding, product availability, margin/price overrides, credit and reports.

Full reseller commercial logic may ship later, but organization isolation must exist from the beginning.

## 5. Admin Panel Map

```text
Dashboard
Customers: Customers, Contacts, Resellers, Tags
Orders: Orders, Review Queue, Failed Fulfillment
Billing: Invoices, Payments, Transactions, Refunds, Credits, Credit Notes, Tax
Services: All, Suspended, Pending Provisioning, Failed Operations
Domains: Domains, Transfers, Renewals, TLD Pricing
Support: Tickets, Departments, SLA, Canned Responses, Knowledge Base
Products: Groups, Products, Options, Addons, Promotions
Infrastructure: Server Groups, Servers, Capacity, Health
Automation: Jobs, Failed Operations, Scheduled Tasks, Settings
Developer: API Tokens/Clients, Webhooks, API Activity, Integration Logs
Appearance: Themes, Branding, Email Templates, Invoice Templates
Extensions: Modules, Module Health
System: Staff, Roles, Settings, Security, Health, Maintenance, Updates, Audit
```

## 6. Public REST API

All public APIs are versioned under `/api/v1`.

Resource groups:

```text
/profile
/products
/orders
/invoices
/payments
/services
/domains
/tickets
/webhooks
/admin/*
```

Examples:

```text
GET  /api/v1/services
GET  /api/v1/services/{service}
POST /api/v1/services/{service}/actions/reboot
POST /api/v1/services/{service}/actions/shutdown
GET  /api/v1/domains
POST /api/v1/domains/{domain}/renew
GET  /api/v1/invoices
GET  /api/v1/invoices/{invoice}
GET  /api/v1/tickets
POST /api/v1/tickets
POST /api/v1/tickets/{ticket}/replies
```

Requirements:

- public ULID/UUID identifiers where appropriate;
- token scopes;
- per-token/IP rate limits;
- pagination/filtering/sorting;
- stable error envelope;
- request/correlation IDs;
- idempotency keys for sensitive writes;
- expiry/revocation;
- last-used metadata;
- API audit/activity logs;
- OpenAPI spec validated in CI;
- developer docs and examples;
- secret/token shown only at creation.

Example scopes:

```text
profile:read profile:write
services:read services:write
domains:read domains:write
invoices:read
tickets:read tickets:write
customers:read customers:write
orders:read orders:write
billing:read billing:write
infrastructure:read infrastructure:write
```

Use Sanctum initially. Keep contracts clean enough for OAuth2/OIDC third-party apps later.

## 7. Outbound Webhooks

Events include:

```text
invoice.created invoice.paid invoice.overdue
payment.completed payment.failed payment.refunded
order.created order.paid order.fulfilled
service.created service.activated service.suspended service.unsuspended service.terminated
domain.registered domain.renewed domain.expiring
ticket.created ticket.replied
```

Each endpoint has a signing secret. Require HMAC signature + timestamp, replay protection, stable event ID, persisted delivery attempts, timeout, exponential retry, manual redelivery, delivery logs and payload versioning.

## 8. Background Operations Center

Long-running business operations must be visible in Admin instead of only existing as queue jobs.

Track: operation ID, type, actor, target, state, attempt, progress, correlation ID, timestamps, sanitized error, next retry and manual-intervention flag.

Use for provisioning, termination, domain registration/transfer/renewal, imports, bulk operations and updates.

States: Pending, Running, Retrying, Failed, Manual Intervention, Completed.

## 9. Fraud / Risk Engine

Create a provider-neutral risk layer before automatic fulfillment.

Possible signals: account age, order value, velocity, payment failures, billing/IP mismatch and third-party fraud modules.

Decision contract:

```text
allow
review
deny
```

Persist decision reasons and manual overrides. Do not hardcode a specific fraud vendor into Ordering.

## 10. Knowledge Base & Announcements

Provide a lightweight support-content system:

- categories/articles;
- draft/published state;
- localized content;
- search;
- announcements with publish/expiry dates;
- maintenance notices.

Do not build a general CMS.

## 11. Theme / White-Label V2

Surfaces: Storefront, Client, Reseller and optionally Admin.

Override precedence:

```text
brand/installation override
 -> child theme
 -> parent theme
 -> core fallback
```

Theme manifest declares name, slug, version, platform compatibility, parent, supported surfaces and settings schema.

White-label settings: company/legal name, address, support contacts, logos, favicon, colors, typography, portal name, email identity, invoice branding, footer/legal links and optional vendor-brand removal based on license entitlement.

No core-file edits.

## 12. Module SDK V2

Module types:

```text
payment-gateway
provisioning
registrar
notification-channel
fraud
tax
report
admin-widget
client-widget
reseller-widget
addon
```

Lifecycle: install, enable, disable, upgrade, guarded uninstall.

Modules may provide migrations, configuration schemas, encrypted secret fields, translations, permissions, events, navigation/UI registrations, health checks and isolated logs.

Expose platform-owned SDK contracts rather than framework internals wherever practical.

## 13. Import / Migration Framework

Support migration from legacy hosting platforms, especially WHMCS-like systems.

Pipeline:

```text
Connect/Upload -> Analyze -> Map -> Dry Run -> Validate -> Import -> Reconcile -> Report
```

Potential domains: customers, contacts, products, services, domains, invoices, transactions and tickets.

Requirements: resumable jobs, deterministic external-ID mapping, duplicate protection, dry run, detailed errors and no silent data loss.

## 14. Localization

Locale, timezone and currency are independent concepts. Store timestamps in UTC and render in user/account timezone. Email, notification, knowledge-base, theme and invoice-visible labels must support localization.

## 15. Feature Flags vs License Entitlements

Keep separate:

1. deployment configuration;
2. feature flags;
3. commercial license entitlements.

Never scatter checks such as `license == enterprise` throughout the codebase. Use a central entitlement service, e.g. `Entitlements::allows('reseller.white_label')`.

## 16. System Health / Maintenance

Admin System Health reports application version, runtime, DB/Redis, queue depth, failed jobs, scheduler heartbeat, object storage, mail, provider connections, update state and license heartbeat.

Maintenance mode supports message, scheduled start/end, staff bypass and predictable API responses. Never expose environment secrets.

## 17. Backup / Restore

Document backup boundaries: MariaDB, object storage, environment/secrets, themes/modules and generated documents. Provide restore runbooks and verification.

Do not advertise an in-app backup button unless it can produce a consistent recoverable snapshot.

## 18. Update / Release System

Commercial releases use signed manifests, checksums, stable/beta channels, compatibility metadata and migration requirements.

Before update: compatibility, disk/permissions, maintenance strategy and backup readiness.
After update: migrations, caches, queue restart and smoke/health tests.

Never overwrite customer theme/module data blindly.

## 19. Licensing Control Plane V2

Licensing stays in a separate application/database.

Use signed short-lived license tokens with locally verifiable public-key signatures and a grace period. Private signing keys remain only on licensing infrastructure.

Model products, editions, licenses, activations, installations, entitlements, features, update channels and immutable audit events.

Do not treat source-code obfuscation as the trust boundary.

Central entitlement examples:

```text
api.enabled
reseller.enabled
reseller.white_label
advanced_reports
priority_updates
max_staff_users
max_reseller_accounts
```

## 20. Additional Security Requirements

Add:

- SSRF protections for configurable URLs;
- strict file upload MIME/size/storage rules;
- antivirus scanning hook;
- CSP/security headers;
- signed temporary private downloads;
- webhook replay protection;
- API scopes/idempotency;
- secret rotation support;
- recent-auth for destructive admin actions;
- dependency/static security scanning;
- authorization tests for organization isolation.

## 21. Observability

Use structured JSON logs in production and correlation IDs across HTTP requests, jobs, provider calls and webhook deliveries.

Measure HTTP latency/errors, queues, scheduler heartbeat, payment webhook failures, provisioning success/failure, registrar errors, notifications, DB/Redis and licensing health.

External calls require explicit timeout, bounded retries, exponential backoff and sanitized structured errors.

## 22. Revised Roadmap

### Phase 0 — Foundation
Repository, Docker, Laravel/Vue/TS/Inertia, MariaDB, Redis, CI, ADRs, correlation IDs, RBAC/audit foundations and organization ownership.

### Phase 1 — Identity + CRM
Customers, contacts, staff, organizations, permissions, 2FA, sessions, profile/security and impersonation audit.

### Phase 2 — Catalog + Storefront
Products, groups, pricing, options, addons, currencies, storefront catalog and theme foundation.

### Phase 3 — Cart + Checkout + Orders + Risk
Cart, domains-in-cart abstraction, promotions, checkout, tax interface, order state machine and risk engine.

### Phase 4 — Billing + Payments
Invoices/PDF, payments, transactions, credit/refunds, Stripe, PayPal, manual payment, webhooks and reconciliation.

### Phase 5 — Client Area
Dashboard, services, billing, support shell, account/security and Developer section.

### Phase 6 — Services + Provisioning
Service lifecycle, infrastructure inventory, placement, queues and first hosting/VPS adapters.

### Phase 7 — Domains
Registrar SDK, TLD pricing, register/transfer/renew, nameservers and synchronization.

### Phase 8 — Support + Content + Notifications
Tickets, departments, SLA, KB, announcements, email/in-app/webhook notifications.

### Phase 9 — Automation + Operations
Renewals, reminders, suspension/termination, retries, Background Operations Center and System Health.

### Phase 10 — Public API + Developer Platform
`/api/v1`, scopes, rate limits, idempotency, OpenAPI, API activity and outbound webhook management.

### Phase 11 — Theme / White-Label
Storefront/client/reseller theme manifests, child themes, branding and upgrade-safe overrides.

### Phase 12 — Module SDK
Stable extension contracts, module lifecycle, permissions, UI extension points and compatibility checks.

### Phase 13 — Reseller
Reseller isolation, customers, services, pricing/margins, API, branding, credit and reports.

### Phase 14 — Licensing Control Plane
Separate License API/Admin, signed entitlements, activations, heartbeat, grace period and update entitlement.

### Phase 15 — Import / Migration
Importer framework and first WHMCS-compatible migration adapter.

### Phase 16 — Reporting / Operations
MRR/ARR, churn, aging, product/gateway revenue, renewals, support metrics and operational reports.

### Phase 17 — Production Hardening
Security review, concurrency/load tests, backup/restore drills, upgrade tests, accessibility, disaster runbooks and release candidate.

## 23. Definition of Done for Every Phase

A phase is not complete until:

- migrations exist and are reviewed;
- authorization policies exist;
- audit behavior exists for sensitive actions;
- unit/feature tests pass;
- organization-isolation tests pass where relevant;
- API contract/docs are updated where relevant;
- UI handles loading/empty/error states;
- async work is idempotent;
- secrets are redacted;
- translations are used;
- static analysis/lint/typecheck pass;
- operational documentation is updated;
- no TODO is silently used to defer a required acceptance criterion.

## 24. Claude Code First Execution

When starting a new repository:

1. Read this full V2 handoff.
2. Produce `docs/architecture/implementation-plan.md`.
3. Produce initial ADRs for modular monolith, organization ownership, money representation, module SDK boundary and licensing separation.
4. Create only Phase 0.
5. Do not implement billing, provisioning, domains or licensing yet.
6. Run all Phase 0 checks.
7. Report architecture created, files changed, commands run, tests, unresolved decisions and the proposed Phase 1 task breakdown.

The objective is not to generate the largest amount of code. The objective is to create a stable commercial platform that can be evolved for years without coupling customer UI, APIs, billing and provider integrations together.
