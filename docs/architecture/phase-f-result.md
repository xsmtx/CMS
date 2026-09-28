# Phase F — the data platform: what shipped

Status: complete
Date: 2026-09-28
Plan: `phase-f-plan.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §9, §10, §12, §25

Everything §1 of the plan listed is in, with the Migration Center deferred as
the plan said it would be. Phases G to J are not started.

---

## 1. What shipped

| § | Thing | Where |
| --- | --- | --- |
| 12 | Backup coverage, and what nothing is protecting | `backup_protections`, `BackupProvider`, `RecordProtections`, `BackupCoverage`, `Admin/Infrastructure/BackupCoverage` |
| 12 | Backup age as an alert subject | `AlertSubject::BackupAge` |
| 9 | Storage pools and volumes | `StorageProvider`, `DiscoverStorage`, `modules/infracms/storage-ceph` |
| 9 | Database and cache telemetry | `db.slow_queries`, `db.deadlocks`, `cache.evictions` |
| 9 | Load balancers, and guarded drain | `LoadBalancerProvider`, `LoadBalancerWriter`, `DrainBackend`, `Admin/Infrastructure/LoadBalancers`, `modules/infracms/loadbalancer-haproxy` |
| 10 | Hypervisors, and guarded power | `HypervisorProvider`, `HypervisorWriter`, `ChangeMachinePower`, `Admin/Infrastructure/VirtualMachines`, `modules/infracms/hypervisor-proxmox` |
| 25 | The metering contract and its snapshots | `UsageMeter`, `usage_meters`, `usage_snapshots`, `RecordUsage`, `QuoteUsage`, `CollectUsage` |
| 12 | Migration Center | **deferred by the plan — see §4** |

Permissions: `infrastructure.backup.view`, `infrastructure.loadbalancers.view`,
`infrastructure.drain`, `infrastructure.machines.view`,
`infrastructure.power`. Support holds the three views; the two writes are
high-risk, carry the password challenge, and sit **above** `auth.recent` on
their routes.

SDK: **1.9**. Additive throughout — four contracts that did not exist
(`BackupProvider` at 1.7, `LoadBalancerProvider`/`LoadBalancerWriter` at 1.8,
`HypervisorProvider`/`HypervisorWriter` at 1.9) plus `UsageMeter`.

Automation: `backups`, `storage`, `load-balancers`, `machines` and `usage`,
taking the task count from 20 to 25.

## 2. The decisions this phase actually made

**Core never takes a backup, and that is what makes the family worth
building.** Veeam knows what it backs up; only this installation knows what it
sold. The difference — which running services nothing is protecting — is the
answer nobody else can give, and it is the tab the screen opens on.

**`last_good_at` is the number, never the last outcome.** A job that has
succeeded nightly for a month against a resource deleted three weeks ago is a
lie, and a green tick beside it is worse than a red cross.

**Unprotected and stale are different, and that distinction is the safety
rail.** A source that is merely unreachable reports nothing, and "nothing"
read naively means every customer has lost their backups. Every contract in
this phase therefore says the same thing in as many words: **a read that
failed must throw, never return `[]`** — and every sweep catches it without
calling its recorder.

**A storage volume is a graph node, not a table** (`phase-f-plan.md` §3 records
the correction). `ResourceKind`'s own docblock names it as the example of a
thing a module owns, and everything a table would have bought is already in the
graph — pool contains volume, server hosts volume, server contains service. The
one thing it would have added, an edge from a volume to a customer's service,
is refused anyway: different subtrees (ADR 0043), the wall IPAM hit in Phase C.

**A drain is a guarded action; a power change is a guarded action with a
higher bar.** A drain is reversible where a firewall policy is not, so
requiring a second person would make a maintenance window need somebody else
awake at two in the morning — a permission and a password, not an approval. A
power action is level 4, with the machine's own name typed out, because it does
not stop a customer's service: it stops the machine several customers are on.

**Both writes read the thing back.** A balancer or a hypervisor that accepted
a command and cannot then describe what it did is a named refusal, not a
success — the operator is about to work on that machine.

**Stopping something already stopped is refused, not `already_done`.** The
opposite of provisioning (ADR 0026), and deliberate: a provisioning retry that
finds the account created has found what it wanted; an operator pressing Shut
down on a machine that is off is looking at a page that does not match the
world.

**A meter answers quantities and the seller sets the price.** A source that
returned money would be a module setting prices. The rate lives on
`usage_meters` because a usage rate is per service, per meter, with an
allowance — three dimensions the catalog's price matrix has not.

**A snapshot is append-only and an invoice line quotes it**, which is §25's own
phrase and ADR 0023's rule: the invoice is frozen, so the number comes from a
row that cannot change and is stamped with the item that quoted it.

## 3. What was found by driving it

Eight things the suite could not see:

- **`BackupOutcome::Succeeded` returned the tone `success`** where every other
  enum in this product returns `healthy`, so `asTone()` fell through and a
  backup that had worked drew the unknown mark. `VocabularyTest` now checks
  every `tone()` under `app/Domain` against the `TONES` array parsed out of
  `status.ts`.
- **The backup screen's three figures counted services while two of its three
  lists showed protections** — "1" over three rows. Each figure now counts
  exactly what its list shows, which is the contract a pressable count makes.
- **Every metric name in the product printed its own translation key**, on four
  screens, in both languages, since Phase A: `MetricKind`'s values have dots,
  so `__('infrastructure.metrics.disk.total')` walks three levels of nesting
  and returns the path. `MetricNames` is the reader now, beside
  `PermissionNames` and `CapabilityNames`. `CapacityPanelTest` had asserted the
  bug by comparing one broken call against another.
- **The Explorer's drawer received every discovered fact and drew none of
  them.** `attributes` has been in the payload since Phase A — model, serial,
  firmware, port speed, MAC, VLAN — declared in the props interface and
  rendered nowhere.
- **A declared `default` on a module config field meant nothing.** An absent
  boolean became `false` rather than its default, and the settings screen sent
  `null` for anything unset — so every `default: true` in every manifest was
  decorative. The one that mattered was `verify_tls`: a module configured
  without naming it had certificate verification silently turned off.
- **A button's label interpolated into a question** is not a sentence in
  English and is worse in Turkish, where the word order differs. Each power
  action has its own title and its own body now.
- **`AppMenu`'s slot exposes `close`** and a row that opens a dialog has to
  call it, or the menu stays open behind the scrim.
- **A helper function in a Pest file is global**, and `function reading()`
  existed in two of them — "Cannot redeclare" takes the whole suite down.
  `tests/Feature/TestHelpersTest.php` refuses it now. Found because Rector
  reported a three-argument call as having extra parameters, having resolved
  the other function of that name.

## 4. Not in this phase

- **The Migration Center** (§12's second half) — eight resumable steps with
  per-account jobs, which beside seven adapter families would have made this
  phase two phases wearing one name. Its own.
- **DCIM, hardware inventory, PDU, UPS and environment** (§11) — phase G, which
  needs F's hypervisor answers to know what the hardware is carrying.
- **BMC** (§10's second half) — the contract is not written. A power action
  through a hypervisor stops a guest; the same action through a BMC stops the
  host every guest is on, and it belongs with G's hardware inventory where
  there is something to say about which host that is.

## 5. The standing limitation

Unchanged, and sharper than in any phase before it: **no adapter here has ever
talked to the thing it adapts**, and two of them can take a machine out of
service. `docs/operations/release-checklist.md` treats every adapter as
unproven until a real provider has answered it; for `VirtualMachinePowerWrite`
that is not a formality. The guarded workflow exists precisely because the
first real call will be made by somebody who cannot undo it.
