# Backup and restore

## There is no backup button, and there will not be one

Handoff §17 says not to advertise an in-app backup unless it can produce a
consistent recoverable snapshot. It cannot, and the reasons are worth stating
because somebody will propose it again:

- A PHP request cannot take a consistent snapshot of a live MariaDB database.
  `mysqldump --single-transaction` can, and it needs to run as a database user
  with privileges the application deliberately does not have.
- The database is not the whole system. Object storage, the environment file, the
  installed themes and modules and the generated documents are all state, and
  three of them are outside the web root.
- A button that produced an inconsistent snapshot would be **worse than no
  button**, because somebody would rely on it — and they would find out on the
  day they needed it.

So backup is the deployment's job. What this document does is say exactly what
has to be in it, and what restoring it involves.

## What has to be backed up

Five things. A backup missing any one of them is not a restorable installation.

### 1. MariaDB

Everything. There are no throwaway tables: the audit trail, the ledger and the
import mappings are all history that cannot be regenerated.

```bash
mysqldump \
  --single-transaction --quick --routines --triggers \
  --default-character-set=utf8mb4 \
  infracms | zstd -19 > infracms-$(date -u +%Y%m%dT%H%M%SZ).sql.zst
```

`--single-transaction` is what makes it consistent, and it is the whole reason
this runs outside the application. Without it a dump taken while an order is
being placed can contain the order and not its lines.

### 2. Object storage

Whatever `FILESYSTEM_DISK` points at: generated invoice and credit-note
documents, brand logos, any attachment a later phase adds. Sync it, do not dump
it — these are immutable files and a mirror is both faster and easier to verify.

### 3. The environment file

`.env`, and it is the one piece of the backup that is **itself a secret**. It
holds `APP_KEY`, the database password, the gateway keys and the licence key.

**`APP_KEY` is not optional and it is not regenerable.** Every encrypted
column in this installation — provider credentials, gateway secrets, two-factor
secrets — is encrypted with it. A database restored without the matching
`APP_KEY` is a database whose provider credentials cannot be decrypted, and
there is no recovery from that: the servers have to be reconnected by hand and
every customer's two-factor enrolment is void.

Back it up separately, encrypted, somewhere the database backup is not.

### 4. Themes and modules

`themes/` and `modules/` hold packages an operator installed and may have
edited. A theme is a package that may not execute (ADR 0037) and a module is one
that may (ADR 0038); either way the files are state, and `modules/*/database`
holds migrations whose `migrations` rows are in the database backup. Restoring
one without the other leaves a module marked enabled whose tables do not exist.

### 5. The scheduler and queue configuration

Not application state, but a restored installation with no cron entry and no
Horizon supervisor is an installation where nothing renews, nothing retries and
nothing is invoiced — and it looks like it is working.

## Restoring

In this order. Each step exists because doing it later causes a specific problem.

1. **Put the installation into maintenance mode before anything else**, or
   through `php artisan down` if the application will not boot. A storefront
   answering while the database is half restored takes orders it will lose.

2. **Restore `.env` first.** The migrations in step 4 read configuration, and
   anything that decrypts a column needs the original `APP_KEY`.

3. **Restore MariaDB into an empty database.** Not over a populated one: a
   restore into an existing schema leaves rows the dump did not contain, and the
   ones that hurt are the ledger rows.

4. **Restore `themes/`, `modules/` and object storage.** Then
   `php artisan migrate --force`, which is a no-op on a consistent pair and the
   thing that catches an inconsistent one.

5. **Clear every cache.** `config:clear`, `cache:clear`, `view:clear`,
   `route:clear`. A cached configuration from before the restore will point at
   the old database.

6. **Restart the queue workers.** Horizon holds the old code and the old
   configuration in memory; `php artisan horizon:terminate` is what makes it pick
   up the restored state.

7. **Check the health page before turning maintenance mode off.** Database,
   Redis, queue depth, scheduler heartbeat, mail, provider connections, licence.
   The scheduler heartbeat will be stale — it lives in `platform_state` and its
   last value is from before the failure — and it going green on the next minute
   is the signal the scheduler is actually running again.

8. **Then turn maintenance mode off.**

## Verifying a backup, which is the part people skip

A backup nobody has restored is a hypothesis. Quarterly, into a scratch
environment:

```bash
# A restore that has to work without touching production.
createdb infracms_restore_test
zstd -dc infracms-latest.sql.zst | mysql infracms_restore_test
```

Then, in that environment, check the four things that prove the restore is real
rather than merely present:

1. **The ledger adds up.** For a sample of customers, the sum of their
   transactions matches their invoices' `paid_minor`. A dump taken without
   `--single-transaction` fails exactly here.
2. **An encrypted column decrypts.** Open a server under Apps and Integrations.
   If the token is unreadable, `APP_KEY` did not come from the same backup.
3. **The document numbers are contiguous.** A gap means a rollback was captured
   mid-transaction.
4. **`php artisan migrate --pretend`** reports nothing to run. Anything
   outstanding means the code and the database are from different days.

## What a restore cannot recover

Stated so nobody discovers it during an incident:

- **Anything encrypted, without the original `APP_KEY`.** See above.
- **Provider-side state.** A hosting account, a domain registration and a Stripe
  charge all live somewhere else. Restoring to a point an hour ago restores this
  platform's *record* of them; the accounts created in that hour still exist on
  the servers, and the services that record them do not. Reconcile through the
  provider's own list — every imported and provisioned record keeps its
  `external_id` for exactly this.
- **Licence activations.** The installation UUID is in the database on purpose
  (Phase 14), so a restore into a second environment produces two installations
  claiming one identity, which the licence server sees. That is the intended
  behaviour and not a fault to work around.
- **Sent notifications.** `notification_deliveries` records what was sent; it
  does not un-send it.
