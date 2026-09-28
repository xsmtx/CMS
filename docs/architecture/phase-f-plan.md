# Phase F — The data platform: backup, storage, virtualization and metering

Status: complete (see `phase-f-result.md`)
Date: 2026-09-28
Previous: `phase-e-result.md`
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §9, §10, §12, §25
Umbrella: `advanced-operations-plan.md` §9 (F)

E was about the customer's side going wrong. F is about **the machine under
the customer**: whether their data is protected, whether the pool they live on
has room, whether the node can be taken out of service, and how much they
actually used.

It is the last phase whose answer to almost everything is "an adapter said
so", and the first where the platform is asked to act on hardware.

---

## 1. What ships

1. **Backup coverage** (§12) — a unified protected-resource view: what is
   protected, by what, when it last succeeded, and how old the last good copy
   is.
2. **Unprotected detection** (§12) — the inverse, and the point of the whole
   family: which services nothing is backing up.
3. **Storage** (§9) — pools and volumes with capacity, health and the
   workloads attached to them.
4. **Database and cache telemetry** (§9) — through the metric normalizer that
   already exists, exactly as mail did in E.
5. **Load balancers** (§9) — listeners, backends and their health, plus
   **guarded drain and undrain**, which is what D's maintenance windows were
   always going to need.
6. **Hypervisors and BMC** (§10) — cluster, host and machine health; power as
   a guarded action.
7. **The metering contract** (§25) — a stable seam into Billing, with an
   immutable snapshot of what was invoiced.

