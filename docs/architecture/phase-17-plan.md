# Phase 17 — Production Hardening Plan

Status: complete — see `phase-17-result.md`
Date: 2026-09-24
Previous: `phase-16-result.md`
Handoff: §10 (Security / reliability), §11 (Testing / CI), §17 (Backup /
Restore), §20 (Additional Security Requirements), §21 (Observability),
§22 Phase 17

---

## 1. What is left, honestly

Handoff §20 is a list of eleven requirements. Four of them already shipped in
earlier phases and this phase's job is not to build them again:

| Requirement | Where |
| --- | --- |
| webhook replay protection | Phase 10 — `event_id` across attempts, signed `timestamp.rawBody` |
| API scopes / idempotency | Phase 10 — ADR 0033, ADR 0034 |
| authorization tests for organization isolation | Phase 13 — `ResellerScreenAuditTest` walks the router |
| secret redaction | Phase 0 — `SecretRedactor`, extended in Phase 14 |

So the work is the other seven, plus §11's concurrency tests, §17's backup
documentation and §22's runbooks and release candidate.

## 2. What ships

1. **Security headers, including a CSP.** One middleware on the web group.
2. **SSRF protection for configurable URLs.** A guard the webhook channel and
   every other operator-supplied URL passes through.
3. **Recent authentication for destructive actions.** This closes the gap
   `AppConfirm` has been documenting since Phase 11: level 4 in §8's
   confirmation ladder is "step-up auth as policy requires", and until now the
   component said so rather than pretending.
4. **Upload rules and an antivirus hook.** With the honest observation that this
   installation has no upload endpoints yet, so what ships is the **rule set and
   the seam**, ready for the first one.
5. **Concurrency tests.** The three places where two requests racing would
   corrupt money: settling one invoice twice, an idempotency key used twice, and
   a reseller ledger written twice.
6. **An accessibility audit** over the primitives, as tests rather than as a
   document: every icon-only control has an accessible name, every field has a
   label, status is never colour alone.
7. **Backup and restore documentation** with the boundaries named, and
   deliberately **no in-app backup button** — the handoff says not to advertise
   one unless it can produce a consistent recoverable snapshot, and a PHP
   process cannot.
8. **Disaster runbooks** for the failures that actually happen.
9. **The release checklist.**

## 3. The decisions

**A CSP that is enforced, not reported.** A report-only policy is a policy
nobody fixes. So it is strict and the one thing that has to be allowed is
allowed explicitly: Vite's built assets are same-origin, the storefront's themes
are same-origin, and the only third-party origin in the product is the fonts a
theme may ask for. `unsafe-inline` for styles stays, because Vue's scoped styles
and Inertia's inline page object both need it and pretending otherwise would mean
a policy somebody disables in week two.

**SSRF is checked at request time, not at save time.** Validating a URL when an
operator types it proves nothing: DNS can change between then and the request,
which is the whole technique. So the guard runs immediately before the call, on
the resolved address, and a redirect is never followed — which the webhook
channel already does for a different reason.

**Recent authentication is a window, not a second password field.** An operator
confirming a password once per fifteen minutes is a rule people follow; one
confirming it per action is a rule people work around by keeping a password in a
text file. High-risk permissions are already declared on `PermissionDefinition`,
so the set of actions that need it is the set that already exists — nothing new
to maintain.

**No in-app backup button.** Handoff §17 is explicit and it is right: a PHP
process cannot produce a consistent snapshot of a live MariaDB database plus
object storage plus the environment, and a button that produced an inconsistent
one would be worse than no button, because somebody would rely on it. What ships
is the boundary documentation and a restore runbook that has been thought
through.

**Concurrency is tested at the guard, not by racing threads.** Pest cannot
reliably run two requests at once against MariaDB, and a test that tried would
be flaky — which is worse than no test. What is testable, and what actually
matters, is that the guard exists and holds: the unique index refuses the second
write, the idempotency key replays rather than re-charges, and the ledger's
`lockForUpdate` is in the transaction. Each is asserted directly.

## 4. Not in this phase

- **Load testing.** It needs a target environment and a traffic model, neither
  of which exists here. What ships instead is the indexes being right, which has
  been a per-phase concern all along.
- **A penetration test.** That is somebody's engagement, not a commit.
- **Dependency scanning.** It belongs in CI configuration, which is the
  deployment's business rather than the application's; the runbook says what to
  run.
