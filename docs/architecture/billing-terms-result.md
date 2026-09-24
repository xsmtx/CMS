# Billing Terms — Result

Status: complete
Date: 2026-09-24
ADR: [0046](../adr/0046-a-late-fee-is-a-new-invoice.md)
Follows: `tax-configuration-result.md`, which is the other half of the same
request.

The second half of "the financial side must be configurable, because this will
not be used in one country only". Tax was the rates. This is the three other
things that differed by country and were constants in `config/platform.php` —
which meant an operator could not change them without an environment file and a
deploy, and a reseller in another country could not have their own at all.

---

## 1. What shipped

- **`billing_settings`** — one row per seller: `due_days`, `late_fee_rate_ppm`,
  `late_fee_label`, `document_note`.
- **`number_sequences.reset_period` and `.period_key`** — a sequence that
  restarts at one every year or every month.
- **`invoices.is_late_fee`** — the flag that stops the dunning sweep chasing a
  fee.
- **`DunningAction::LateFee`** and **`ChargeLateFee`** — the fee, as its own
  invoice.
- **`BillingSettings`** (read) and **`SaveBillingSettings`** / **`SaveNumberSequence`**
  (write, both audited).
- **`/admin/billing/settings`** — owner only, on the Setup page beside Tax.
- **The note on the document** — `IssueInvoice` copies the seller's
  `document_note` onto `invoices.terms` at the moment of issue, and both invoice
  screens render it.

## 2. The decisions worth keeping

**A yearly reset is a legal requirement, not a preference.** An unbroken run from
INV-000001 forever is not an acceptable invoice book in Turkey, Italy, Spain,
Portugal or Poland. The platform could not express it, so an installation in any
of them could not legally use it.

**The period is a key that is compared, never an elapsed time.** `period_key`
holds `2026` or `2026-10` and a mismatch is what resets the sequence. Anything
derived from `updated_at` cannot tell "nobody invoiced in January" from "already
reset in January", and the second must not reset twice.

**The reset happens under the same lock as the increment.** That is why it lives
in `AllocateNumber` and not in a scheduled task. A sequence restarts on the first
document of the new year, whenever that document happens to be raised; two raised
in the same second must not both decide they are the one that resets. A task that
reset sequences at midnight would be a task that *has* to run, and a reset that
did not happen is a duplicate invoice number.

**Turning the reset on does not renumber this year.** A row with no `period_key`
adopts the current period rather than restarting, and switching the reset back off
does not cause one last restart from the stale key. Both are pinned by tests,
because both are somebody's invoice numbers.

**`next_value` is writable, and the audit row says by how much.** An installation
taking over from another panel has invoices on paper up to INV-010420; starting
again at one collides with every one of them. Lowering it is the same power
pointed the other way and is not refused — the platform cannot know which numbers
a legacy system used — but the next failure will be a unique-index violation and
the audit row is what explains it.

**Only the four financial sequences are offered.** `ticket` is a sequence too, and
a screen that accepted any key would let somebody renumber support tickets from
the billing screen. A test posts `ticket` and expects 404.

**A late fee is a new invoice** (ADR 0046, which answers a question Phase 9 left
open on purpose). The invoice the fee is about is frozen, so it cannot grow a
line; a line on the *next* invoice arrives weeks after the behaviour it exists to
discourage, on a customer who may have no next invoice. Five consequences, each
its own decision: the fee is a percentage of what is **outstanding**, not of the
total; it is a dunning **step**, so the timing is the step's `offset_days` and
`invoice_dunning_steps` is what stops it charging thirty times; the fee invoice is
**never itself chased**; nothing to charge is **not a failure**; and a fee is not
withheld service, so the customer's suspension preference does not apply to it.

**The note is copied at issue, never read at render.** The wording a country
obliges an invoice to carry is part of what the customer received, so changing it
next year must not change what last year's invoices say — the same rule as the
bill-to party and every amount (ADR 0023). An invoice that already carries its own
terms keeps them: an operator who typed something onto one document meant that
document.

**Terms fall back to configuration and no row is written on read.** An
installation that set `INVOICE_DUE_DAYS` behaves exactly as it did. A row written
on first read would be terms nobody agreed to, and would freeze today's default
into the database where the next deploy could not reach it. The screen says which
of the two an operator is looking at.

## 3. What the tests cover

`tests/Feature/BillingTermsTest.php` — 18 cases: the owner-only refusal on both
read and write; the shipped-default banner before and after somebody states the
terms; one row however often it is saved; five malformed rates and a due date a
year out refused; the due date taken from the seller and from the configured
fallback; a yearly reset across a new year and not twice in it; a monthly reset
and then switched off without one last restart; continuing a migrated book at
10421 with the screen's preview agreeing; `ticket` refused; the fee as a separate
issued invoice with the original untouched; the fee on a part-paid balance; the
three ways there is nothing to charge; the fee charged once across three sweeps;
three consecutive nights producing exactly one fee; a fee charged to a customer
who asked never to be suspended; the note copied onto a document at issue and
unchanged by a later edit; and a note an operator typed onto one invoice left
alone.

## 4. A bug the tests found in this work

`BillingSettings` shipped with a per-seller cache — a plausible optimisation,
since the renewal sweep asks the same question once per invoice. It cached the
**miss** as well as the hit, and a miss is a model saying "nobody has stated
these". Held across a save, that answer outlived the operator stating them: the
form redirected, the screen re-rendered from the cached default, and it read as a
settings page that did not save. The test that caught it was the one asserting the
banner disappears after saving — written because the banner is a sentence to an
operator, not because a cache was suspected.

The cache is gone and the docblock says why. A single indexed lookup per call is
the cheaper mistake.

## 5. Gates

Pint, Rector, PHPStan level 8, Pest (1593 passed), ESLint, Prettier, vue-tsc,
Vitest (85 passed), Vite build, `platform:openapi --check`. All green.

## 6. Not done, deliberately

- **The fee is a percentage and never a fixed amount.** A fixed fee needs a
  currency and this platform never converts one (ADR 0014), so a fixed fee is a
  price list — a row per currency and a screen of its own. A percentage of what is
  outstanding needs no currency and works in every country.
- **The fee carries no tax.** Whether interest is a taxable supply has a different
  answer in nearly every jurisdiction. An operator who must charge tax on one will
  need a `TaxAppliesTo` member for it, which is a change to make when somebody
  needs it rather than a rule invented here.
- **The note is printed on the invoice screens, not yet in a PDF.** There is no
  PDF renderer in this product, so "on the document" means the admin and client
  invoice pages. Both now show it, which also fixed a smaller thing: `terms` was
  already being sent to the admin screen as a prop and rendered nowhere at all,
  and `notes` was never rendered either.
