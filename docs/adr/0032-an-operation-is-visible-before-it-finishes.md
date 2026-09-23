# 0032 — An operation is visible before it finishes

Status: accepted
Date: 2026-09-23

## Context

Since Phase 6 the real work of this platform has happened in queued jobs:
provisioning an account, registering a domain, renewing one, terminating a
service. They are the operations that cost money and that customers notice.

A queued job is a poor record of any of them.

- **It is opaque while it runs.** "Is my server being set up?" has no
  answer except "the job has not failed yet".
- **It is gone when it succeeds.** Nothing says a provisioning attempt
  happened, only that a service is now active.
- **When it fails it becomes a `failed_jobs` row** with a serialised
  payload and a stack trace, which is a developer's artefact and not an
  operator's.
- **When it never runs, it leaves nothing at all.** Redis down, a worker
  that will not boot, a supervisor watching the wrong queue — Phase 6 hit
  the last of those and the symptom was silence.

The handoff (§8) asks for a Background Operations Center. The reason is
worth writing down rather than implementing from the bullet list.

## Decision

**A long-running business operation is a record of its own. The queue is
how it gets worked.**

`operations` carries what the handoff lists: type, actor, target, state,
attempt, progress, correlation id, timestamps, a sanitised error, the next
retry time and a manual-intervention flag.

**The row is written before the job is dispatched.** `WatchedDispatch`
opens the operation, then hands the job to the queue with the operation's
id on it. An operation that never reaches a worker is therefore still
visible, as a `pending` row that is still pending an hour later. That is
the whole argument for the table, and it is the one thing a queue
inspection cannot give.

**`ManualIntervention` is a first-class end state**, not a failed operation
with a note. A transfer the losing registrar rejected, a provisioning run
whose server no longer exists, a domain with no registrar configured: no
amount of retrying will fix any of them, and a retry counter that hides
that wastes a week before anybody looks.

**Retries are bounded, and the backoff is exponential and capped.** When
the attempts run out the operation becomes `failed` rather than retrying
forever. A provider refusing a request for a reason that will not change is
not improved by asking it nine hundred more times.

**A retry does not reset the attempt counter.** An operator retrying
something that has already failed three times should be able to see that it
is on its fourth.

**Resolving does not delete the row or clear the error.** An operation that
went wrong and was fixed by hand is exactly the history somebody wants next
quarter.

The retry sweep is an automation task like any other
([ADR 0031](0031-a-run-is-a-record.md)), asking "which operations are
retrying, due, and have attempts left". A delayed job is a promise held by
Redis; a row with a date in the past survives a flush.

## Consequences

Four existing jobs implement `ReportsToOperations` and report their state.
A job that does not implement it is dispatched unwatched rather than
refused — not every queued thing is a business operation, and a mail send
does not belong on that screen.

Their idempotence ([ADR 0026](0026-provisioning-is-idempotent-and-failure-is-a-state.md))
is what makes the retry button safe. Without it the Operations Center would
be a way to provision a second account by clicking twice.

Two jobs are deliberately not re-dispatched by the automatic sweep: a
service action carries a reason and a domain action carries nameservers or
a flag, and neither argument is on the operation row. Reconstructing them
from here would be guessing. An operator retries those from the service or
domain screen, where the arguments are in front of them.

The cost is a row per operation and a state machine to keep honest. The
alternative is an operator reading Horizon and inferring.
