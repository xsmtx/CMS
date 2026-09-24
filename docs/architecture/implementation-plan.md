# InfraCMS — Implementation Plan

Status: living document
Last updated: 2026-09-23
Source of record: `CLAUDE_HOSTING_PLATFORM_HANDOFF_V2.md` (the V2 addendum
supersedes conflicting V1 requirements; this plan follows V2).

---

## 1. What is being built

A production-grade, modular, white-label hosting automation platform —
comparable in scope to WHMCS — on Laravel 13 and MariaDB 11.8, with a
separately deployed licensing control plane.

Six systems share one application core:

| Surface | Audience | Rendering |
| --- | --- | --- |
| Storefront | Anonymous visitors | Blade through `StorefrontRenderer`, themeable |
| Client Area | Customers | Inertia + Vue 3 |
| Reseller Area | Reseller staff | Inertia + Vue 3 |
| Admin Panel | Provider staff | Inertia + Vue 3 |
| Developer Platform | Integrators | `/api/v1`, Sanctum tokens, OpenAPI |
| Licensing Control Plane | Vendor only | Separate application and database |

The rule that keeps these from diverging: **business logic lives in
application use cases, and every surface calls the same one.** A Client Area
reboot button and `POST /api/v1/services/{id}/actions/reboot` both invoke
`RestartServiceAction`. No surface owns a rule.

## 2. Architecture in one page

```text
                    ┌──────────────────────────────────────────┐
  Storefront ─────► │                                          │
  Client Area ────► │            Http (interface)              │
  Reseller ───────► │  validate · authorize · render           │
  Admin ──────────► │                                          │
  API /v1 ────────► └────────────────────┬─────────────────────┘
                                         │ commands / queries
                    ┌────────────────────▼─────────────────────┐
                    │             Application                  │
                    │  one class per use case · DTOs · events  │
                    └────────────────────┬─────────────────────┘
                                         │ contracts
                    ┌────────────────────▼─────────────────────┐
                    │               Domain                     │
                    │  entities · value objects · policies     │
                    │  (no framework imports)                  │
                    └────────────────────▲─────────────────────┘
                                         │ implements
                    ┌────────────────────┴─────────────────────┐
                    │            Infrastructure                │
                    │  Eloquent · adapters · queues · cache    │
                    └──────────────────────────────────────────┘
```

Cross-cutting concerns that are not a bounded context live in `Support`:
correlation identifiers, the error envelope, logging and redaction, the audit
API, the organization boundary.

Every authorization decision is two independent questions asked in order:

```text
actor → organization boundary → resource ownership → permission
```

The boundary decides which rows exist. The permission decides what may be
done to them. Neither substitutes for the other. See ADR 0002 and 0007.

## 3. Decisions already fixed

| ADR | Decision |
| --- | --- |
| 0001 | Modular monolith, four layers, enforced by architecture tests |
| 0002 | Organization ownership boundary from the first migration |
| 0003 | ULIDs as public aggregate identifiers |
| 0004 | One stable JSON error envelope with an error-code enum |
| 0005 | Correlation IDs propagated through Laravel Context |
| 0006 | Append-only audit log with secret redaction |
| 0007 | First-party permission-based RBAC; no `is_admin` |
| 0008 | Redis + Horizon for queues; idempotent external operations |
| 0009 | Inertia for authenticated areas, renderer abstraction for storefront |
| 0010 | Structured JSON logging with redaction |
| 0011 | Pint, Rector, PHPStan 8, Pest with architecture tests |
| 0012 | Docker Compose for development; Kubernetes never required |
| 0013 | Licensing control plane is a separate system |
| 0014 | Money as integer minor units, never float |
| 0015 | Module SDK depends on platform contracts, not framework internals |
| 0016 | Two authenticatables, two guards |
| 0017 | First-party authentication, not Fortify |
| 0018 | Impersonation safeguards |

## 4. Roadmap

Phase numbering follows the V2 revised roadmap (handoff §22). Each phase ends
with the Definition of Done in §6 satisfied for everything it introduced.