Not the Migration Center (§12's second half). It is a workflow of eight steps
with resumable per-account jobs, and putting it beside seven adapter families
would make this phase two phases wearing one name. It gets its own.

## 2. The decisions

### A backup is somebody else's job and this platform's problem

Core never takes a backup. `docs/operations/` has said since handoff #1 that
there is no backup button and there will not be one: a PHP process cannot take
a consistent snapshot, and one that produced an inconsistent snapshot would be
worse than none because somebody would rely on it.

What core *can* do — and what no backup vendor can — is say **which of the
things this platform sells are not protected at all**. Veeam knows what it
backs up. Only this installation knows what exists. The interesting query is
the difference, and it is the reason this family is worth building.

So `BackupProvider` reads, and `Capability::BackupRunWrite` and
`RestoreWrite` exist without a method on the contract — the third time that
pattern appears, after the firewall policy and the DNS record. Running a
restore is a guarded workflow, and building the method before the workflow
would be building the shortcut around it.

### "Unprotected" is the absence of a row, so it has to be careful

The dangerous failure is the mirror of E's: an adapter that is down reports
nothing, and "nothing" read naively means "everything is unprotected" — a
screen that says every customer has lost their backups, at three in the
morning, because a token expired.

So the unprotected list is built from **protection records that exist and are
stale**, plus services **no source has ever mentioned**, and the two are
distinguished on the screen. A service that was protected yesterday and is
missing from today's answer is `stale`, not `unprotected`; only a service no
adapter has ever named is the second. And a sweep whose read failed writes
nothing at all.

### Last-good age is the number, not success or failure

A backup job that failed last night is a warning. A backup job that has
succeeded every night for a month against a resource that was deleted three
weeks ago is a lie, and a green tick beside it is worse than a red cross.
`last_good_at` is what the coverage screen sorts by and what an alert rule
compares against — `AlertSubject::BackupAge`, the same shape as
`CertificateExpiry` and `ReputationListing`, with the threshold the
operator's because a daily and a weekly schedule do not agree about when to
worry.

### Drain is a change, and it already has a workflow

C built `network_changes`: who asked, why, which ticket, the exact diff, who
agreed, what the device said. Draining a backend out of a load balancer is
the same act against a different kind of box, and inventing a second record
for it would be two audit trails for one idea.

The open question the plan has to answer, and does: **a drain is reversible
and a firewall policy is not**, so requiring an approval for every drain would
make a maintenance window need a second person at two in the morning. Drain
and undrain are therefore a guarded action with the permission and the
password challenge, recorded as an operation (ADR 0032), and **not** an
approval. The record is the same; the gate is lighter, and the plan says so
rather than leaving somebody to discover it.

### Power is the most destructive thing in this product

`BmcPowerWrite` turns a machine off. Not a customer's service — the machine
several customers are on. It is level 4 in `AppConfirm`'s terms: the reason,
plus the node's own name typed out, which is the same bar `CompleteCancellation`
already sets for terminating a service.

A power action is never a retry (`tries = 1`, like `ApplyNetworkChangeJob`):
a power-off that timed out may well have happened, and a second attempt after
somebody brought the machine back is an outage this platform caused twice.

### Storage and database telemetry are numbers, and mail proved the shape

`MetricKind` gains the pool, database and cache members; they arrive through
the normalizer, land on Telemetry and are alerted on by existing rules. §6
stands: the series belongs in Prometheus and Zabbix.

What is *not* a metric here is a **volume** — it has an identity, a name, a
state and something attached to it, so it is a graph node with `resource_kind`
entries and an edge to what it serves. The same split mail made between a
queue depth (a number) and a blocklist listing (a row).

### A usage snapshot is frozen, because an invoice is

§25 asks for "immutable invoiced usage snapshots", and ADR 0023 already
decided what that means here. `usage_snapshots` is append-only, written when
a period closes, and an invoice line quotes it by id. Re-reading a meter for a
period that has been invoiced must not change what the customer was charged —
and a meter that revises history (they do) must produce a *second* snapshot
and a credit note, never an edit.

`UsageMeter` is the contract and it answers **quantities with units**, never
money. What a gigabyte costs is a price in the catalog, and a meter that
returned an amount would be a module setting prices.

## 3. Schema (core)

| Table | Why core |
| --- | --- |
| `backup_protections` | The unified protected-resource row: what, by which source, last run, last good, restore points. Raise-and-clear like `alerts`. |
| `usage_snapshots` | Append-only, quoted by an invoice line. |
| `usage_meters` | Which meter measures which service, and in what unit. |

Storage pools **and volumes**, load-balancer backends, hypervisor hosts and
BMC sensors are **graph nodes and telemetry**, not tables. They have no fact
core owns that the owning system does not: ADR 0043's rule, applied for the
fourth time.

### Correction: `storage_volumes` is not a table (2026-09-28)

This section originally gave a volume its own table, on the grounds that it
has an identity core wants to correlate to a service. It does not need one,
and `ResourceKind`'s own docblock says why in as many words: core owns four
kinds because it owns four kinds of row, and **"a storage volume"** is one of
the examples it names of a thing a *module* owns.

Everything the table was for is already in the graph. A pool contains a
volume, a server hosts one, a server contains a service and a service belongs
to a customer — so §9's "attached workloads" is the walk `ImpactSummary`
already does, with no new code. Capacity goes through `RecordSamples` as
`disk.total` and `disk.used`, which is what `CapacityForecast` reads and what
the Telemetry screen already draws; a column would have been a second copy of
a number that changes every hour, and the daily rollup would never have seen
it.

The one thing a table would have bought — an edge from a volume to a
customer's service — is refused by the graph anyway, because the two ends are
in different subtrees (ADR 0043). That is the same wall IPAM hit in Phase C,
and the answer is the same: the provider-side objects go in the graph and the
crossing is made by walking, never by an edge.

## 4. Authorization

New permissions, all `infrastructure`, staff scope:

- `infrastructure.backup.view` — Support holds it. "Is my site backed up" is a
  support question before it is anybody else's.
- `infrastructure.storage.view` — Support holds it.
- `infrastructure.drain` — high risk, password challenge. Not Support's.
- `infrastructure.power` — high risk, password challenge, level-4 dialog. Not
  Support's.
- `billing.usage.view` and `billing.usage.invoice` — the second is what turns
  a snapshot into a line, and it is the Billing role's rather than
  infrastructure's.

The route group carries the permission **above** `auth.recent`, which is the
rule this repository has now learned five times.

## 5. Sequence

1. Backup contract, `backup_protections`, the coverage and unprotected
   screens, `AlertSubject::BackupAge`.
2. Storage contract, volumes, the pool telemetry kinds.
3. Database and cache telemetry kinds (no new screen — Telemetry has one).
4. Load balancer contract, backends, guarded drain/undrain.
5. Hypervisor and BMC contracts, machine inventory, guarded power.
6. Metering contract, snapshots, the invoice line that quotes one.

Each step is a commit with its gates, its browser pass and its language files,
as every phase since A has been.

## 6. Not in this phase

- **The Migration Center** (§12) — its own phase, for the reason in §1.
- **DCIM, hardware inventory, PDU and environment** (§11) — phase G, which
  needs F's hypervisor answers to know what the hardware is carrying.
- **Any adapter this repository can prove.** The modules are written against
  the vendors' documented shapes and faked HTTP, like every adapter before
  them.

## 7. The standing limitation

Unchanged, and this phase makes it sharper than any before it: **no adapter
here has ever talked to the thing it adapts**, and two of them can turn a
machine off. The release checklist's rule that each adapter is unproven until
a real provider has answered it applies with more force to `BmcPowerWrite`
than to anything shipped so far, and the guarded workflow exists precisely
because the first real call will be made by somebody who cannot undo it.
