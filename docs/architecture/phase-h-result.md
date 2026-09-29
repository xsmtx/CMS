# Phase H — intelligence: what shipped

Status: complete
Date: 2026-09-29
Plan: `phase-h-plan.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §9, §21, §25

All six steps of the plan's sequence are in, and step 6's second half — noisy
neighbour — with them. Phases I and J are not started.

---

## 1. What shipped

| § | Thing | Where |
| --- | --- | --- |
| 21 | The reconciliation engine: five classes, the sweep, the queue | `reconciliation_findings`, `ReconciliationClass`, `Difference`, `ReconcileServices`, `RecordFindings`, `Admin/Intelligence/Reconciliation` |
| 21 | "This one is deliberate", with a date it comes back | `finding_dismissals`, `DismissFinding` |
| 21 | Remediation proposals, and the approval that makes one an operation | `remediation_proposals`, `RemediationAction`, `ProposalState`, `ProposeRemediation`, `Remediations` |
| 21 | Orphan detection — reconciliation read from the other end | `DetectOrphans` |
| 25 | Revenue leakage: the four questions | `leakage_findings`, `LeakageKind`, `Leak`, `DetectLeakage`, `RecordLeaks`, `Admin/Intelligence/Leakage` |
| 25 | Cost entries and how they are shared out | `cost_entries`, `CostScope`, `CostPeriod`, `AllocationStrategy`, `AllocateCosts`, `Admin/Intelligence/Costs` |
| 25 | Profitability, and the margin that cannot be stated | `Profitability`, `ProfitRow`, `ProfitGrouping`, `Admin/Intelligence/Profitability` |
| 21 | Customer health: signals, never a score | `HealthSignal`, `CustomerSignal`, `CustomerHealth`, `Admin/Intelligence/CustomerHealth` |
| 9, 21 | Noisy neighbour: a comparison, never a threshold | `MetricKind::isContended()`, `NoisyNeighbours`, `NoisyRow`, `NeighbourReport`, `Admin/Intelligence/NoisyNeighbours` |

Permissions: `intelligence.reconciliation.view` (Support holds it — "the
customer says their account is suspended and the panel says active" is a
support question first), `intelligence.reconciliation.remediate` (with the
password challenge, the permission **above** `auth.recent` on the route),
`intelligence.commercial.view` and `intelligence.costs.manage`.

Two screens deliberately declared **no permission of their own**: customer
health reads `crm.customers.view` and noisy neighbour reads
`infrastructure.telemetry.view`. Both show what somebody may already see,
arranged to answer one question, and a permission for that is a permission
that only confuses.

SDK: unchanged at **1.11**. Nothing in this phase is an adapter seam — every
answer is built from rows this platform already holds, which is the point of
the phase.

---

## 2. The decisions worth keeping

**Two screens compute nothing that is stored, and that is the design.**
Customer health and noisy neighbour get no table: both are questions about
rows that already exist, asked when somebody opens the screen. A stored copy
would disagree with its source by the afternoon, and the disagreement would
be invisible.

**There is no customer health score, and a test keeps it that way.** §21 asks
for it to be explainable, and the only honest way to be explainable is not to
compute the thing that would need explaining: a number between 0 and 100 is a
number somebody acts on and nobody can reproduce, and the weights behind it
are a commercial opinion this platform has no standing to hold. Seven signals,
each with its own arithmetic, ordered by the worst thing that is *true* of
each customer. `CustomerHealthTest` walks each row's own keys and refuses one
named anything like `score`, `rating` or `total`.

**Noisy neighbour is measured against the median, never the mean.** The mean
of thirty-nine idle accounts and one runaway is a mean the runaway moved: it
rises with the thing it is supposed to measure against, and on a machine with
two runaways it rises far enough to hide both. The median does not move at
all, and a test pins exactly that case.

**It declines more often than it answers**, like `CapacityForecast`. Fewer
than four services on a machine is not a distribution — with two, the median
is the midpoint between them and one of the two is always "twice the median"
— and where only per-node metrics exist the answer is "nothing can be said",
in words. That is three different empty answers (nothing is noisy, nothing
reports per service, nothing carries enough neighbours) and the screen keeps
them apart, because one empty state for all three would tell an operator
everything is fine when the truth is that nothing was measured.

**A median of nought is the common shape, not an error.** Thirty-nine idle
sites and one busy one is exactly what this feature was built for, and it is
exactly what produces a zero median. The row is reported with no multiple at
all rather than with an invented infinity, and there is deliberately no floor
below which a reading is called insignificant — 0.1% of a 128-core machine is
not nothing, and core has no way to know what is.

**Every monetary answer is `MoneyByCurrency`**, including the ones that cannot
be stated: `ProfitRow::margin()` returns null across currencies rather than
adding a euro to a lira, and the screen says why in a sentence under the table
rather than in a tooltip.

**A customer is an organization of its own**, and three classes in this phase
were written filtering on the seller's organization instead of the subtree —
which matched nothing at all on a real installation while every test passed,
because the fixtures had forced the customer into the provider's own
organization. `OrganizationSubtree` is the one place that answers whose
customers these are. Recorded at length in CLAUDE.md, because it is the most
expensive mistake of the phase and the cheapest to repeat.

---

## 3. Not in this phase

The plan's §6 list is unchanged. Nothing here talks to a provider, and the
reconciliation engine's `observe()` reads through the same provisioning module
a sync already used — so the standing caveat holds: the adapters have never
answered for real, and a screen that reconciles against a faked provider
proves the arithmetic and not the integration.
