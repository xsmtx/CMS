# Phase 9 — Automation and Operations Result

Status: complete
Date: 2026-09-23
Plan: `phase-9-plan.md`
Next phase: Phase 10 (Public API + Developer Platform) — **not started**

---

## 1. Results

| Gate | Command | Result |
| --- | --- | --- |
| Formatting (PHP) | `vendor/bin/pint --test` | pass |
| Idiom drift | `vendor/bin/rector process --dry-run` | pass, no changes |
| Static analysis | `vendor/bin/phpstan analyse` level 8 | pass, 0 errors |
| Tests | `vendor/bin/pest` | **817 passed, 2893 assertions**, 0 failed |
| Lint (TS/Vue) | `npx eslint .` | pass |
| Formatting (front end) | `npx prettier --check .` | pass |
| Type check | `vue-tsc --noEmit` | pass |
| Front-end tests | `vitest run` | 17 passed |
| Build | `vite build` | pass |
| Migrations | 21 migrations on MariaDB 11.8, rolled back and re-applied | clean |

Phase 8 finished at 774 tests; this phase adds 43.

## 2. The decisions this phase turns on

**A run is a record, and time is not a trigger**
([ADR 0031](../adr/0031-a-run-is-a-record.md)).

The cheap version of this phase is a `Schedule::call()` per task and a log
line per action. It cannot answer "why was this customer suspended on
Tuesday", it cannot answer the harder question "why was this customer *not*
suspended", it sends two reminders when somebody reruns the scheduler by
hand, and it hides the forty renewals that never happened because the
thirty-ninth threw.

So every task asks a question about rows rather than about the clock, every
run writes a record — including a run that changed nothing — and one row
failing never stops the sweep. The guard against repeating is always state:
a column on the service, a row with a unique index, the invoice's own
status. A scheduler that was down for three days catches up instead of
skipping three days permanently.

**An operation is visible before it finishes**
([ADR 0032](../adr/0032-an-operation-is-visible-before-it-finishes.md)).

A queued job is opaque while it runs, gone when it succeeds, a stack trace
when it fails, and *nothing at all* when it never reaches a worker — which
is the failure Phase 6 actually hit. So the operation row is written before
the job is dispatched, and `manual_intervention` is a real end state rather
than a failed operation with a note.

## 3. Problems found

**A `static fn` reaching for `$this`.** The dunning screen built each row
inside `->map(static fn (DunningStep $step) => [... $this->whenLabel(...)])`,
which is a fatal the moment the page loads. Every test passed, because the
tests posted and deleted steps and none of them opened the screen. Rector
found it — as "unused private method", since the only call site was
unreachable — and the fix was the closure plus a test that loads the page.

**The test for suspension was wrong, and it was worth being wrong.** A
service with `module => null` cannot be suspended: `RunServiceOperation`
refuses, correctly, because there is no adapter to call. The test had
invented that service. The decision that came out of it is deliberate and
now documented: a dunning step whose suspension fails does **not** record
the step as run, so the next sweep tries again and the failure is on the
run's detail rather than silently swallowed. A platform that marked a
service suspended without suspending anything would be lying to its own
operator.

**The renewal sweep and dunning did not compose.** `servicesFor()` found
a service through `invoice.order_id`, which is right for a first invoice
and useless for a renewal one — the renewal sweep raises invoices with no
order at all. A suspend step would therefore have found nothing on exactly
the invoices dunning exists for. Caught while writing the carried-risks
section of this document, which is the argument for writing that section
honestly. The line's `subject_id` is preferred now, with the order as a
fallback.

**Four `list<T>` return types PHPStan would not accept.**
`->get()->all()` is `array<int, T>`, and `->values()->all()` still is.
`array_values(...)` is the one that types. Mechanical, but it is the sort
of thing that gets suppressed rather than fixed.

**Three private copies of "who sells to this organization".**
`AllocateNumber` had one, `SellerDepartments` had one, and dunning needed a
third. Each was a boundary escape that is only safe because of the
narrowing that follows it, and three copies is three chances to write the
escape without the narrowing. Extracted to `ResolveSeller`.

