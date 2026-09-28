# Phase H — Intelligence: what is actually true, and what it costs

Status: planned
Date: 2026-09-29
Previous: `phase-g-result.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §19, §20, §21, §22
Umbrella: `advanced-operations-plan.md` §9 (H)

Every phase so far added something this platform can see. H is the first that
adds something it can **conclude**: that the provider says a service is active
and cPanel says it is suspended, that a machine is running and nobody is being
billed for it, that a customer costs more to serve than they pay.

§9 puts H after A, B, E and F for one reason stated there: *nothing is
remediated before it is reconciled*. A remediation proposal needs an actual
state to compare an expected one against, and the adapters that report an
actual state landed in B, C, F and G.

---

## 1. What ships

1. **The reconciliation engine** (§22) — expected platform state against
   actual provider state, classified `healthy / drift / orphan / missing /
   unknown`, with the difference itself recorded.
2. **Remediation proposals** (§22) — a proposal is a record somebody
   approves, never an action a sweep takes.
3. **Orphan detection** (§21) — what exists at a provider and belongs to
   nobody here.
4. **Revenue leakage** (§21) — active services with nothing billing them,
   domains with no renewal, addons missing from an invoice, payments matched
   to nothing.
5. **Cost and profitability** (§21) — what a service costs to run, from the
   infrastructure, licence and payment figures this platform already holds.
6. **Customer health** (§21) — explainable signals, never a score with no
   arithmetic behind it.
7. **Noisy neighbour** (§21) — a service using disproportionately more than
   its neighbours on the same machine.

Not the automation builder (§19) and not the AI assistant (§20). Both are in
H's box in §9 and both are the wrong thing to build in the same phase as the
data they would read; §6 says why.

---

## 2. The decisions

### Intelligence is core, for the third time

§83's module map puts all five of H's items in modules — `reconciliation`,
`commercial-intelligence`, `cost-model`, `automation-builder`, `ai-assistant`.
Phase C corrected that for IPAM and the correction applies here with more
force: **a module cannot draw a screen**, and almost everything in H is a
screen. `ExtensionPoint` has `Navigation` and `Widget`, and a `NavigationItem`
carries a *path* — a path core already serves.

What stays a module is what it has always been: an adapter that reports an
actual state, and a calculator somebody's own business needs. A provider's
cost model is not core's to write.

### A difference is a record, and remediating it is a second decision

The reconciliation engine writes `reconciliation_findings`. A finding says
what was expected, what was found, when, and against which resource. Nothing
about it is an action.

This is ADR 0031 and ADR 0032 applied to a comparison. A sweep that quietly
fixed what it found would be a sweep that suspends a customer because a panel
was slow to answer, and the audit row afterwards would say the platform did
it to itself. A finding is raised, kept while it is true, and cleared when it
stops being true — the raise-and-clear shape `alerts`, `zone_findings` and
`reputation_listings` already share.

### `unknown` is a class, and it is the one that matters

§22 names five classes and four of them are conclusions. `unknown` is the
absence of one: the provider did not answer, the adapter has no capability
for this resource, or the account exists at a provider this installation
cannot reach.

It must never be folded into `drift`. A machine that "might have been
resized" and a machine nobody could ask are different things to act on, and
the second is a monitoring problem rather than a customer's problem. The
whole family of bugs this product keeps finding — `unknown` availability, a
null vulnerability, a stale metric, a missing latest version — is the same
mistake, and this is the phase where getting it wrong would suspend somebody.

### An orphan is never deleted, and the word is a finding rather than a verdict

§21 wants VMs, hosting accounts, IPs, DNS zones and certificates without
valid platform ownership. Every one of those has a legitimate reason to exist
without a row here: a machine built by hand for a migration, an address held
for a customer arriving next week, a zone somebody parked.

So an orphan is a finding with a reason to read, an age, and a way to say
"this one is deliberate" — a dismissal that is itself a record with an actor
and a note. Nothing offers a delete button, in this phase or any other: the
destructive act belongs to the provider's own console, where the person doing
it can see what else is on the machine.

### Revenue leakage is arithmetic on rows this platform already owns

No adapter, no provider, no model. Four questions, each of which is a join:

- an **active service** whose product has a recurring price and which no
  invoice line has referenced since its last renewal date;
- a **domain** past its renewal date with no renewal invoice;
- an **addon** on a service with no corresponding line on the invoice that
  covered its parent;
- a **payment** whose amount matched no invoice.

Each answers in `MoneyByCurrency`, never a number: there is no rate anywhere
in this product and a total across currencies is a figure that means nothing
and is the figure somebody would quote.

### Cost is an allocation, and every allocation is somebody's opinion

§21 wants cost by product, service and node from infrastructure, payment,
licence, bandwidth and optionally support. Only one of those is a fact this
platform holds without a choice: the payment fees, which are on the ledger.

The rest is allocation — a server costs €200 a month and carries forty
accounts, and how that €200 lands on the forty is a decision. So:

- **Core ships no allocation.** `cost_entries` is what an operator states: a
  cost, a period, a currency, and what it is against (a server, a product, a
  licence, the installation).
- **The allocation is a strategy with two members to begin with**: evenly
  across the services on a node, or weighted by a metric the graph already
  holds. A third arrives when somebody asks for it, not before.
- **The figure says which strategy produced it**, on the row and on the
  screen. A margin whose arithmetic an operator cannot check is a margin they
  will believe when it is wrong — the rule `CapacityForecast` already lives
  under.

Nothing here touches the ledger. A cost is not a transaction; it is what the
provider paid somebody else, and the ledger is the record of what customers
paid this provider (ADR 0024).

### Customer health is signals, never a score

§21 says "explainable", and the only honest way to be explainable is to not
compute the thing that needs explaining. A customer health view is a list of
signals with their own arithmetic on each — days overdue, tickets open past
their SLA, failed provisioning runs, a service in `failed`, a payment method
about to expire — and no weighted total.

A number between 0 and 100 would be a number somebody acts on and nobody can
reproduce, and the first argument about it would be the last time anyone
opened the screen.

### Noisy neighbour is a comparison, not a threshold

A service using 40% of a machine's CPU is fine on a machine with two services
and a problem on one with forty. So the question is about a node's own
distribution: which services on this node are using disproportionately more
than their neighbours, measured against the median rather than the mean —
because the mean of forty accounts and one runaway is a mean the runaway
moved.

It needs **per-service metrics**, which is what §9 means by its dependency
note. Where only per-node metrics exist the answer is "nothing can be said",
in words, rather than a comparison of one.

---

## 3. Schema (core)

| Table | Why core |
| --- | --- |
| `reconciliation_findings` | Raise-and-clear, per resource: what was expected, what was found, which class. |
| `reconciliation_dismissals` | "This one is deliberate", with an actor, a reason and a date it comes back. |
| `remediation_proposals` | What could be done about a finding, who approved it, what happened. |
| `cost_entries` | A cost an operator states, against a server, a product, a licence or the installation. |
| `leakage_findings` | The four revenue questions, so "we fixed that" is a row rather than a memory. |

Customer health and noisy neighbour get **no table**. Both are questions about
rows that already exist, asked when somebody opens the screen; a table of them
would be a cache that disagrees with its source by the afternoon.

---

## 4. Authorization

- `intelligence.reconciliation.view` — the queue. Support holds it: "the
  customer says their account is suspended and the panel says active" is a
  support question first.
- `intelligence.reconciliation.remediate` — approving a proposal. Not
  Support's, and it carries the password challenge, with the permission
  **above** `auth.recent` on the route (learned five times now).
- `intelligence.commercial.view` — leakage, cost, profitability. Commercial
  rather than operational, and a separate permission for that reason: the
  people who chase a suspended account are not the people who see the margin
  on it.
- `intelligence.costs.manage` — stating what something costs.

Customer health reads `crm.customers.view`, because it is a view of a
customer and inventing a permission for a screen that shows what somebody may
already see would be a permission that only confuses.

---

## 5. Sequence

1. The reconciliation engine: the finding, the five classes, the sweep, the
   queue, and the dismissal.
2. Remediation proposals, and the approval that turns one into an operation.
3. Orphan detection, which is reconciliation read from the other end.
4. Revenue leakage.
5. Cost entries and the allocation strategies, then profitability.
6. Customer health, and noisy neighbour where per-service metrics exist.

---

## 6. Not in this phase

- **The automation builder** (§19). It is `WHEN → IF → THEN → WAIT →
  RECHECK → THEN` over exactly the findings this phase creates, and building
  both at once would fix the vocabulary of the first to whatever the second
  happened to need on its first day. It also needs loop prevention, execution
  limits and action risk classes — three of which this product has words for
  and none of which it has a mechanism for.
- **The AI assistant** (§20). It reads "normalized operational context", and
  the context it would read is what H is still deciding the shape of. Its one
  hard rule is already recorded and does not change: it never executes a
  generated command, and every recommendation references internal evidence.
- **A delete button on an orphan.** Stated above, and it is a decision rather
  than a deferral.
- **Automatic remediation.** A proposal an operator approves becomes an
  operation; nothing applies one on a schedule. §22 says "approved
  automation" and the approval is the part this phase builds.

---

## 7. The standing limitation

Unchanged, and sharper here than anywhere. Reconciliation compares what this
platform believes against what an adapter reports, and **not one of those
adapters has ever talked to its real system**. A finding is therefore only as
true as an untested parse, which is exactly why nothing in this phase acts on
one by itself.

`docs/operations/release-checklist.md` is unchanged and still right: the
first real deployment treats every adapter as unproven, and the first
reconciliation run on a real installation should be read as a test of the
adapters rather than as a list of problems.
