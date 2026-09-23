# Phase 17 — Production Hardening Result

Status: complete
Date: 2026-09-24
Plan: `phase-17-plan.md`
Handoff: §10, §11, §17, §20, §21, §22 Phase 17

**This is the last phase on the V2 addendum's roadmap.** Phases 0 to 17 are
complete.

---

## 1. What shipped

- **`AddSecurityHeaders`** — an enforced CSP plus the five headers that go with
  it, as **global** middleware.
- **`SafeUrl`** — SSRF protection, checked at the call site, wired into both
  outbound webhook paths.
- **`RequireRecentAuthentication`** and `ConfirmPasswordController` — a password,
  again, before eight irreversible actions. This closes the gap `AppConfirm` has
  documented since Phase 11.
- **`RequireInstallationOwner`** — who you have to be, as middleware, so that
  authorization runs *before* the password challenge.
- **`SecurityHardeningTest`** (19), **`ConcurrencyGuardsTest`** (9),
  **`accessibility.test.ts`** (13).
- **`docs/operations/backup-and-restore.md`**,
  **`docs/operations/runbooks.md`**, **`docs/operations/release-checklist.md`**.

Four of §20's eleven requirements had already shipped — webhook replay
protection, API scopes and idempotency, organization-isolation authorization
tests, secret redaction — and this phase did not rebuild them.

## 2. The decisions

**The CSP is enforced, not report-only.** A report-only policy is a policy
nobody fixes: the reports go somewhere, nobody reads them, and the header sits in
production for two years doing nothing. What has to be allowed is allowed by name
and each exception has its reason in the class: `style-src 'unsafe-inline'`
because Vue's scoped styles and a brand's colour overrides are both inline;
Google Fonts because a theme author may name a webfont (ADR 0037); `img-src
data:` because a brand logo and a two-factor QR code are both data URIs.
`connect-src 'self'` is the line that earns the most — the realistic attack on an
admin panel is not injection but a dependency that is already there talking
somewhere it should not.

**`script-src 'self'` with no exceptions**, which was only possible because
Inertia's page object is a `data-` attribute and the translations block is
`type="application/json"`. Two decisions made in earlier phases for other reasons
paid for this one.

**The headers are global middleware, and a test is why.** They were on the web
group first. A route-model binding failure throws inside the router's pipeline, so
the response is rendered by the exception handler *outside* every route
middleware — which meant a 404 and a 500 went out with no CSP, and an error page
is exactly where an unescaped value ends up. Global middleware wraps the router
and sees the rendered exception on the way back out.

**SSRF is checked immediately before the request, not when a URL is saved.**
Validating at save time proves nothing: DNS can change in between, and that is
the entire technique. So it is a guard at the call site and the class says so,
because the temptation is to put it in a form request and feel finished.

**An unresolvable host is allowed through, and that is the opposite of the
obvious answer.** A test found it: refusing it would record a webhook delivery as
*unsafe*, which `DeliverWebhookNow` treats as permanent — so a customer's
ten-minute DNS outage would silently end their deliveries forever. Letting it
through means the HTTP client fails to connect, which is retryable and correct.
Nothing is lost: a host that does not resolve cannot be connected to either.

**No refusal echoes the URL.** The URL is the thing somebody is trying to
smuggle, and a message quoting it would put
`http://169.254.169.254/latest/meta-data/iam/` into a log line, a flash message
and eventually a screenshot in a support ticket. A test asserts it.

**Recent authentication is a window, not a field on every form.** Fifteen
minutes. An operator confirming once per quarter-hour follows the rule; one
confirming per action works around it, and the way they work around it is a
password in a text file — which is worse than not having the check.

**Authorization runs before the challenge, and getting that wrong is what
produced `RequireInstallationOwner`.** With the owner check inside the
controller, `auth.recent` ran first: a staff member who may not touch the Licence
screen was asked to confirm their password and *then* refused. Rude, and a small
oracle. Two existing tests caught it by expecting 403 and getting 302. The
controllers keep their own check as well — a route added without the middleware
would otherwise be an open one, and two cheap checks are worth less than one
forgotten.

