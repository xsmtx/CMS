# Phase 6 — Services + Provisioning Result

Status: complete
Date: 2026-09-27
Plan: `phase-6-plan.md`
Next phase: Phase 7 (Domains) — **not started**

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **659 passed, 2274 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 17 passed |
| Build | `vite build` | pass |
| Migrations | 21 migrations on MariaDB 11.8 | clean |

Phase 5 finished at 599 tests; this phase adds 60.

## 2. The two decisions this phase turns on

**Provisioning is idempotent, and failure is a state**
([ADR 0026](../adr/0026-provisioning-is-idempotent-and-failure-is-a-state.md)).
Everything before this phase could be undone by rolling back a transaction;
an account on somebody else's control panel cannot. So the external id is
written the moment it arrives, results are three-valued — `already_done` is
what makes a retry safe — and a run that cannot succeed leaves the service
in `failed` with a reason, in a queue an operator works through, rather than
in `provisioning` forever.

**Contexts meet through events**
([ADR 0027](../adr/0027-contexts-meet-through-events.md)). An order reaching
`paid` is a fact about ordering; deciding that somebody should go and create
a hosting account is provisioning's business, and it subscribes. Ordering
does not know provisioning exists, and Phases 8 and 9 add listeners rather
than editing the order state machine.

## 3. Problems found

**Two placement strategies were sorting by something other than what they
looked like.** Laravel reads a callable inside `sortBy([...])` as a
**two-argument comparator**, not as a key extractor, so
`sortBy([fn ($s) => $used[$s->id], fn ($s) => $s->id])` silently ordered by
the first server's count rather than by each server's. Least-accounts
happened to pass anyway; weighted and capacity-aware did not, which is how
it surfaced. All three now build one composite sort key.

**A provider's error message put a password in an event record.** The
redactor cleaned structures but not prose, and a control panel habitually
quotes back the request that caused the failure —
`createacct failed for user bob with password=…` — which is exactly the text
written to an event for an operator to read later. `SecretRedactor` now
cleans `key=value`, `key: value` and `"key":"value"` in free text using the
same configured fragments, so adding a fragment protects logs and prose at
once. Found by a test asserting the negative.

**Horizon was not watching the queue the jobs go on.** The supervisor listed
`default`; every provisioning job is dispatched to `provisioning`. Nothing
would have run, and the failure mode is silence — a service that sits in
"Pending setup" while the platform reports no error at all. Both queues are
listed now, provisioning first, and the symptom is in the troubleshooting
guide.

## 4. What was built

### The service lifecycle

Eight states with explicit transitions. `failed` is a place a service sits,
not an exception, and it transitions back into `provisioning` because
retrying after fixing the cause is the normal response. `terminated` is
terminal in the strongest sense available: the account is gone at the
provider and a new service is a new account.

An operator cannot declare a service `active` by hand — saying a service
works does not make an account exist.

### The provisioning contract

`ProvisioningModule`: connection test, create, suspend, unsuspend,
terminate, change package, sync. Adapters take a value object and return
one, so there is no path by which a provider integration writes to the
database, reaches a customer through a relation, or stores a credential.
Capabilities are asked rather than assumed, so the interface offers a
suspend button only where suspending means something.

Two adapters:

- **`ManualModule`** — an operator does it. A real module rather than a
  special case, so "somebody set this up themselves" and "cPanel set this
  up" travel the same path and produce the same events.
- **`CpanelModule`** — WHM API 1 over HTTPS with a token, never a password.
  Reads success out of the body rather than the status code, maps "already
  exists" and "does not exist" onto `already_done`, and derives its username
  deterministically from the domain so a retry asks for the same account.

### Infrastructure and placement

Server groups own the placement strategy, so an operator rebalancing a fleet
edits one row rather than every product. Five strategies, and three rules
that hold regardless: not `active` is never chosen, at capacity is never
chosen, and nothing available fails loudly. A suspended service still
occupies a slot; a terminated one does not.

Server credentials are encrypted at rest, hidden from array conversion, and
handed to an adapter only inside a value object that refuses to print them.
The token is never sent to the browser — not even masked. An operator
changing it types a new one.

### The queue

The first real work Horizon carries. Every job is unique on its subject,
has bounded tries with backoff, carries the correlation id from the request
that started it, and has a `failed()` hook that records the outcome instead
of leaving a service mid-flight.

### Screens

Admin: services with the three counts an operator opens the screen for —
pending, failed, suspended — a detail screen offering only the actions the
module supports, the event log, and credentials behind an endpoint that
records the operator asking. Infrastructure: groups with their strategy,
nodes with capacity and health, a connection test that says what answered.

Client: the Services row the navigation has been deliberately missing. It
carries what the customer needs — status, what they chose, when it is next
due, and the credentials that are theirs — and none of the operator
vocabulary. A customer reading "placement failed on node-07" learns only
that something they do not control is broken.