**The Support role again.** Phase 8 found it holding none of the support
permissions; this phase added `operations.view` and `automation.view` to
it at the same time as declaring them, which is the habit that note in
`CLAUDE.md` was written to create.

**A permission group with no label.** `PermissionRegistryTest` refuses a
permission whose group has no translation, which caught the new
`automation` group before it reached a screen as a blank heading.

## 4. What was built

### Seven tasks

| Task | The question it asks |
| --- | --- |
| `renewals` | services and domains due within the lead time, not invoiced through that date |
| `dunning` | owed invoices with a sequence step that has not run against them |
| `overdue` | unpaid invoices with a due date in the past |
| `domain-expiry` | domains inside a notice window they have not been told about |
| `retries` | operations that are retrying, due, and have attempts left |
| `sync` | services and domains not synced within the window, oldest first |
| `cleanup` | expired carts, read notifications, expired tokens, old run detail |

Each is an `AutomationRun`, resolved through `TaskRegistry`, runnable as
`php artisan platform:run <task>` or from the screen, and scheduled in
`routes/console.php` with `withoutOverlapping()` and `onOneServer()` —
because two application containers must not both invoice the same renewal.

The renewal sweep produces **one invoice per customer and currency**: a
customer with four services renewing the same week gets one document, which
is what a bank transfer can actually pay. Two currencies cannot share an
invoice, because money is never converted in this platform.

**Payment advances the date, not issuing.** A renewal invoice that was
raised is a request; one that was paid is the next term being bought.
`AdvanceRenewalDates` listens for `PaymentReceived`, reads the line's
`subject_type` and `period_end`, and moves the service or domain forward.
Advancing on issue would mean an unpaid renewal silently extends the
service, which is the bug that makes dunning pointless.

### The Background Operations Center

`operations`, the `Operations` recorder that owns every state change,
`WatchedDispatch` which opens the row before dispatching, and the four
existing jobs reporting into it. The screen opens on what needs attention
rather than on everything, because an operator who has to filter before
they can see a problem will open it less often than they should.

### System health

Seven checks — database, cache, queue depth per named queue, failed jobs,
scheduler heartbeat, mail, servers — each returning ok, **degraded** or
failing. The middle state earns its place: a queue with a thousand jobs on
it is nothing broken and everything about to be late.

No check returns a configuration value, and a test asserts it. A health
page that proves the database is reachable by printing its DSN has
published a password to whoever is looking over the operator's shoulder.

The scheduler heartbeat is the only check that reports on an absence, and
it is in a table rather than the cache: a heartbeat that vanishes when
Redis restarts cries wolf after every deploy, and an operator who has
dismissed three false alarms will dismiss the real one.

### Maintenance mode

An operator-facing switch with a message, an optional window, staff bypass
and a 503 through the standard error envelope. Not `php artisan down`,
which takes the admin panel down with everything else — including the
screen needed to turn it back on. A window that has passed turns itself
off, because a maintenance banner nobody removed is how a storefront stays
shut for a week.

## 5. Files

