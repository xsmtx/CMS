# Phase B — Core Operations Result

Status: complete
Date: 2026-09-26
Plan: `advanced-operations-plan.md` §9a
Handoff: `CLAUDE_ADVANCED_HOSTING_OPERATIONS_HANDOFF_2.md` §3, §4, §7, §14, §17,
§23, §28 Phase B, §29
Builds on: `phase-a-result.md`

Phase A built the graph and proved the seam with a worked example that reads a
file. Phase B is the first phase where something outside this repository answers.

---

## 1. What shipped

- **`SecretStore`** — `SecretReference` (`area/kind/owner`), the contract in
  `app/Domain/Secrets`, `EncryptedDatabaseSecrets` behind it. Writes are audited
  and reads are not; writing over a value is a rotation; the audit row names the
  reference and never the value. A `vault-hashicorp` module can answer the same
  contract later without a caller changing.
- **`modules/infracms/monitoring-prometheus`** — the first adapter family that
  reads a real monitoring system. Four instant PromQL queries across every target
  rather than one query per host, so a fleet of four hundred machines is four
  requests. The address is module configuration; the token is in the vault, read
  at the moment of the call so a rotation takes effect without restarting a
  worker.
- **The credential UI** on the Adapters screen — write-only, behind the password
  challenge, saying only whether a credential is stored and when it last changed.
- **`CheckAdapterHealth`** — the health sweep Phase A deferred, pacing itself by
  each adapter's own declared `RateLimits` and asking a question about rows rather
  than about the clock.
- **`resource_metric_days` and `CapacityForecast`** — the one series this platform
  keeps, accumulated by the collector as readings arrive, and a straight line
  through it that declines more often than it answers.
- **Global search reaches the graph** — a hostname or an IP out of somebody else's
  ticket finds the resource.
- **The capacity panel** on Telemetry — what is filling, soonest first, drawn only
  when something is, and saying how many daily points each date came from.
- **Target mapping** (`RecordSamples::byHostname()`) — what an adapter calls a
  machine, matched to what this platform calls it.
- **Scored placement** (§4) — `PlacementStrategy::Scored`, `PlacementFactor`,
  `PlacementComponent`, `PlacementDecision`, `ScorePlacement`, and a
  `service_placements` row per decision, rendered on the service screen.
- **The Infrastructure Center** (§3), as far as core honestly can — see §4.

## 2. The decisions

**A secret is a contract before it is a table.** The first caller of the vault is
the first adapter, which is why the vault is first in the phase. `SecretStore`
takes no actor: it lives in `app/Domain` and the layering test refuses an
`Illuminate` import, so the actor is resolved by the implementation from
`CurrentActor` — which is also the honest shape, because a queue worker rotating
a token has no actor to pass.

**A node key is a ULID and an adapter has never heard of one.** Phase A wrote the
reason into a comment and left the answer to this phase: a hostname is not unique,
so two machines sharing one would silently become a single node. Prometheus
reports `instance="web-1.dc2:9100"`. Without a mapping every reading on a real
installation lands in `unplaced` while everything is configured correctly, and the
Telemetry screen stays empty for a reason nobody can see.

The mapping is the node's own `hostname` attribute, lower-cased, with the port
stripped — including `[2001:db8::1]:9100`, because splitting an IPv6 address on
its last colon leaves half an address. **Ambiguity is refused rather than
resolved**: two nodes claiming one hostname match nothing at all, and the target
is counted as unplaced. A reading attached to the wrong machine is worse than a
reading nobody placed, because the first one is acted on.

**Scored placement is a weighted mean and says so.** There is no model and
nothing to tune beyond the weights on `PlacementFactor`: a scoring function an
operator cannot reproduce on paper is one they will override every time it
surprises them, and then the placement engine is an operator with a spreadsheet.

Ten of the thirteen inputs §4 lists. **Reserved capacity and compatibility are
left out on purpose** — a server group already decides which nodes can take a
product, and no column anywhere reserves a slot, so a factor scored from a number
nobody entered always says the same thing. Maintenance is not a factor either: it
is a refusal, and `PlaceService` makes it before anything is scored.

**A discovered fact never refuses a placement.** Graph health pulls a node down
hard and cannot take it out of the running. A monitoring adapter that breaks at
three in the morning would otherwise empty the candidate set and fail every
provisioning job on the installation. Only an operator's own row — `maintenance`,
`full` — refuses.

**What nothing reported is assumed to be the average of the candidates that
did**, marked `assumed` on the row and on the screen. Both obvious alternatives
send every service to the one machine nobody can see: scoring an unreported factor
as zero makes it the worst node, and taking the mean over only a node's own
factors judges it on how empty it is — which on an unmonitored fresh box is the
best score on the list. A factor *no* candidate answered is dropped entirely, so
an installation with no monitoring scores exactly as it did before any of this
existed.

