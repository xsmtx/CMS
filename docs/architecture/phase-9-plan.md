# Phase 9 — Automation + Operations Plan

Status: approved for implementation
Date: 2026-09-23
Scope: V2 roadmap Phase 9 — the scheduled runs that make this platform work
unattended (renewal invoices, reminders, dunning, suspension, termination,
domain expiry, retries, sync, cleanup), the Background Operations Center,
and System Health with maintenance mode.

---

## 1. Starting point

Eight phases have built machinery that only moves when somebody pushes it.
An invoice is raised because an order was placed. A service is suspended
because an operator clicked suspend. A domain expires and the row sits
there saying `active` until a human notices.

Everything needed to change that is already here:

- **`Notifier` is the voice** ([ADR 0029](../adr/0029-an-event-is-not-a-message.md)).
  `InvoiceIssued`, `PaymentFailed`, `ServiceSuspended`, `ServiceTerminated`
  and `DomainExpiring` exist, are transactional, and have nothing raising
  them on a schedule.
- **The questions are already written.** `Service::next_due_on`,
  `Invoice::owed()`, `Domain::expiringWithin()`,
  `Service::needingAttention()`.
- **Every write path is idempotent** (ADRs 0026 and 0028).
  `OperationOutcome::AlreadyDone` is what makes a retry safe, and the
  suspend/terminate/renew paths all return it.
- **Queues are named and supervised**, and `config/horizon.php` already
  knows `provisioning` and `domains`.

What is missing is the thing that decides *when*, and the record of what it
decided.

## 2. The central decision: a run is a record, and time is not a trigger

The obvious implementation is a `Schedule::call()` per task and a log line
per action. It is smaller than what follows, and it is wrong in three ways
that only show up in production:

**It cannot answer the operator's question.** "Why was this customer
suspended on Tuesday?" has no answer if the only artefact is a log file
that rotated. Worse: "why was this customer *not* suspended?" has no answer
at all, because nothing records a decision not to act.

**It is not safe to run twice.** A task that says "suspend everything more
than seven days overdue" is safe. A task that says "send the seven-day
reminder to everything whose invoice is seven days old" runs once at 03:00
and again when somebody reruns the scheduler by hand, and the customer gets
two of them.

**It hides a partial failure.** Sixty renewals, the fortieth throws, the
remaining twenty never happen, and the task looks like it ran.

So, three rules, and every run in this phase obeys all three:

**A run asks a question about state, never about elapsed time.** "Which
services are past due and not yet suspended" is a question about rows.
"Which services became overdue today" is a question about the clock, and
the clock is not authoritative — a server that was down for two days would
skip a day's work permanently. Where a step must not repeat, the row itself
records that it happened: a reminder writes the step it sent, and the
question becomes "which owed invoices have not had step 3 sent".

**Every run is a record.** `automation_runs` holds the task, when it
started and finished, how many rows it examined, changed, skipped and
failed, the correlation id, and a sanitised error. An operator reads the
run history rather than a log file, and "examined 60, changed 0, skipped
60" is a legible answer to "why did nothing happen".

**One row failing never stops the run.** Each row is its own try/catch, its
own outcome and its own line in the run's detail. A run that hits fifteen
failures finishes, reports fifteen failures, and leaves the other
forty-five done.

ADR 0031 records this.

## 3. The second decision: an operation is visible before it is finished

The handoff (§8) asks for a Background Operations Center, and the reason is
worth stating rather than implementing from the bullet list.

A queued job is a transport detail. It is opaque while running, it is gone
when it succeeds, and when it fails it becomes a row in `failed_jobs` with
a serialised closure and a stack trace — which is a developer's artefact,
not an operator's.

**A long-running business operation is a record of its own, and the queue
is how it gets worked.** `operations` carries what the handoff lists: type,
actor, target, state, attempt, progress, correlation id, timestamps, a
sanitised error, the next retry time and a manual-intervention flag. It is
written *before* the job is dispatched, so an operation that never reaches
a worker is still visible.

The states are the handoff's: Pending, Running, Retrying, Failed, Manual
Intervention, Completed. `ManualIntervention` is the one that earns its
keep — it is the honest answer for a domain transfer the losing registrar
rejected, and it is what stops a retry loop from hiding a problem that no
amount of retrying will fix.

