# Runbooks

For the failures that actually happen, in the order they happen in. Each one is
written to be read at three in the morning by somebody who did not build this.

Every runbook starts with **how to tell**, because the commonest mistake during an
incident is fixing the wrong thing.

---

## The queue has stopped

**How to tell.** `/admin/health` reports the queue as failing or degraded. The
Module Queue at `/admin/operations` has rows stuck in `pending`. Customers are
paying and nothing is provisioning.

**Why it matters more than it looks.** Provisioning, webhooks, notifications and
the licence heartbeat are all jobs. A stopped queue is a platform that takes
money and does nothing, and the storefront looks fine throughout.

**What to do.**

1. `php artisan horizon:status`. If it is not running, `supervisorctl status`
   (or the systemd unit) — Horizon does not restart itself.
2. Check Redis is up: `redis-cli ping`. A queue with no Redis is a queue that
   accepts nothing, and Horizon will be dead rather than idle.
3. `php artisan queue:failed`. Jobs that failed are recoverable;
   `queue:retry all` after the cause is fixed.
4. **Check every queue is in a supervisor.** `config/horizon.php` lists them, and
   a job dispatched to a queue no supervisor consumes sits in Redis forever with
   no error anywhere. The queues are `provisioning`, `domains`, `webhooks`,
   `imports` and `default`; a new one added without a supervisor entry is the
   silent failure this platform has documented since Phase 7.
5. Restart with `horizon:terminate` rather than `kill`: it lets the running job
   finish, and a provisioning job killed halfway leaves a service in
   `provisioning` with an account already created.

---

## The scheduler has stopped

**How to tell.** `/admin/health` reports the scheduler heartbeat as stale.
Nothing has been invoiced, no dunning step has run, no domain expiry notice has
gone out.

**Why the heartbeat is trustworthy.** It lives in `platform_state`, not the
cache, precisely so that it survives a Redis restart — an operator who has seen
three false alarms stops reading the fourth.

**What to do.**

1. Check the cron entry exists and runs as the right user:
   `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`.
2. `php artisan schedule:list` — it prints every task and when each is next due.
3. **Do not manually run the sweeps to "catch up".** Every task asks a question
   about rows rather than about the clock (ADR 0031), so the next scheduled run
   catches up by itself. Running them by hand is safe but it is also unnecessary,
   and doing it during an incident is one more thing to have got wrong.
4. If something must run now, `php artisan platform:run <task>` — it is wrapped
   in `RecordedRun`, so it appears at `/admin/automation` like any other run.

---

## Payments are being taken and invoices are not settling

**How to tell.** The gateway's own dashboard shows charges. `/admin/invoices`
still shows them unpaid. `/admin/billing/gateway-log` shows events received and
not processed, or nothing at all.

**The thing to understand before touching anything.** A redirect back from a
gateway proves nothing (ADR 0024). Only a verified webhook or a server-to-server
answer moves money, so "the customer says they paid" and "we received a webhook"
are different facts and this is a webhook problem until proven otherwise.

**What to do.**

1. `/admin/billing/gateway-log`. Events present but unprocessed means the queue
   — go to the queue runbook.
2. No events at all means the gateway cannot reach this installation. Check the
   endpoint URL in the gateway's own dashboard, and check TLS: an expired
   certificate stops webhooks silently while browsers show a warning somebody
   clicks through.
3. Events present and *refused* means the signature check failed. The secret in
   `.env` and the secret in the gateway are different — which happens after a
   restore from a backup taken before a key rotation.
4. **Never settle an invoice by editing it.** One path settles an invoice,
   `RecordPayment`, whether the money came from a webhook or an operator. Use
   Billing → Add Transaction, naming the invoice: it writes the ledger row, moves
   the invoice, advances the service and leaves an audit trail. Editing
   `paid_minor` would leave the ledger disagreeing with the invoice for good.
5. Replay the gateway's events from its dashboard once the cause is fixed. They
   are deduplicated on the provider's event id, so replaying everything is safe.

---

## A provisioning run has failed

**How to tell.** `/admin/operations` shows `failed` or `manual_intervention`
rows. The service is in `failed` with a reason.

**What the states mean.** `failed` is retryable. `manual_intervention` is the
honest answer for something no amount of retrying will fix — a server that no
longer exists, a registrar that rejected a transfer — and it is a real end state
rather than a failed operation with a note.

**What to do.**

1. Read the error on the operation row. It is already redacted, and it names the
   provider's own message.
2. `already_done` is a **success**. If the account exists on the server and the
   platform says failed, the retry is safe and will reconcile: that is what makes
   idempotent provisioning worth having (ADR 0026).
3. Retry from the screen. The attempt counter is not reset, deliberately —
   somebody retrying a thing that has failed three times should be able to see
   that it is on its fourth.
4. If it cannot succeed, resolve it rather than retrying forever. Resolving does
   not clear the error, so the record of what happened stays.
5. **Check the server before creating the account by hand.** A service in
   `failed` may still have an account at the provider; `external_id` on the
   service is written the moment a provider returns it.

---

## The licence server cannot be reached

**How to tell.** `/admin/health` reports the licence as degraded.
`/admin/licence` shows the last failure and a grace deadline.

**What to do.** Nothing urgent, and that is the design (ADR 0041). The
installation keeps working on the last known entitlements until the grace period
ends, counted from the heartbeat deadline the vendor set — a weekly heartbeat and
thirty days of grace is thirty-seven days.

