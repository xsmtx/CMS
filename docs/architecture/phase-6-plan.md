# Phase 6 — Services + Provisioning Plan

Status: approved for implementation
Date: 2026-09-27
Scope: V2 roadmap Phase 6 — the service lifecycle, the provisioning
contract, infrastructure inventory and placement, the queue that drives
them, and the first adapters. Renewal invoicing, dunning and automatic
suspension for non-payment are Phase 9; this phase builds the machinery they
will call and stops there.

---

## 1. Starting point

An order reaches `paid` and nothing happens. That is the hole this phase
fills, and it is the phase where the platform stops being a shop and starts
being a hosting system.

What it builds on:

- Orders whose lines are a frozen copy of the catalog
  ([ADR 0021](../adr/0021-order-lines-copy-the-catalog.md)) — the service's
  configuration comes from there, not from the product.
- `RecordPayment` moving an order to `paid` through one path, whether the
  money came from a webhook or an operator
  ([ADR 0024](../adr/0024-the-ledger-is-the-truth.md)).
- Redis and Horizon, chosen in [ADR 0008](../adr/0008-queues-and-scheduling.md)
  and so far carrying nothing. This phase is their first real work.
- A provider contract pattern proven twice by the payment gateways: a
  narrow interface, adapters that never reach a controller, and a registry
  that only offers what is actually configured.

## 2. The central decision: a service is a copy, and provisioning is a fact about the world

**A service records what was set up, not what the catalog currently says.**
Product name, billing cycle, price, the chosen options and the hostname are
copied onto the service when it is created — the same rule as an order line,
one more step along the chain. A product repriced next March does not
silently change what an existing service costs, and a renamed option does
not rewrite the disk quota somebody is running on.

**Provisioning is not a database transition.** Everything else in this
platform can be undone by rolling back; an account created on a control
panel cannot. So:

- The remote call happens **outside** any database transaction, and the
  external id is written the moment the adapter returns it — before
  anything else, because an id we did not store is an account nobody can
  find.
- Every operation is **idempotent**, keyed on the service, so a retried job
  does not create a second account.
- **Failure is a state, not an exception.** `failed` is a place a service
  sits, with the reason recorded, until a human decides. A permanently
  failed provisioning run is not retried into oblivion; it appears in a
  queue an operator works through.

## 3. The service lifecycle

```text
pending ──> provisioning ──> active ──> suspended ──> active
                │               │          │
                │               │          └──> terminated
                ├──> failed     ├──> grace_period ──> active | terminated
                │               └──> cancel_pending ──> terminated
                └──> cancelled
```

| State | Meaning |
| --- | --- |
| `pending` | Created from a paid order, waiting to be set up. Nothing exists remotely. |
| `provisioning` | A job is talking to a provider right now. |
| `active` | Set up and working. |
| `suspended` | Turned off at the provider, recoverable. |
| `grace_period` | Past due but still running. Phase 9 drives entry; the state exists here so it has somewhere to go. |
| `cancel_pending` | The customer asked to cancel at the end of the term. |
| `terminated` | Gone at the provider. Terminal. |
| `failed` | An operation failed and a human has to look. |

Transitions are declared on the enum, the way order and invoice statuses
already are, and the one place a transition is refused is the enum.

## 4. Tables

| Table | Notes |
| --- | --- |
| `server_groups` | Name, placement strategy, region, notes |
| `servers` | Group, module key, hostname, port, username, **encrypted** token/password, capacity (max accounts), weight, region, status (active/maintenance/full/offline), health, last checked |
| `services` | Customer, order line, product (nullable, for reporting), server (nullable), module key, status, copied name and billing snapshot, next due date, external id, hostname/domain, username, **encrypted** password, configuration snapshot, suspension reason, failure reason, timestamps for provisioned/suspended/terminated |
| `service_options` | The configured options as chosen, copied — group name, label, value |
| `service_events` | Append-only: what was attempted, by whom, the outcome and a sanitised error |

`products` gains `provisioning_module`, `server_group_id`, `auto_setup`
(none / on_order / on_payment) and `welcome_template` (reserved for
Phase 8). A product with no module is provisioned by hand, which is a real
answer rather than a missing one.

## 5. The provisioning contract