| Phase | Scope | Status |
| --- | --- | --- |
| 0 | Foundation: repo, Docker, Laravel/Vue/TS/Inertia, MariaDB, Redis, CI, ADRs, correlation IDs, RBAC + audit foundations, **organization ownership** | **complete** — see `phase-0-result.md` |
| 1 | Identity + CRM: customers, contacts, staff, permissions UI, 2FA, sessions, profile/security, impersonation with audit | **complete** - see `phase-1-result.md` |
| 2 | Catalog + Storefront: products, groups, pricing, options, addons, currencies, storefront catalog, theme foundation | **complete** — see `phase-2-result.md` |
| 3 | Cart + Checkout + Orders + Risk: cart, domains-in-cart abstraction, promotions, checkout, tax interface, order state machine, risk engine | **complete** — see `phase-3-result.md` |
| 4 | Billing + Payments: invoices and PDF, payments, transactions, credits/refunds, Stripe, PayPal, manual payment, webhook idempotency, reconciliation | **complete** — see `phase-4-result.md` |
| 5 | Client Area: dashboard, services, billing, support shell, account/security, developer section | **complete** — see `phase-5-result.md` |
| 6 | Services + Provisioning: service lifecycle, infrastructure inventory, placement, queues, first hosting/VPS adapters | **complete** — see `phase-6-result.md` |
| 7 | Domains: registrar SDK, TLD pricing, register/transfer/renew, nameservers, synchronisation | **complete** — see `phase-7-result.md` |
| 8 | Support + Content + Notifications: tickets, departments, SLA, knowledge base, announcements, email/in-app/webhook notifications | **complete** - see `phase-8-result.md` |
| 9 | Automation + Operations: renewals, reminders, dunning, suspension/termination, retries, Background Operations Center, System Health | **complete** - see `phase-9-result.md` |
| 10 | Public API + Developer Platform: `/api/v1` resources, scopes, rate limits, idempotency, OpenAPI, API activity, outbound webhooks | **complete** - see `phase-10-result.md` |
| 11 | Theme / White-Label: storefront/client/reseller manifests, child themes, branding, upgrade-safe overrides | **complete** - see `phase-11-result.md` |
| 12 | Module SDK: stable contracts, module lifecycle, permissions, UI extension points, compatibility checks | **complete** - see `phase-12-result.md` |
| 13 | Reseller: isolation, customers, services, pricing/margins, API, branding, credit, reports | **complete** — see `phase-13-result.md` |
| 14 | Licensing Control Plane: the installation side — signed entitlements, activation, heartbeat, grace. The vendor's API is a separate deployment (ADR 0013); `docs/licensing/api.md` is its contract | **complete** — see `phase-14-result.md` |
| 15 | Import / Migration: importer framework, first WHMCS-compatible adapter | **complete** — see `phase-15-result.md` |
| 16 | Reporting / Operations: MRR/ARR, churn, aging, revenue by product and gateway, renewals, support metrics | **complete** — see `phase-16-result.md` |
| 17 | Production Hardening: CSP and security headers, SSRF guard, recent-auth for irreversible actions, concurrency guards, accessibility tests, backup/restore and runbooks, release checklist | **complete** — see `phase-17-result.md` |

**The V2 addendum's roadmap is finished.** What is deliberately unproven, and
was deferred by the owner rather than overlooked:

- **The provider adapters have never talked to their real providers.** Stripe,
  PayPal, cPanel and Namecheap are tested against faked HTTP, which proves the
  code and not the integration. `docs/operations/release-checklist.md` says the
  first real deployment must treat each as unproven.
- **No screen has been driven in a browser.** Visual changes are verified against
  the built stylesheet and by the Vitest suite; contrast and 200% zoom stay in
  `docs/design/design-system.md` as things for a person to check.
- **Load testing and a penetration test** need a target environment and an
  engagement respectively, neither of which lives in a repository.

## 5a. Handoff #2 — Advanced Operations

`CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md`, planned in
`advanced-operations-plan.md`, which maps every one of its families onto the
domains above. Its phases are lettered, so its result documents are
`phase-a-result.md` onwards.

| Phase | Scope | Status |
| --- | --- | --- |
| A | Foundation: Resource Graph / Digital Twin, capability registry, telemetry normalization, operations UI shell | **complete** — see `phase-a-result.md` |
| B | Core ops: secret store, monitoring adapters, smart placement, capacity, global record search | not started |
| C | Network: IPAM, device adapters, topology, guarded configuration, JIT access | not started |
| D | Reliability: alerts, incidents, status page, SLA credits, maintenance, postmortems, push | not started |
| E | Security: abuse, evidence, mail reputation, DNS/SSL fleet, WAF/CDN context | not started |
| F | Data platform: backup, storage, database/cache/load balancer, hypervisor and BMC, metering | not started |
| G | Datacenter: DCIM, rack elevation, hardware inventory, PDU/UPS/environment, Remote Hands | not started |
| H | Intelligence: reconciliation, orphans, revenue leakage, cost, automation builder, AI assistant | not started |
| I | Mobile: the staff API surface first, then the customer and staff apps (ADR 0044) | not started |
| J | Advanced: vendors, contracts, procurement, IaC, Kubernetes | not started |

Two rules from that plan decide most of it: **the seam is core and the capability
is a module**, and **nothing is asked of a provider before `SecretStore` exists**,
which is why B gates C and F.

`CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` is in progress: Phase A is done.

## 5. Sequencing constraints

These orderings are not preferences; reversing one causes rework.

- **Organizations before anything owned.** Done in Phase 0. Every table added
  afterwards carries `organization_id` from its first migration.
- **Catalog before cart.** A cart line references a product and a price book
  entry; inventing a placeholder means migrating carts later.
- **Orders before billing.** An invoice is issued *from* an order; the reverse
  dependency does not exist.
- **Billing before services.** Provisioning is triggered by payment, so the
  payment event must exist before there is anything to trigger.
- **Services before domains.** Domains reuse the lifecycle, renewal and
  automation machinery that services establish.
- **Everything before the public API.** The API is a projection of use cases;
  writing it first would mean designing endpoints for behaviour that does not
  exist, then rewriting them.
- **Reseller after the surfaces it resells through.** The isolation boundary
  exists from Phase 0; the reseller *product* needs Client Area, billing and
  services to be real first.
- **Licensing last among the platform phases.** It gates features that must
  exist before they can be gated.

## 6. Definition of Done (every phase)

A phase is not complete until, for everything it introduced:

- migrations exist, are reviewed and are reversible where practical;
- authorization policies exist and are tested;
- organization-isolation tests pass where ownership is involved;
- validation exists on every input;
- tests cover success, failure and idempotency;
- audit records exist for sensitive actions;
- async work is idempotent and does not hold a transaction across a remote
  call;
- secrets are redacted in logs, audit rows and error responses;
- all user-facing strings are translatable;
- UI handles loading, empty and error states;
- API contract and docs are updated;
- indexes match the actual access patterns;
- Pint, Rector, PHPStan, Pest, ESLint, Prettier, `vue-tsc` and Vitest pass;
- operational documentation is updated;
- no `TODO` silently defers a required acceptance criterion.

## 7. Standing constraints

Carried from the handoff §0 and §15, and enforced by review and tests:

- Provider-specific logic lives behind versioned contracts and adapters —
  never in controllers, models or application services.
- Raw card data is never stored; secrets, tokens and private keys are never
  logged.
- Financial records are append-oriented. Finalised history is never silently
  mutated.
- Provisioning, payment webhooks and licensing operations are idempotent.
- External calls have explicit timeouts, bounded retries with backoff,
  correlation IDs and sanitised structured errors.
- Themes and modules never require editing a core file.
- Public APIs and extension contracts are versioned.
- Deployment configuration, feature flags and commercial entitlements stay
  three separate concepts.
- Architectural deviation requires a new ADR in `docs/adr/`.
