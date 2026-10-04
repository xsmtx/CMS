# Phase J — what we buy, and what somebody else runs

Status: complete
Date: 2026-10-04
Plan: `phase-j-plan.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §24, and what was left of §25

---

## 1. What landed

| | What | Where |
| --- | --- | --- |
| §24 | Vendors and contracts, with the cost link and the expiry alert | `app/Domain/Vendors`, `/admin/vendors` |
| §24 | Licence pools and allocations, and the three-way difference | `app/Application/Vendors/LicenceCoverage`, `/admin/vendors/licences` |
| §25 | `ChangeTarget`, so a guarded change may be about a workspace | `app/Domain/Network/ChangeTarget`, `network_changes.change_target` |
| §25 | `InfrastructureAsCodeProvider` / `…Writer`, and `iac-terraform` | SDK 1.13 |
| §25 | `KubernetesProvider` and the discovery sweep | SDK 1.14 |

Procurement is declined with its reason (plan §2.4, repeated in §6 below).

---

## 2. §24, and the decisions that shaped it

**The date that matters is the decision, not the end.** On a contract that
renews itself the last day to say no is `ends_on` minus the notice period;
showing the end would be telling somebody about a deadline they had already
missed. `Contract::decideBy()` is the one place that works it out and
`daysRemaining()` goes negative rather than clamping — "below 30" has to catch
"minus four".

**A rolling agreement answers null, never zero**, or it would be the most
urgent row on the screen. `ContractTerm::Once->months()` follows the same rule:
a one-off purchase divided into a monthly figure is a one-off spread across
months.

**A contract is not a cost entry and must not become one.** A cost entry is
what a month was charged; a contract is what was agreed. `cost_entries.contract_id`
is a pointer and the amount is never copied, because the same money in both as
two rows nobody reconciles is the figure somebody would quote.

**`AlertSubject::ContractExpiry` is a rule an operator writes and core ships
none** — thirty days is right for a business renewing by hand and absurd for a
monthly licence with a week's notice. Auto-renew changes what the alert *means*
rather than whether it fires.

### 2.1 Licences: the screen opens on the difference

Every vendor portal can say how many seats were bought. Only this installation
holds the server list, so only it can say which of them are doing anything.
Three answers, three different kinds of bad:

- **idle** — seats paid for and attached to nothing;
- **orphaned** — a seat on a machine that has left the fleet or been switched
  off, which is the same money with a worse story;
- **running without one** — a machine configured for a module whose pool holds
  no seat for it, which costs an outage rather than money.

**The gap is asked per pool.** A cPanel licence and an Imunify licence are both
about cPanel machines; asked once across every pool, a machine holding a cPanel
seat looks fully licensed — which is exactly the Imunify renewal nobody noticed
had lapsed.

**A pool that names no module produces no gap at all.** Core cannot know which
machines a licence belongs on, and a guess would be a list of four hundred
servers that each need nothing.

**Over-allocation is shown, never refused.** A hundred and one machines on a
hundred seats is real and expensive, and refusing the hundred-and-first
allocation would hide it from the one person who can fix it.

**`server_id` falls to null and `server_name` is copied**, so a seat on a
machine that has left the fleet stays visible instead of cascading away at
exactly the moment it starts being wasted.

**There is no licence-key column**, and a test asserts the column list: a key is
a credential for somebody's production panel, nothing here would ever read one,
and a column holding it would be a secret stored for no reason.

---

## 3. §25 IaC: Phase C's workflow, with two steps that mean something else

`network_changes` gained `change_target` rather than a second table. Who asked,
why, which ticket, the exact diff, who agreed and what the thing said
afterwards are identical questions, and two tables answering them would be two
queues an operator has to remember to look at.

| Step | A device | A workspace |
| --- | --- | --- |
| 1. permitted to write | `DeviceConfigWrite` | `AutomationApplyWrite` |
| 2. back up first | read the configuration | **the lock** — another run holding it |
| 3. unchanged | SHA-256 fingerprint | **the state serial** |
| 4. apply | `applyConfiguration` | run the approved plan |
| 5. verify | re-read the configuration | **plan again**; an empty plan is the proof |
| on failure | roll back | **fail** — reverting is another plan |

**An adapter that cannot report a state serial refuses rather than guesses.** A
workflow that proceeded there would be one with its only safety check switched
off.

**Core holds no Terraform code.** `intended` for a workspace is a revision,
nullable, never defaulted to a branch nobody named.

**`WorkspaceKey` is the one place that answers what the adapter calls a
workspace.** The node key is qualified by its adapter, and unlike a storage
volume this target is handed back on every later call.

**`tries = 1` carries over and matters more**, which it already did:
`ApplyNetworkChangeJob` was written that way in Phase C.

---

## 4. §25 Kubernetes: the graph, narrowed

`KubernetesProvider` and `DiscoverKubernetes`, and **no screens of its own** —
the Explorer, the Telemetry screen and the impact figures already draw whatever
the graph holds, and a Kubernetes dashboard is what the handoff says not to
build.

Cluster **contains** node, node **hosts** workload. And where a cluster node is
plainly a `servers` row, the server **hosts** the cluster node — which is what
joins the two halves, because the services a customer bought hang off the
server and a walk from the workload then reaches them.

**That match is by hostname and ambiguity is refused**, which is
`RecordSamples::byHostname()`'s rule for the reason it gives.

**No edge from a workload to a customer's service, ever.** The ends are in
different subtrees and `ResourceGraph::attach()` refuses it (ADR 0043) — which
is right, because an edge pointing that way would let a customer walk up from
their own service to the cluster. IPAM and storage hit the same wall and took
the same answer.

Four Kubernetes particulars that a careful read turns up, each pinned by a test:
a cluster does not know its own name; a node's condition is a list and `Ready`
is not the first entry; cordoned (`spec.unschedulable`) is separate from Ready;
and an unscheduled pod has no `spec.nodeName`, which read as `''` would place
it on a node called "".

---

## 5. What is deliberately absent

- **Procurement** (§24's optional eight states). Four of them are somebody
  else's email, and the last two — delivery and inventory — are already
  `hardware_parts` and `part_fittings`. Building a purchase-order engine nobody
  asked to replace their accountant with is how a phase ships something that is
  turned off in a fortnight. The honest hook is in place: a contract hangs off a
  vendor, and a cost entry names the contract it came from.
- **A write half for Kubernetes.** Scaling, draining and deleting all belong
  behind §6's workflow rather than behind a method anything could call.
- **Auto-apply on merge.** The guarded workflow is the whole point, and a
  setting for that would be the shortcut around it, built first.
- **A Kubernetes dashboard**, by the handoff's own words.

---

## 6. The standing caveat

Nothing in this repository has run `terraform plan` or spoken to an API server.
`iac-terraform` and `kubernetes` are tested against faked HTTP, which proves
the code and not the integration — the same sentence every adapter family in
this product carries, and `docs/operations/release-checklist.md` says the first
real deployment treats each as unproven.

---

## 7. Where the roadmap stands

Handoff #2's lettered phases A to J are complete. What remains is in
`docs/architecture/whmcs-parity-plan.md`, which audits this product against the
panel it is meant to replace.