```php
interface ProvisioningModule
{
    public function key(): string;
    public function capabilities(): ModuleCapabilities;
    public function testConnection(Server $server): ConnectionResult;
    public function create(ProvisioningRequest $request): ProvisioningResult;
    public function suspend(ServiceReference $service, ?string $reason): ProvisioningResult;
    public function unsuspend(ServiceReference $service): ProvisioningResult;
    public function terminate(ServiceReference $service): ProvisioningResult;
    public function changePackage(ServiceReference $service, PackageChange $change): ProvisioningResult;
    public function sync(ServiceReference $service): SyncResult;
}
```

Rules the contract's docblock states, each with a test:

1. An adapter never writes to the database. It takes a value object and
   returns one.
2. Every call carries a timeout, bounded retries with backoff and a
   correlation id.
3. A result is `succeeded`, `failed` or **`already_done`** — the third is
   what makes a retry safe, because "this account already exists" is a
   success from the platform's point of view.
4. Nothing an adapter returns is trusted as a secret store: credentials go
   back in a value object and are encrypted at rest by the caller.

Two adapters:

- **`ManualModule`** — the operator sets it up. A real module rather than a
  special case, so "an operator did it by hand" and "cPanel did it" travel
  the same path and produce the same events.
- **`CpanelModule`** — WHM API 1 over HTTPS with a token, the first real
  hosting adapter. Tested against faked HTTP, exactly as the Stripe adapter
  was, and carrying the same honest warning: code proven, integration not.

## 6. Placement

A `PlacementStrategy` contract with four implementations — least accounts,
weighted, capacity aware, region aware — and `manual`, which is the absence
of a strategy rather than an implementation of one. The strategy is a
property of the **server group**, so an operator changes placement without
touching a product.

Rules:

- A server at or over capacity is never chosen, whatever the strategy says.
- A server in maintenance or offline is never chosen.
- If nothing can be chosen, the service goes to `failed` with a reason an
  operator can act on — never to a node that cannot hold it.

## 7. The queue flow

```text
invoice paid ──> order paid ──> CreateServicesForOrder
                                      │
                                      ├──> service (pending)
                                      └──> ProvisionService job
                                                │
                                        place ──┤
                                                ├──> adapter.create()
                                                ├──> save external id + credentials
                                                └──> active | failed
```

Every job: `ShouldQueue`, `ShouldBeUnique` on the service id, bounded
`$tries` with exponential `backoff()`, the correlation id carried from the
request that started it, and a `failed()` hook that moves the service to
`failed` with a sanitised reason rather than leaving it in `provisioning`
forever.

Suspend, unsuspend, terminate and sync are the same shape.

## 8. Permissions added

| Permission | Notes |
| --- | --- |
| `services.view` | |
| `services.manage` | Create, edit, change package |
| `services.provision` | Run a provisioning operation |
| `services.suspend` | |
| `services.terminate` | high risk — it destroys an account |
| `infrastructure.view` | |
| `infrastructure.manage` | high risk — holds credentials |
| `portal.services.view` | Customer scope |

## 9. Screens

**Admin.** Services: all, pending provisioning, suspended, failed
operations. Service detail: the copied configuration, the server, the
external id, credentials behind a reveal, the event log, and the actions the
module actually supports. Infrastructure: server groups with their strategy,
servers with capacity and health, a connection test that says what happened.

**Client.** The Services row the navigation has been deliberately missing:
a list, and an overview with what they bought, where it runs, its hostname
and its next due date. Management actions land with the modules that support
them; the tabs the handoff lists are a Phase 12 extension point, not a
promise made here.

## 10. Testing

- The full chain, once, end to end: an order is paid, a service appears,
  the job runs against a fake module, the external id is stored and the
  service is active.
- Idempotency: the job runs twice and one account exists. The adapter
  answers `already_done` and the service is still active.
- Failure: the adapter throws, the service is `failed` with a reason, and
  the reason contains no credential.
- Placement: each strategy picks what it should, a full server is skipped,
  and no capacity at all fails loudly.
- Isolation: every service query is scoped, and a customer cannot see or
  act on somebody else's service.
- Secrets: a server's password and a service's password are never in a
  payload, a log line, an event record or an exception message.

## 11. Order of work

1. Enum, tables, models, factories, permissions.
2. The contract and its value objects; the manual module; the fake module
   the tests use.
3. Placement strategies.
4. `CreateServicesForOrder`, wired to the order reaching `paid`.
5. The jobs, with retries and the failure state.
6. The cPanel adapter.
7. Admin screens; then the client screens.
8. Translations, ADRs, result document.
