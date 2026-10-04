# Phase J — what we buy, and what somebody else runs

Status: planned
Date: 2026-10-04
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §24, and what is left of §25
Follows: Phase H (costs), Phase C (the guarded change workflow), Phase A (the graph)

---

## 1. What is actually left

The lettered roadmap's last phase is three unrelated things wearing one
letter, and naming them separately is the first useful act:

| | What it is | What it already rests on |
| --- | --- | --- |
| §24 | Vendors, operational licences, contracts, and optionally procurement | Nothing. Rows an operator types, like DCIM and cost entries |
| §25 (IaC) | Terraform/Ansible/Git: show a plan, approve it, apply it | Phase C's guarded change workflow, exactly |
| §25 (Kubernetes) | Hosting-service context over a cluster, not a Rancher replacement | Phase A's resource graph, exactly |

Usage metering (§25) landed in Phase F and the Operations Calendar (§25) in
Phase D, so neither is here.

---

## 2. §24 is the one with no adapter, and that is why it is first

No API tells this platform what a transit contract costs or when the cPanel
licences renew. Somebody types it in — which puts §24 in the same family as
the DCIM spine and cost entries, and makes it the piece that can be finished
without a provider ever answering.

### 2.1 Three tables, and the line between them

**`vendors`** — who we buy from. A kind (`datacenter`, `transit`, `hardware`,
`software`, `registrar`, `backup`, `cloud`, `ddos`), a name, and how to reach
somebody there. Nothing clever.

**`contracts`** — what we agreed with one of them: what it covers, what it
costs per period, when it ends, and whether it renews itself. **A contract is
not a cost entry and must not become one**: a cost entry is what this month
was charged and a contract is what was agreed, and the same money appearing in
both as two rows nobody reconciles is the figure somebody would quote.
Phase H's `cost_entries` gains an optional pointer at the contract it came
from, so a cost can say where it is from and a contract can say what it has
actually cost.

**`licence_pools`** — an operational licence bought in quantity: cPanel,
CloudLinux, LiteSpeed, Imunify, Windows. A vendor, a contract, how many were
bought, and what each costs. Its rows are the *allocations*: which server is
using one.

### 2.2 The thing only this platform can answer

Every vendor portal can say how many licences you bought. **Only this
installation knows which of your machines are running them**, because it is
the one with the server list and the graph. So the screen opens on the
difference, exactly as backup coverage does:

- **Paid for and not allocated** — licences being billed for nothing.
- **Allocated to a server that is retired or gone** — the same money, with a
  worse story.
- **A server that looks like it needs one and has none** — the gap that costs
  an outage rather than money.

That difference is the whole reason the family is worth building, and it is
the tab the screen opens on. A list of licences you already have a receipt for
is a spreadsheet.

### 2.3 Expiry is an alert subject, not a constant

A contract that renews itself in nine days and a transit agreement that lapses
silently are the two ways this costs real money, and the answer is the one
`CertificateExpiry` already is: an `AlertSubject` with a threshold an operator
writes, and **core ships no rule**. Ninety days is right for a datacenter
contract with a negotiation behind it and absurd for a monthly cloud bill.

The gatherer emits **every** live contract including expired ones with a
negative figure, for the reason the certificate one does: "below 30" has to
catch "minus 3", and a contract that lapsed last night is the one somebody
most needs to hear about.

**Auto-renew changes what the alert means, not whether it fires.** A contract
that renews itself is not a crisis and is still the last moment to leave; one
that does not is a service that stops. Both are on the list and the row says
which.

### 2.4 Procurement is declined, with its reason

§24 says "Optional Procurement: forecast → request → approval → quote → PO →
delivery → inventory → deployment". Eight states, four of which are somebody
else's email, is a phase of its own wearing the word *optional* — and most
hosting companies do it in a thread and a spreadsheet.

What this platform would add is the last two steps, and it already has them:
delivery and inventory are `hardware_parts` and `part_fittings` (Phase G), and
deployment is the rack elevation. The six before them are a workflow with no
system of record behind it here, and building a purchase-order engine nobody
asked to replace their accountant with is how a phase ships something that is
turned off in a fortnight.

Declined, recorded, and the honest hook left in place: a contract can be
created from a vendor, and a part can name the contract it arrived under.

---

## 3. §25 IaC is Phase C's workflow with a different adapter

"Show plans/diffs and require approval to apply" is `network_changes`
described in nine words. The record exists: who asked, why, which ticket, the
exact diff, who agreed, what the thing said afterwards. The refusals exist and
their order is the feature — permitted to write, back up first, the fingerprint
must still match, apply, **read it back**, roll back on a failed verify.

So IaC is **not a second workflow**. It is:

- a contract (`InfrastructureAsCodeProvider`) that can describe a workspace,
  produce a plan, and apply one;
- `ChangeTarget` on `network_changes`, because the table is currently about a
  device and a Terraform workspace is not one;
- modules: `iac-terraform`, `iac-ansible`.

**`tries = 1` carries over and matters more here**, not less: `terraform apply`
is not idempotent in the way a provisioning retry is, and a second attempt
after a timeout can act on state the first one already moved.

**What does not carry over is the fingerprint.** A device's configuration
hashes to one string; a Terraform workspace's state is a file with a serial
and a lock. The equivalent check is the state serial, and an adapter that
cannot report one must refuse rather than guess — `DeviceConfiguration::fingerprint()`
exists precisely so that two adapters cannot disagree about what "unchanged"
means.

---

## 4. §25 Kubernetes is the graph, narrowed

"Hosting-service context rather than replacing Rancher" is the whole
specification, and the graph is already the shape: `cluster` contains `node`
contains `workload`, and a workload that belongs to a customer's service gets
the edge that makes `ImpactSummary` answer "who is affected if this node
drains".

So it is a `KubernetesProvider` and a discovery sweep, and **no screens of its
own**: the Explorer, the Telemetry screen and the impact figures already draw
whatever the graph holds. A Kubernetes *dashboard* is what the handoff says not
to build.

`ResourceKind` is an open vocabulary for exactly this, so `cluster`,
`namespace` and `workload` are a module's nouns and not core's.

---

## 5. Sequence

1. Vendors and contracts, with the cost link and the expiry alert.
2. Licence pools and allocations, and the three-way difference the screen
   opens on.
3. `ChangeTarget`, so `network_changes` can be about something other than a
   device.
4. `InfrastructureAsCodeProvider` and one module.
5. `KubernetesProvider` and the discovery sweep.

1 and 2 need no adapter and finish §24. 3 to 5 are seams, and the standing
caveat covers them: nothing in this repository has run `terraform plan` or
spoken to an API server.

---

## 6. Not in this phase

Procurement (§2.4, with its reason). A Kubernetes dashboard (§4). Anything
that applies an IaC plan without a person approving it — the guarded workflow
is the whole point, and an "auto-apply on merge" setting would be the shortcut
around it, built first.