1. Check outbound HTTPS from the application server. A firewall rule added on a
   Friday is the commonest cause.
2. Press **Check now** on the licence screen rather than waiting for the hourly
   task.
3. If grace does run out, the vendor mark returns and **nothing else changes**:
   no screen closes, no order is refused, no service is suspended. It is not an
   outage and it does not need a two-in-the-morning decision.

---

## An import has gone wrong

**How to tell.** `/admin/import` shows a run as failed, or completed with
failures.

**What to do.**

1. A run that **failed** could not proceed at all — the legacy database went
   away, or a domain was asked for whose parent had never been imported. The
   error says which. Nothing was half written: each row is its own transaction.
2. A run that **completed with failures** did the rest. Open the report: every
   row that did not come across is listed by name with a reason.
3. **Fix the cause and run it again.** Rows that came across are skipped on the
   mapping table, so a second run brings only the failures. This is safe and it is
   the intended workflow.
4. If the wrong thing was imported, there is no undo. `import_mappings` is the
   record of what came from where — `target_type` and `target_id` per legacy row —
   and it is what a manual clean-up is driven from.

---

## The resource graph is empty, or a resource is missing from it

**How to tell.** `/admin/resources` shows nothing, or shows fewer resources than
the installation has.

**What to do.**

1. The graph is filled by an automation task, not by events. Check
   `/admin/automation` for the **Resource graph** task: it runs hourly, and a run
   that examined rows and changed none is the normal steady state.
2. **Run it by hand** — `php artisan platform:run resources` — and read the run
   record. It writes one item per resource it created or retired, and a failure
   per resource it could not.
3. A resource that is **missing** is either terminated (a terminated service is
   retired on purpose, and the Retired count on the screen will show it) or its
   row is outside the boundary of whoever is looking.
4. A resource that is **retired and should not be** comes back on the next run:
   the projection un-retires anything it sees again. Nothing needs to be deleted.

---

## Telemetry has stopped arriving

**How to tell.** `/admin/resources/telemetry` — the **Stale** count is climbing,
or **Nothing reporting** is higher than it was. Readings do not disappear when a
source stops; they go stale where somebody can see them.

**What to do.**

1. Open `/admin/resources/adapters` and press **Check now** on the adapter. Its
   state and the message beneath it are the adapter's own answer.
2. `degraded` with a version on it means the remote was upgraded past what the
   adapter was written for. Some calls still work; that is why it is degraded
   rather than failing.
3. Check the **Telemetry** task on `/admin/automation`. It runs every five
   minutes; `php artisan platform:run telemetry` runs it now and the run record
   says how many readings were recorded and how many were refused.
4. **Refused readings are not a transport problem.** A metric this platform has
   no name for is counted and dropped on purpose. If a source is sending
   something worth keeping, it needs a `MetricKind`, which is a code change
   rather than a configuration one.
5. A resource that nothing reports on is listed at the foot of the Telemetry
   screen. Usually it is a resource no adapter was ever pointed at.

---

## An adapter is doing something it should not

**How to tell.** Anything from a device configuration changing unexpectedly to a
vendor rate-limiting the installation.

**What to do.**

1. **Switch it off first**, on `/admin/resources/adapters`. That is deliberately
   not behind the password challenge — it is the thing to do while an incident is
   running, and a guard in front of it would make the outage longer. A disabled
   adapter is asked for nothing at all, including reads.
2. **Revoke its writes** if switching it off is too blunt. A read-only adapter
   keeps the screens fed while it can change nothing.
3. `audit_logs` has the answer to "who allowed this": the action is
   `infrastructure.adapter.writes_allowed`, and the record names every capability
   that was allowed along with the reason the operator typed.
4. If the module itself is the problem, disable the module — Setup → Modules.
   The adapter's row stays, with its decisions on it, and appears under "no
   module currently provides this adapter" so that nothing is silently forgotten
   when it comes back.

---

## Somebody has lost access to the admin area

**How to tell.** Self-evident. What is not self-evident is which of three things
it is.

1. **Forgotten password.** The reset flow. There is no way to read or set
   somebody's password from this platform, deliberately.
2. **Lost their second factor.** A recovery code, used once. If those are gone
   too, another super administrator disables two-factor for them from
   `/admin/staff` — which is audited, and is why more than one super
   administrator should exist.
3. **The last super administrator is gone.** `php artisan identity:create-owner`
   on the server. This is the only path back in and it requires shell access,
   which is the point.

---

## The whole thing is down

1. **Is it the application or the infrastructure?** `curl -sI https://…/up`.
   Laravel's health endpoint answers without touching the database.
2. Database: `mysql -e 'select 1'`. Redis: `redis-cli ping`. Disk:
   `df -h` — a full disk presents as everything failing at once and is the single
   commonest cause of it.
3. `storage/logs/laravel.log`, most recent entry. Every line carries a
   correlation id; the one from the customer's report ties their request to every
   job it started.
4. **Maintenance mode is the operator's switch, not `php artisan down`.** It
   closes the storefront and the client area and leaves the admin area open,
   because the person fixing the thing has to be able to see it. Use `down` only
   when the application will not boot.
5. If it was a deploy: the migrations are reversible where practical, but rolling
   back the code without rolling back the database is how a working installation
   becomes an inconsistent one. Roll back both or neither.

---

## Before you finish

Write down what happened while it is fresh: what the first symptom was, what it
turned out to be, and what would have shown it sooner. The health page and the
audit trail are both built to answer that second question, and the answer is how
they get better.
