# Release checklist

What has to be true before a build goes to a customer. Every line is here because
skipping it has a specific consequence, and the consequence is written next to
it — a checklist of bare imperatives is a checklist people tick without reading.

## The gates

All of them, on the commit that is being released.

```bash
composer check      # pint --test, rector --dry-run, phpstan, pest
npm run lint
npm run typecheck
npm run test:unit
npm run build
php artisan platform:openapi --check
```

- **`pest` runs against MariaDB**, never SQLite. The platform uses `date_format`,
  `lockForUpdate` and JSON columns; a suite that passed on SQLite would prove
  nothing about any of them.
- **`platform:openapi --check`** fails when a route was added and the document was
  not regenerated. An API document that disagrees with the routes is worse than
  none, because somebody integrates against it.

## Migrations

- [ ] **Every new migration has been run forwards and backwards on a copy of
      production-shaped data.** `migrate:rollback` on an empty database proves
      nothing; a `down()` that drops a column with a foreign key on it fails only
      when the data is there.
- [ ] **No migration is destructive without a deliberate decision.** Dropping a
      column loses data no restore short of a full one recovers.
- [ ] **Every index added matches a query that exists.** An index added
      speculatively costs write throughput forever.

## Data that cannot be regenerated

- [ ] `php artisan platform:permissions:sync` is in the deploy, **before** the
      application serves traffic. A permission declared in code and missing from
      the table denies everybody, including the owner.
- [ ] **Every new permission has a label and a description in `lang/en` and
      `lang/tr`.** `AccessControlTest` fails without them, and a permission with
      no label reads as its own slug on the roles screen.
- [ ] **Roles that should hold a new permission hold it.** Phase 8 shipped a
      Support role holding none of the support permissions, which made the role
      called Support unable to open a ticket.

## Queues and the scheduler

- [ ] **Every new queue is in a Horizon supervisor** (`config/horizon.php`). A
      job dispatched to a queue nothing consumes sits in Redis forever with no
      error anywhere.
- [ ] **Every new automation task is in `routes/console.php`** and in
      `AutomationTask`. The screen lists the enum; the scheduler runs the file; a
      task in one and not the other is invisible or never runs.
- [ ] `php artisan schedule:list` prints what is expected.

## Security

- [ ] **No secret in a commit.** `git log -p` for the release range, looking for
      keys. The redactor protects logs, not the repository.
- [ ] **Every new outbound URL goes through `SafeUrl`.** A configurable URL
      without it is an SSRF into the cloud metadata endpoint.
- [ ] **Every new irreversible action carries `auth.recent`.** A stolen session
      cookie passes the boundary, the permission and the policy.
- [ ] **Every new owned table carries `organization_id` and uses
      `BelongsToOrganization`.** The architecture tests check the model; only a
      person checks the migration.
- [ ] `composer audit` and `npm audit` are clean, or every finding has a written
      reason for being accepted.

## The front end

- [ ] **`npm run build` output is in the release.** An installation with no built
      assets serves a blank page and the error is in the browser console, where
      nobody looks first.
- [ ] **Every new screen has a feature test that renders it.** Phase 9 shipped a
      `static fn` reaching for `$this` in a controller's `->map()` and every test
      passed, because no test had loaded that page. Phase 11 shipped two policies
      with no `create()` method for the same reason.
- [ ] **`LazyLoadingTest` covers the new screens with more than one row.** Strict
      mode only reports a lazy load when the query returned more than one, so a
      screen that is correct with one record and throws with two passes every test
      written against a single fixture.
- [ ] **Every `t('group.key')` added to a component has its path in
      `FrontEndTranslations`.** A missing path renders the key, visibly.

## Before the announcement

- [ ] **The backup has been restored somewhere, this quarter.** See
      `backup-and-restore.md`. A backup nobody has restored is a hypothesis.
- [ ] **Maintenance mode has been tried on staging**, including that it turns
      itself off when its window passes.
- [ ] **The health page is green on staging**, with the licence check included.
- [ ] **The runbooks match the code.** A runbook naming a screen that moved is a
      runbook that wastes somebody's night.

## After the deploy, in this order

1. `php artisan migrate --force`
2. `php artisan platform:permissions:sync`
3. `config:cache`, `route:cache`, `view:cache`
4. `php artisan horizon:terminate` — the workers hold the old code in memory
   until they are told; a worker running last release's job against this
   release's database is the subtlest failure on this page.
5. Open `/admin/health`. The scheduler heartbeat will be stale for up to a
   minute; it going green is the proof the cron entry survived the deploy.
6. Open one customer, one invoice and one service. Three pages is enough to catch
   a missing eager load, and a missing eager load throws rather than degrading.

## What this checklist does not cover

- **Load testing.** It needs a target environment and a traffic model, neither of
  which lives in this repository.
- **A penetration test.** That is an engagement, not a commit.
- **The provider adapters against real providers.** Stripe, cPanel and Namecheap
  have never talked to their real services from this codebase. Their request
  shapes, retries and error handling are tested against faked HTTP, which proves
  the code and not the integration — and the first real deployment has to treat
  each one as unproven.