Provisioning and domain jobs from Phases 6 and 7 are adapted to write
through it. Their idempotence is what makes the retry button safe.

ADR 0032 records this.

## 4. Dunning is a sequence an operator edits, not a number in the code

WHMCS hard-codes "first reminder, second reminder, suspend, terminate" and
hides the days in a settings page. The days are the easy part; the shape is
what differs between businesses. A hosting provider selling to consumers
suspends on day 3. One selling to enterprises never suspends automatically
at all.

So the sequence is rows: an offset in days (negative before the due date,
positive after), an action, and — for a notify step — which event to raise.
Actions: `notify`, `suspend`, `terminate`. A sequence with no suspend step
is a valid configuration, and an installation that wants a human decision
before every suspension expresses it by not having that step.

Each invoice records which steps have run against it, which is what makes
the sequence safe to re-run.

**Late fees are deliberately not in this phase.** A late fee is money, and
money that appears on a frozen document is a credit note's worth of
complexity ([ADR 0023](../adr/0023-issued-documents-are-frozen.md)). It
needs its own decision about whether it is a new invoice or a line on the
next one, and inventing that in a phase about scheduling would produce the
wrong answer quickly.

## 5. What gets built

### Automation runs

| Task | Question it asks | What it does |
| --- | --- | --- |
| `renewals` | services and domains due within the lead time, not already invoiced for that period | one draft-then-issued invoice per customer and currency |
| `dunning` | owed invoices with a sequence step not yet run | notify, suspend or terminate, per the step |
| `overdue` | issued invoices past `due_on` still unpaid | move to `overdue` |
| `domain-expiry` | domains expiring within the notice window | raise `DomainExpiring`; renew where auto-renew is on |
| `retries` | operations in `retrying` whose next attempt is due | re-dispatch, bounded |
| `sync` | services and domains not synced within the window | ask the provider what it thinks |
| `cleanup` | delivery rows, abandoned carts and expired tokens past their retention | delete |

Each is an Application class with a `handle(): RunSummary`, a console
command that wraps it in a recorded run, and a schedule entry. Each is
runnable by hand from the command line and from the admin screen, because
"run it now and watch" is how an operator learns to trust it.

### Background Operations Center

The `operations` table, an `Operations` recorder, adaptation of the four
existing jobs, and the admin screen: a filterable list, a detail with the
attempt history and the sanitised error, a retry button and a "mark
resolved" for the manual-intervention ones.

### System Health

A `HealthCheck` contract and the checks the handoff lists that can be
answered honestly here: application version and runtime, database, Redis,
queue depth, failed jobs, scheduler heartbeat, mail configuration and
provider connections. Each returns ok / degraded / failing with a
one-sentence reason. **No environment values are ever returned** — a health
page that prints a DSN to prove the database is configured has published a
password.

The scheduler heartbeat is a timestamp written by a scheduled task; the
check reports it stale after twice its interval. That is the only way to
notice a scheduler container that died, and its absence is the failure mode
nobody sees for a week.

### Maintenance mode

A message, an optional scheduled window, staff bypass, and a predictable
response shape for the API
([the error envelope](../api/errors.md), with a new `ErrorCode` member).
Laravel's own `php artisan down` stays available for deploys; this is the
operator-facing one that a storefront visitor sees and a signed-in staff
member does not.

## 6. Order of work

1. Migrations: `automation_runs`, `automation_run_items`, `operations`,
   `dunning_steps`, `invoice_dunning_steps`.
2. `RunSummary`, the run recorder, the console command base and the
   schedule.
3. The seven tasks, each with its tests, in the order of the table above.
4. Operations: recorder, job adaptation, retry.
5. Health checks and maintenance mode.
6. Admin screens: Automation (tasks + run history), Operations, Health.
7. Permissions, translations, ADRs 0031 and 0032, result document.

## 7. Definition of done for this phase

Everything in the standing list, plus:

- Every task is proven to be safe run twice in a row, by a test that runs
  it twice and asserts the second run changed nothing.
- Every task is proven to survive one row throwing, by a test that makes
  one row fail and asserts the rest completed.
- Every run writes a record, including a run that did nothing.
- No health check returns a configuration value.
- A suspension and a termination each raise their notification event, and
  the delivery log shows it.
