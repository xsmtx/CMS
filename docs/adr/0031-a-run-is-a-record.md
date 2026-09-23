# 0031 — A run is a record, and time is not a trigger

Status: accepted
Date: 2026-09-23

## Context

By Phase 9 the platform can do everything a person can ask it to and
nothing on its own. An invoice is raised because an order was placed. A
service is suspended because an operator clicked suspend. A domain expires
and the row keeps saying `active` until somebody notices.

The obvious way to fix that is a `Schedule::call()` per task and a log line
per action. It is smaller than what follows and it is wrong in three ways
that only appear in production.

**It cannot answer the operator's question.** "Why was this customer
suspended on Tuesday?" has no answer once the log file has rotated. Worse:
"why was this customer *not* suspended?" has no answer at all, because
nothing records a decision not to act.

**It is not safe to run twice.** "Suspend everything more than seven days
overdue" is safe. "Send the seven-day reminder to everything whose invoice
is seven days old" runs at 03:00 and again when somebody reruns the
scheduler by hand, and the customer gets two.

**It hides a partial failure.** Sixty renewals, the fortieth throws, the
remaining twenty never happen, and the task looks like it ran.

## Decision

Three rules. Every automation task obeys all three, and each is tested for
rather than trusted.

### A run asks a question about state, never about elapsed time

"Which services are past due and not yet suspended" is a question about
rows. "Which services became overdue today" is a question about the clock,
and the clock is not authoritative — a server switched off for two days
would skip two days of work permanently.

Where a step must not repeat, **the state itself records that it
happened**, and the guard is a column or a row rather than a date
calculation:

| Task | Guard |
| --- | --- |
| Renewals | `services.renewal_invoiced_through` |
| Dunning | a row in `invoice_dunning_steps`, with a unique index |
| Overdue | the invoice's own status |
| Domain expiry | `expiry_notified_days` plus `expiry_notified_for` |
| Retries | `operations.next_attempt_at` and the attempt counter |

The domain-expiry guard is two columns rather than one on purpose: a domain
whose expiry moves gets a fresh set of notices without anything anywhere
having to remember to reset a flag.

### Every run is a record

`automation_runs` holds the task, when it started and finished, how many
rows it examined, changed, skipped and failed, the correlation id and a
sanitised error. **A run that did nothing is still written**, because
"examined 60, changed 0" is the answer to "why was nobody suspended last
night" and a log file that rotated is not.

The record is written **before** the task starts, so a run that dies half
way — killed process, out of memory — leaves a row stuck in `running`
rather than no evidence that anything was attempted. A stuck row is a
question an operator can ask; silence is not.

Detail rows are written for what a run **changed or failed on**, never for
what it skipped. A nightly sweep of ten thousand services that changes four
of them writes four rows; the counts cover the rest.

`RecordedRun` wraps the task rather than being mixed into it, so there is
exactly one path from "run this" to a row, and a task cannot forget.

### One row failing never stops the run

Each row is its own try/catch, its own outcome and its own line in the
summary. A run that hits fifteen failures finishes, reports fifteen
failures, and leaves the other forty-five done. `Completed` therefore means
the run finished, not that every row succeeded — calling it failed would
hide the forty-five that worked. `Failed` is reserved for a run that could
not finish at all.

### Dunning is a sequence an operator edits

The days are the easy part; the shape is what differs between businesses. A
provider selling to consumers suspends on day 3; one selling to enterprises
never suspends automatically at all. So the sequence is rows — an offset in
days, an action, and for a notify step which event to raise — and **a
sequence with no suspend step is a valid configuration**, not an incomplete
one.

Late fees are deliberately excluded. A late fee is money, and money on a
frozen document ([ADR 0023](0023-issued-documents-are-frozen.md)) needs its
own decision about whether it is a new invoice or a line on the next one.
Inventing that answer inside a phase about scheduling would produce the
wrong one quickly.

## Consequences

Adding a task is: a case on `AutomationTask`, a class implementing
`AutomationRun`, an entry in `TaskRegistry`, a line in `routes/console.php`
and two tests — one that runs it twice and asserts the second run changed
nothing, one that makes a row fail and asserts the rest completed.

An operator can run any task by hand, from the command line or from the
screen, and watch what it does. That is not a convenience: a task that
behaves differently under the scheduler than under a person is a task
nobody will ever trust.

The cost is a table and a wrapper where a cron line would have done. The
table is what turns "it probably ran" into an answer.