**The confirmation form is rate limited per account and every failure is
audited.** It is a password oracle against a session somebody may already have
stolen — a better brute-force target than the sign-in screen, because it leaks
the account name for free. And somebody failing it is either an operator who
mistyped or a session that is not theirs, which is what an incident review has to
be able to find.

**Concurrency is tested at the guard, not by racing threads.** Pest cannot
reliably run two requests at once against MariaDB, and a flaky test is worse than
no test because it gets retried until it passes and then nobody believes it. What
is asserted is that the guard is a database constraint rather than a
check-then-act in PHP: the unique index refuses the second gateway event and the
second import mapping, the idempotency table has a unique index at all, the
reseller ledger's balances are strictly sequential, and `lockForUpdate` appears
*after* `DB::transaction` in the source — a lock taken outside a transaction is
released before the write it was protecting.

**`increment()` is safe and the first version of that test said otherwise.** It
grepped for `increment(` across billing and a promotion's usage count failed it.
`increment()` compiles to `set x = x + 1` and is atomic; the lost update is
read-modify-write in PHP. So the rule became narrower and sharper: a money column
that caches rows is recomputed from the rows, and a counter uses the atomic
increment — and both are now pinned, because somebody will one day "tidy" the
second into `$promotion->usage_count++` and lose redemptions under load.

**Accessibility is tests, not a document.** Nobody audits forty screens twice a
year; a failing test is read the day it breaks. Thirteen of them, over the
primitives: every icon-only control has an accessible name and an
`aria-expanded` that moves, every field's label is tied to its input, an error is
`aria-invalid` plus `aria-describedby` rather than a red line, a danger alert
interrupts and nothing else does, a loading table announces once rather than
eighty times.

**`info` and `healthy` shared a glyph**, which meant two states were identical in
greyscale — exactly the failure "status is never colour alone" exists to prevent,
in the component that enforces the rule. An accessibility test found it rather
than an eye. `info` is now `◐`, which also reads as "in progress", which is what
this product marks with it.

**There is no backup button and there will not be one.** Handoff §17 says not to
advertise one unless it can produce a consistent recoverable snapshot, and a PHP
process cannot: `--single-transaction` needs database privileges the application
deliberately lacks, and the database is not the whole system. A button producing
an inconsistent snapshot would be worse than none, because somebody would rely on
it. What ships is the boundary documentation, a restore order where every step
says what doing it later breaks, and a verification procedure — because a backup
nobody has restored is a hypothesis.

## 3. What is written down rather than built

- **Load testing.** Needs a target environment and a traffic model, neither of
  which lives in a repository.
- **A penetration test.** An engagement, not a commit.
- **Dependency scanning.** Belongs in CI configuration; the release checklist
  says what to run.
- **File uploads.** This installation still has no upload endpoint. The rules
  would have been a seam with nothing behind it, and a seam nobody has used is a
  seam that is wrong. The release checklist names it for the phase that adds one.

## 4. Standing limitations, restated because they have not changed

- **The provider adapters have never talked to their real providers.** Stripe,
  cPanel and Namecheap are tested against faked HTTP, which proves the code and
  not the integration. The owner deferred them deliberately, and the release
  checklist says the first real deployment must treat each as unproven.
- **The vendor's licence control plane does not exist here** and cannot (ADR
  0013). `docs/licensing/api.md` is the contract.
- **`WhmcsImportSource` has never read a real WHMCS database.** Its column list
  is verified against the live schema before anything is written, which turns a
  wrong guess into one legible failure instead of a half-finished import.
- **No screen has been driven in a browser by the author.** Visual changes are
  verified against the built stylesheet and by the tests above; contrast and 200%
  zoom remain in the design-system document as things for a person to check.

## 5. Gates

Pint, Rector, PHPStan level 8, Pest on MariaDB, ESLint, Prettier, vue-tsc,
Vitest, Vite build, `platform:openapi --check` — all green.
**1279 Pest tests, 81 Vitest tests.**
