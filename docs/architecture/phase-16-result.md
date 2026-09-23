# Phase 16 — Reporting / Operations Result

Status: complete
Date: 2026-09-24
Previous: `phase-15-result.md`
Handoff: §22 Phase 16 — "MRR/ARR, churn, aging, product/gateway revenue,
renewals, support metrics and operational reports"

---

## 1. What shipped

`/admin/reports` — the monthly review, on one page.

- `ReportPeriod` — the window, inclusive of both days.
- `MoneyByCurrency` — the shape every monetary answer in this platform has.
- `RecurringRevenueReport` — MRR, ARR, active and suspended counts, movement
  (added and lost, as counts *and* as recurring value), and money in by month.
- `AgingReport` — six buckets from the due date.
- `RevenueBreakdownReport` — by gateway, by product, and renewals ahead.
- The screen, boundary-scoped, with a period picker.

Support metrics and reseller performance already existed —
`/admin/support/overview` and `/admin/reports/resellers` — and are linked from
the bottom of the page rather than reimplemented. A second copy of a figure is a
second thing to keep in step.

## 2. One screen, not six

Because that is how the question is asked: somebody sits down once a month, sets
a period, reads recurring revenue, movement, what is owed, what is coming and
where it came from in one pass, then screenshots it. Six screens with six period
pickers would be six chances for two of them to cover different months, and
nobody would notice — the numbers would all look plausible.

## 3. The decisions

**Every money figure is a list, not a number.** `MoneyByCurrency` exists once and
every report returns it. A reseller selling in lira and euros has two MRRs and
there is no rate anywhere in this product to make them one. A total across
currencies would be a figure that means nothing, and it would be the figure
somebody quotes.

**MRR and ARR are labelled as what they are, on the page.** MRR is a snapshot:
every active service's recurring amount divided down to a month, by integer
division on minor units. ARR is *twelve times that*, and the card says
"Twelve times the month, not a year read". Both are legitimate figures answering
different questions; printing one and calling the other is the classic reporting
lie, and an operator raising money on it finds out in due diligence.

**A one-time line is skipped rather than counted as zero.** Counting it would put
a setup fee inside a recurring figure.

**Aging is measured from the due date.** An invoice issued ninety days ago with
sixty-day terms is thirty days overdue, not ninety — a report that said otherwise
would have somebody chasing a customer who has done nothing wrong. It ages by
what is **outstanding**, not by the total, so a partially paid invoice appears at
its remainder. And an invoice with **no due date gets its own bucket**: dropping
it into "current" would hide it, and there is no honest arithmetic to do with a
missing date, so the bucket exists to make the data problem visible.

**Money in comes from the ledger, not from invoices.** The ledger is the truth
(ADR 0024) and an invoice's date is when it was *issued*. A revenue chart built
on issue dates is a chart of intent.

**Gateway revenue is net of refunds.** A gateway that took 1,000 and gave 400
back brought in 600; a gross figure makes a refund-heavy month look like a good
one.

**Product revenue comes from the services, not from invoice lines.** An invoice
line copies a description rather than referencing a product (ADR 0021) — right
for a frozen document, useless for grouping: two products renamed the same thing
would merge and one renamed last March would split in half. A service with no
product is kept and labelled, not dropped, because a breakdown whose total
disagrees with the MRR figure above it is a report nobody trusts.

**Renewals ahead are the whole term**, not a monthly share, because a renewal
invoice is for the term and this figure is what will actually be billed. Counted
from today rather than from the report period: "what is coming" is not a question
about last quarter.

**Movement is measured on services.** Counts and recurring value together, so the
two numbers are comparable and each is a list an operator can open — which is the
only way a churn figure is ever useful.

**The boundary applies and a test drives a reseller at it.** The same screen
answers the provider's question and a reseller's, with no second implementation.
`toBase()` keeps the models' global scopes while making the aggregates typeable;
`collectedByMonth` uses `DB::table` and therefore applies the boundary by hand,
because a report that leaked another reseller's revenue would be the worst
possible place to leak one.

**A backwards period is read the way it was meant and a malformed date falls back
to the default window.** A report is not the place to answer an error page.

## 4. Not built, and why

- **Export to CSV or PDF.** The page is one screen an operator screenshots or
  prints, and a monthly review does not need a file. A real export belongs with
  the signed-download work in Phase 17, where the rules for producing files an
  operator can hand to an accountant are settled.
- **Cohort or per-customer churn.** Movement on services is the figure an
  operator can act on today. Cohort analysis needs a definition argued out with
  somebody who will use it, and guessing it would mean a number that looks
  authoritative and is not.
- **A dashboard of dashboards.** The existing dashboard (Phase 12) already
  answers "what needs attention today". This screen answers "how did the month
  go". Merging them would produce a page that answers neither.

## 5. Gates

Pint, Rector, PHPStan level 8, Pest on MariaDB, ESLint, Prettier, vue-tsc,
Vitest, Vite build — all green. 1251 Pest tests.