```text
app/Domain/Automation/     AutomationTask, RunStatus, ItemOutcome,
                           DunningAction, RunSummary, RunItem,
                           Contracts/AutomationRun
app/Domain/Operations/     OperationState, OperationType,
                           Contracts/ReportsToOperations
app/Domain/Health/         HealthState, HealthReport, Contracts/HealthCheck
app/Application/Automation/
                           RecordedRun, TaskRegistry,
                           Runs/{GenerateRenewalInvoices,RunDunningSequence,
                           MarkInvoicesOverdue,NotifyExpiringDomains,
                           RetryFailedOperations,SyncWithProviders,
                           CleanUpExpiredRecords},
                           Listeners/AdvanceRenewalDates
app/Application/Operations/ Operations, WatchedDispatch
app/Application/Health/    HealthChecks, MaintenanceMode
app/Application/Shared/    ResolveSeller
app/Infrastructure/Automation/Models/
                           AutomationRunRecord, AutomationRunItemRecord,
                           DunningStep, InvoiceDunningStep
app/Infrastructure/Operations/
                           Models/Operation, Concerns/RecordsOperation
app/Infrastructure/Health/Checks/
                           Database, Cache, Queue, FailedJobs, Scheduler,
                           Mail, Provider
app/Infrastructure/Platform/Models/PlatformState
app/Console/Commands/      RunAutomationTaskCommand,
                           RecordSchedulerHeartbeatCommand
app/Http/                  Controllers/Admin/{Automation,Operation,Health},
                           Middleware/EnforceMaintenanceMode,
                           Requests/Automation/
app/Support/Errors/        MaintenanceException
app/Providers/             AutomationServiceProvider
database/migrations/       automation and operations tables (6, plus three
                           column additions)
resources/js/Pages/        Admin/Automation/{Index,Dunning},
                           Admin/Operations/Index, Admin/Health/Index
lang/{en,tr}/              automation.php, operations.php, health.php
docs/adr/                  0031, 0032
```

## 6. Not done, and why

| Item | Detail |
| --- | --- |
| **Late fees** | Money on a frozen document ([ADR 0023](../adr/0023-issued-documents-are-frozen.md)) needs its own decision — a new invoice or a line on the next one — and inventing it inside a phase about scheduling would produce the wrong answer quickly. |
| **Automatic domain renewal at the registrar** | The expiry notice goes out and the renewal is invoiced. Calling `renew()` the moment the invoice is paid needs a decision about what happens when the registry takes the money and the charge is later refunded, which is a reconciliation question rather than a scheduling one. |
| **Applying what a sync finds** | The sweep asks providers what they think and records the answer. It does not terminate a service because a control panel had a bad minute. Acting on drift belongs to an operator looking at it, which is the Operations Center's job. |
| **Retrying service and domain actions automatically** | Their jobs carry a reason, nameservers or a flag, and none of those are on the operation row. Reconstructing them would be guessing; an operator retries from the screen where the arguments are visible. |
| **Alerting** | Health is a page somebody opens. Pushing a failing check to email, Slack or a pager is Phase 12's operations centre work, and `Notifier` is already the voice it will use. |
| **Per-organization automation settings** | Every threshold is installation-wide config. A reseller wanting its own dunning days needs the settings screen that Phase 13 brings. |
| **Object storage and license-heartbeat checks** | Both are in the handoff's list. Neither exists to check yet. |

## 7. Carried risks

| Item | Detail |
| --- | --- |
| **No task has run against a real installation's data** | Every one is proven against a test database with a handful of rows. A sweep that is correct over ten rows and unusably slow over a hundred thousand looks identical in a test suite. The batch limits on sync and retries are a guess at the right size. |
| **The renewal sweep trusts the service row** | Amounts are copied from the service, which is right, but nothing re-checks that the copied price still makes sense. A service whose price was set wrong at order time will be invoiced wrongly for years without anything noticing. |
| **The scheduler is assumed to be running** | Everything in this phase depends on one `schedule:run` container. The heartbeat notices when it stops; nothing restarts it, and nothing tells anybody except a page somebody has to open. |
| **Maintenance mode does not cover the API** | `routes/api.php` is not behind the middleware, because Phase 10 has not defined what an API client should see. An integrator will keep working while the storefront is closed. |
| **A run's error is redacted, not proven safe** | `SecretRedactor` catches the fragments it is configured with. A provider that invents a new parameter name puts it in a database column that an operator reads on a screen. |

## 8. Exact next recommended task

**Phase 10 — Public API and Developer Platform.**

1. `/api/v1` over the same application use cases the screens call, with
   Sanctum tokens and scopes.
2. Rate limits, idempotency keys on every write, and the error envelope
   that already exists.
3. OpenAPI generated from the routes rather than written by hand, an API
   activity log, and outbound webhook management on top of the
   `WebhookChannel` Phase 8 left.

Nothing from this phase is left half-finished for it to pick up.