**The persisted reason is the deliverable, and it is numbers rather than a
sentence.** "Why is this customer on node seven" is asked weeks later, by somebody
who was not there, about a node that has since been rebuilt. `factors` holds each
factor's score, weight, measurement and whether it was assumed; the wording lives
in `lang/`, because a sentence written by whichever queue worker ran the placement
would be in whichever language that worker was running in — the `health_message`
rule, again. It is written for **every** strategy, not only the scored one:
"the group is set to fewest accounts, and this is what the disk was doing at the
time" is the sentence somebody wants six weeks later. Writing it never throws into
the placement, because an audit trail that could not be written must not become an
outage.

**The record is append-only**, like the ledger. A service placed again gets a
second row, and where a service has lived is the useful part.

## 3. What the tests found

**Taking the mean over a node's own factors is the failure it was written to
prevent.** The first version scored each node over whatever it happened to
answer, which reads as obviously fair and is not: an unmonitored node answers only
"how many accounts am I holding", and on a fresh box that is the best score
available. The test called *"it does not treat a node nobody monitors as an empty
one"* failed on the first run and imputation is what fixed it.

**A composite sort key stopped reading half of itself.** The tiebreak was built
as two `key()` calls concatenated, and `key()` ends with the server's id — so
every tie was decided by whichever ULID sorted first and the accounts half was
never read. It sorts, it is deterministic, and it looks correct. The test that
pins it was itself wrong first: it differed the accounts *factor* as well, so it
passed against the broken key. Two nodes of different weights holding
proportionally the same number of accounts is what makes the scores actually tie,
and that version fails against the old key and passes against the new one.

**The whole suite died at its first test and it was a memory limit.** The
architecture tests parse every file under `app/` in one process, and 128M ran out
as the codebase grew. The symptom is not a failing assertion — it is a fatal error
inside php-parser on whichever file happened to be next, which kills the worker
before anything runs. `phpunit.xml` sets `memory_limit` to 1G now; `composer stan`
already carried `--memory-limit=1G` for the same reason.

**`VocabularyTest` caught the new strategy before a screen could print a slug at
an operator.** A `PlacementStrategy` member is a new option on the server-group
form and a new validation case for free, because both are built from `cases()` —
and the guard fails until both languages name it.

## 4. The Infrastructure Center, and what is deliberately not in it

§3 asks for a unified resource view with health, CPU/RAM/storage/I/O, bandwidth,
capacity, maintenance, alerts, incidents, recent changes and customer/revenue
impact. The Explorer's drawer already carried the readings, the containment in
both directions, the impact in `MoneyByCurrency` and the edge history. It now also
carries:

- **the capacity answer for that one resource**, flat lines included — the
  opposite of the Telemetry list, where forty rows saying "not filling" is a
  screen nobody reads to the bottom, and on one resource "this disk has been flat
  for three months" is the answer somebody came for;
- **the operator's own word about the thing** — a server in maintenance has an
  `unknown` health, because nothing is checking a box that was taken out of
  service on purpose, and a drawer showing only the discovered fact reads as a
  monitoring gap rather than as somebody's decision.

Two parts of §3 are **not built rather than pending**. Alerts and incidents are
Phase D, and a panel promising them now would be a panel that is always empty.
Datacenter, cluster, hypervisor, VM, database, cache, load balancer and storage
views need nodes of those kinds to exist; core discovers none of them, and
`ResourceKind` is an open vocabulary the Explorer already lists whatever it is
given — so those views arrive with the adapters in F and G rather than as screens
waiting for data.

## 5. Not in this phase

Everything in C through J. Specifically: no IPAM, no device adapters, no topology
beyond the graph's own containment, no incidents, no alerting, no backup coverage,
no reconciliation. JIT access is C; the vault contract it needs is here.

## 6. Standing limitations

**No real monitoring system has ever answered this code.** `monitoring-prometheus`
is tested against faked HTTP, which proves the request shape, the retries and the
error handling — and not the integration. The placement engine has therefore never
scored a reading a machine actually took, and the forecast has never drawn a line
through a real disk.

**Three screens of this phase have not been driven in a browser**: the Telemetry
capacity panel, the Adapters credential dialog in Turkish, and the placement panel
on the service screen. They are covered by feature tests that render them and
assert their props; the browser pass is what proves what a human reads.

## 7. Gates

`pint --test`, `rector --dry-run`, `phpstan` (1G), `pest` — 1785 passed, 8223
assertions. `eslint`, `vue-tsc`, `vitest` (117), `prettier --check`, `npm run
build`.
