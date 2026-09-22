# 0027 — Contexts meet through events, not through calls

Status: accepted
Date: 2026-09-27

## Context

Phase 6 needed one thing to happen: when an order is paid, something should
go and create a hosting account.

The direct version is one line in `TransitionOrder` — call the provisioning
service. It works, and it makes ordering depend on provisioning. Then Phase
8 wants a welcome email on the same trigger, and Phase 9 wants a renewal
schedule started, and the transition method grows a list of everything the
platform does about money arriving. Ordering ends up importing half the
application, and a change to provisioning is a change to the order state
machine.

## Decision

**A fact is announced; what it means is somebody else's business.**

`TransitionOrder` dispatches `OrderPaid` — an order reaching `paid` is a
fact about ordering. Deciding that the fact means an account should be
created on a control panel is provisioning's business, and provisioning
subscribes.

Three rules keep this from becoming the usual event-bus fog:

- **Events carry identifiers, not models.** The domain layer holds no
  framework types, and a listener running later — or on another worker —
  should read the row as it is now rather than as it was when the event was
  made.
- **Listeners are registered explicitly**, in a service provider, not
  discovered from a method signature. A listener that starts creating
  accounts on somebody's servers should be visible in a file.
- **A listener does almost nothing.** `StartFulfilment` writes rows and
  dispatches jobs. The slow, failure-prone part is in the job, where it can
  be retried without replaying the row creation — and nothing is
  provisioned inside a request, because the fastest control panel is slower
  than a customer's patience and a webhook that times out gets redelivered.

The correlation id travels on the event and into the job, so the request
that took the payment and the worker that created the account appear under
one id in the log.

## Consequences

- Ordering does not know provisioning exists. Billing does not either: it
  moves the order, and the order announces itself.
- Phases 8 and 9 add listeners rather than editing `TransitionOrder`. The
  welcome email and the renewal schedule subscribe to the same fact.
- The events are a public surface. `OrderPaid` is the first; the handoff
  names more (`ServiceProvisioned`, `ServiceSuspended`, `PaymentFailed`),
  and Phase 12's module SDK will let modules subscribe to them, so their
  shape is a compatibility promise rather than an implementation detail.
- An event dispatched inside a transaction would be seen by a listener
  before the data it describes is committed. `TransitionOrder` dispatches
  after its transaction closes, and any new dispatch site has to do the
  same.