## 5. Files

```text
app/Domain/Provisioning/      ServiceStatus, ServerStatus, PlacementStrategy,
                              AutoSetup, ServiceOperation, OperationOutcome,
                              ModuleCapabilities, ServerConnection,
                              ServiceReference, ProvisioningRequest,
                              PackageChange, ProvisioningResult,
                              ConnectionResult, SyncResult,
                              Contracts/ProvisioningModule
app/Domain/Ordering/Events/   OrderPaid
app/Application/Provisioning/ CreateServicesForOrder, PlaceService,
                              RunServiceOperation, TransitionService,
                              RecordServiceEvent, Listeners/StartFulfilment,
                              Exceptions/
app/Infrastructure/           Provisioning/Models, ModuleRegistry,
                              Modules/{Manual,Cpanel},
                              Jobs/{ProvisionService,RunServiceAction,
                              CheckServerHealth}
app/Http/                     Controllers/Admin/{Service,Infrastructure},
                              Controllers/Client/ServiceController,
                              Requests/Provisioning/
app/Policies/                 Service
app/Providers/                ProvisioningServiceProvider
database/migrations/          provisioning tables, product columns
resources/js/Pages/           Admin/Services/{Index,Show},
                              Admin/Infrastructure/Index,
                              Client/Services/{Index,Show}
lang/{en,tr}/                 provisioning.php
docs/adr/                     0026, 0027
```

## 6. Not done, and why

| Item | Detail |
| --- | --- |
| **More adapters** | Plesk, DirectAdmin, Proxmox, Virtualizor, SolusVM and a generic REST module all plug into the finished contract. Writing them without an account to test against would produce adapters nobody has ever seen work — the same reason PayPal was deferred in Phase 4. |
| **Module UI capabilities** | The handoff's Power, Console, Metrics, Snapshots and Control Panel Login tabs are Phase 12's extension points. This phase built the service they hang off; inventing the extension API before a second module exists would be designing against one example. |
| **Upgrade and downgrade** | `changePackage` is in the contract, tested, and reachable from the runner. What is missing is the **commercial** half: proration, the credit or invoice it produces, and the upgrade paths a product declares. That is billing work and belongs with Phase 9's automation. |
| **Renewals and dunning** | Phase 9. `next_due_on` is set from the billing cycle when a service is created and nothing advances it yet; suspension for non-payment has a state and an operation waiting for the policy that triggers them. |
| **Usage metering** | `sync` returns disk and bandwidth counters and records them on the event. Nothing charges for them, graphs them, or alerts on them. |
| **Scheduled health checks** | The job exists and runs on demand. Putting it on the scheduler belongs with Phase 9's operations centre, which is where a run of failures should be interpreted. |
| **Domains** | Phase 7. A domain line on an order is deliberately not turned into a service: it needs a registrar behind it, not a provisioning module. |

## 7. Carried risks

| Item | Detail |
| --- | --- |
| **The cPanel adapter has never talked to WHM** | Its request shapes, error handling, idempotency and status mapping are tested against faked HTTP, which proves the code and not the integration. Same honest warning as the Stripe adapter, and it needs one run against a real box before an installation provisions real customers through it. |
| **The redactor's prose pass is not complete** | It catches `key=value` forms. A secret embedded in a sentence with no delimiter — or a token after a second colon, as in `Authorization: whm root:TOKEN` — survives. The structured path, which is where headers actually get logged, redacts fully. |
| **Nothing reconciles the platform against the provider** | `sync` reports what a provider says, and an operator reads it. An account terminated directly on a control panel stays `active` here until somebody looks. A drift report belongs with Phase 9. |
| **Placement counts, it does not reserve** | Two jobs placing at the same instant can both see a node with one slot left. The window is small and the consequence is one node one over its limit, not a failure; a reservation would need a lock held across a remote call, which is exactly what this phase refuses to do. |
| **A terminated service keeps its encrypted password** | Deliberate, for support and disputes, but it is a credential outliving the thing it opened. A retention policy for terminated services belongs with the data-retention work in Phase 17. |
| **The admin screens carry English literals** | Same as the rest of the admin front end. The client screens go through `useTranslations`; converting the admin ones is mechanical and belongs with Phase 11. |

## 8. Exact next recommended task

**Phase 7 — Domains**, which is the other half of what a hosting customer
buys and the one order line this phase deliberately skipped.

1. A registrar contract shaped like the provisioning one: availability,
   register, transfer, renew, nameservers, contacts, lock, auto-renew, sync.
2. TLD pricing — register, renew, transfer and redemption, per term and
   currency, on the price matrix that already exists.
3. Domains as first-class records with their own lifecycle, created from the
   domain lines an order already carries.
4. One adapter, tested against faked HTTP, with the same warning attached.
